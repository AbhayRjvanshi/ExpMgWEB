#!/usr/bin/env python3
"""
score_commits.py
Analyzes git commits since the project snapshot was captured.
Assigns weight scores based on config.json drift_sensitivity.trigger_2 settings.
Outputs JSON to stdout and updates accumulated_commit_weight in project_snapshot.json.

Usage:
    python .agents/core/validators/score_commits.py [project_root]

Arguments:
    project_root    Optional. Path to project root. Defaults to current directory.

Exit codes:
    0    Success — threshold NOT crossed. No drift action needed.
    1    Error — could not complete analysis (see error field in output).
    2    Success — threshold IS crossed. Trigger 2 fires. Proceed to Trigger 3.

NOTE on directory detection:
    Git does not track empty directories — only files. New directories are
    inferred from the paths of newly added files. This is correct behavior
    for git-based projects: a directory only meaningfully exists when it
    contains files.
"""

import json
import os
import subprocess
import sys
from datetime import datetime, timezone


def load_json(path):
    with open(path, 'r', encoding='utf-8') as f:
        return json.load(f)


def save_json(path, data):
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(data, f, indent=2)


def run_git(args, cwd):
    result = subprocess.run(
        ['git'] + args,
        cwd=cwd,
        capture_output=True,
        text=True
    )
    return result.returncode, result.stdout.strip(), result.stderr.strip()


def get_commits_since(last_analyzed_commit, captured_at, cwd, max_commits):
    """
    Return list of (hash, timestamp) tuples for commits to analyze.
    Prefers last_analyzed_commit hash over timestamp when available —
    hash-based ranges are stable across rebases and timezone differences.
    Falls back to captured_at timestamp if no last_analyzed_commit exists.
    Excludes merge commits. Caps at max_commits with a warning.
    """
    if last_analyzed_commit:
        code, out, err = run_git(
            ['log', f'{last_analyzed_commit}..HEAD',
             '--format=%H %aI', '--no-merges'],
            cwd
        )
    elif captured_at:
        code, out, err = run_git(
            ['log', f'--after={captured_at}',
             '--format=%H %aI', '--no-merges'],
            cwd
        )
    else:
        return [], False

    if code != 0:
        raise RuntimeError(f"git log failed: {err}")
    if not out:
        return [], False

    lines = out.splitlines()
    lines.reverse()  # oldest → newest — git log returns newest first
    truncated = len(lines) > max_commits
    if truncated:
        lines = lines[:max_commits]  # keep oldest N as first batch

    commits = []
    for line in lines:
        parts = line.split(' ', 1)
        commit_hash = parts[0]
        timestamp = parts[1] if len(parts) > 1 else ''
        commits.append((commit_hash, timestamp))

    return commits, truncated


def get_commit_diff(commit_hash, cwd):
    """
    Return list of file changes using numstat.
    --root ensures the initial commit is analyzed correctly.
    Does NOT use --find-renames: without it, renames appear as
    delete + add in both numstat and name-status, keeping paths aligned.
    Each entry: {filepath, additions, deletions}
    """
    code, out, err = run_git(
        ['diff-tree', '--root', '--no-commit-id', '-r', '--numstat',
         commit_hash],
        cwd
    )
    if code != 0:
        raise RuntimeError(
            f"git diff-tree numstat failed for {commit_hash}: {err}"
        )

    changes = []
    for line in out.splitlines():
        parts = line.split('\t')
        if len(parts) != 3:
            continue
        raw_add, raw_del, filepath = parts
        try:
            additions = int(raw_add)
        except ValueError:
            additions = 0  # Binary file
        try:
            deletions = int(raw_del)
        except ValueError:
            deletions = 0
        changes.append({
            'filepath': filepath,
            'additions': additions,
            'deletions': deletions
        })
    return changes


def get_commit_status(commit_hash, cwd):
    """
    Return dict mapping filepath → status letter.
    --root ensures initial commit is analyzed correctly.
    Does NOT use --find-renames: renames appear as A + D,
    matching numstat output for path consistency.
    A=added, D=deleted, M=modified, R=renamed, C=copied.
    """
    code, out, err = run_git(
        ['diff-tree', '--root', '--no-commit-id', '-r', '--name-status',
         commit_hash],
        cwd
    )
    if code != 0:
        raise RuntimeError(
            f"git diff-tree name-status failed for {commit_hash}: {err}"
        )

    status_map = {}
    for line in out.splitlines():
        parts = line.split('\t')
        if len(parts) >= 2:
            status_letter = parts[0][0]
            filepath = parts[-1]
            status_map[filepath] = status_letter
    return status_map


def get_file_extension(filepath):
    """Return lowercase extension with dot. Empty string if none."""
    _, ext = os.path.splitext(filepath)
    return ext.lower() if ext else ''


def get_top_directory(filepath):
    """
    Return the first path component, or empty string if file is at root.
    Used to infer new directory creation from file additions.
    Note: git does not track empty directories — this inference is correct
    for git-based projects since a directory only exists when it has files.
    """
    parts = filepath.replace('\\', '/').split('/')
    return parts[0] if len(parts) > 1 else ''


def score_commit(commit_hash, timestamp, weights, config_files,
                 known_extensions, known_dirs, cwd):
    """
    Score a single commit. Returns (total_weight, reasons_list, had_error).

    known_extensions and known_dirs are mutable sets passed in from main().
    New extensions and directories discovered in this commit are added to
    those sets so they are not double-counted in later commits.

    Line count uses max(additions, deletions) rather than their sum to avoid
    inflating the weight of pure refactors where content is replaced in place.
    """
    config_filenames = {os.path.basename(p) for p in config_files}
    config_paths = set(config_files)

    try:
        changes = get_commit_diff(commit_hash, cwd)
        status_map = get_commit_status(commit_hash, cwd)
    except RuntimeError as e:
        return 0, [{'type': 'analysis_error', 'file': None,
                    'weight': 0, 'detail': str(e)}], True

    total_weight = 0
    reasons = []

    for change in changes:
        filepath = change['filepath']
        additions = change['additions']
        deletions = change['deletions']
        # max() avoids double-counting refactors that replace
        # lines rather than genuinely adding new content
        significant_lines = max(additions, deletions)
        status = status_map.get(filepath, 'M')
        filename = os.path.basename(filepath)
        ext = get_file_extension(filepath)
        top_dir = get_top_directory(filepath)

        # ── Config file changed (weight 5) ─────────────────────────────
        # Highest-priority signal. Checked first.
        # If matched, skip all other checks for this file.
        if filename in config_filenames or filepath in config_paths:
            w = weights['config_file_changed']
            total_weight += w
            reasons.append({
                'type': 'config_file_changed',
                'file': filepath,
                'weight': w,
                'detail': f"Config file changed: {filepath}"
            })
            continue

        # ── New file type — first appearance (weight 5) ─────────────────
        # Triggers only the FIRST time this extension is seen globally
        # across all commits in this run. known_extensions is updated here
        # so subsequent commits with the same extension do not re-trigger.
        if status == 'A' and ext and ext not in known_extensions:
            w = weights['new_file_type_first_appearance']
            total_weight += w
            reasons.append({
                'type': 'new_file_type',
                'file': filepath,
                'weight': w,
                'detail': f"New file type first appearance: {ext}"
            })
            known_extensions.add(ext)

        # ── New file added (weight 3) ────────────────────────────────────
        if status == 'A':
            w = weights['new_file_added']
            total_weight += w
            reasons.append({
                'type': 'new_file_added',
                'file': filepath,
                'weight': w,
                'detail': f"New file added: {filepath}"
            })

            # ── New top-level directory (weight 4) ──────────────────────
            # Inferred from the path of the added file.
            # Scored only the FIRST time this directory is seen globally.
            if top_dir and top_dir not in known_dirs:
                w = weights['new_directory_created']
                total_weight += w
                reasons.append({
                    'type': 'new_directory',
                    'file': filepath,
                    'weight': w,
                    'detail': f"New top-level directory inferred: {top_dir}/"
                })
                known_dirs.add(top_dir)

        # ── File deleted (weight 3) ──────────────────────────────────────
        elif status == 'D':
            w = weights['file_deleted']
            total_weight += w
            reasons.append({
                'type': 'file_deleted',
                'file': filepath,
                'weight': w,
                'detail': f"File deleted: {filepath}"
            })

        # ── File modified — weight by line count ─────────────────────────
        elif status == 'M':
            if significant_lines < 10:
                pass  # Noise — weight 0, not recorded
            elif significant_lines <= 50:
                w = weights['file_modified_10_to_50_lines']
                if w > 0:
                    total_weight += w
                    reasons.append({
                        'type': 'file_modified',
                        'file': filepath,
                        'weight': w,
                        'detail': (
                            f"File modified "
                            f"({significant_lines} significant lines): "
                            f"{filepath}"
                        )
                    })
            else:
                w = weights['file_modified_over_50_lines']
                total_weight += w
                reasons.append({
                    'type': 'file_modified',
                    'file': filepath,
                    'weight': w,
                    'detail': (
                        f"File modified "
                        f"({significant_lines} significant lines): "
                        f"{filepath}"
                    )
                })

    return total_weight, reasons, False


def main():
    project_root = os.path.abspath(
        sys.argv[1] if len(sys.argv) > 1 else '.'
    )

    config_path = os.path.join(
        project_root, '.agents', 'core', 'config.json'
    )
    snapshot_path = os.path.join(
        project_root, '.agents', 'orchestration', 'project_snapshot.json'
    )

    result = {
        'threshold_crossed': False,
        'accumulated_weight': 0,
        'weight_threshold': 50,
        'new_commits_weight': 0,
        'commits_analyzed': [],
        'commits_skipped': 0,
        'commits_errored': [],
        'history_truncated': False,
        'snapshot_captured_at': None,
        'last_analyzed_commit': None,
        'error': None
    }

    # ── Load config ────────────────────────────────────────────────────
    try:
        config = load_json(config_path)
        trigger_2 = config['drift_sensitivity']['trigger_2']
        weights = trigger_2['commit_weights']
        config_files = trigger_2['config_files']
        weight_threshold = trigger_2['weight_threshold']
        max_commits = trigger_2.get('max_commits_per_run', 500)
        result['weight_threshold'] = weight_threshold
    except FileNotFoundError:
        result['error'] = (
            f"config.json not found at {config_path}. "
            "Run from project root or pass project root as argument."
        )
        print(json.dumps(result, indent=2))
        sys.exit(1)
    except KeyError as e:
        result['error'] = (
            f"config.json is missing required key: {e}. "
            "Ensure drift_sensitivity.trigger_2 section exists."
        )
        print(json.dumps(result, indent=2))
        sys.exit(1)

    # ── Load snapshot ──────────────────────────────────────────────────
    try:
        snapshot = load_json(snapshot_path)
        captured_at = snapshot.get('captured_at', '')
        last_analyzed_commit = snapshot.get('last_analyzed_commit', None)
        previous_weight = snapshot.get('accumulated_commit_weight', 0)
        result['snapshot_captured_at'] = captured_at
        result['last_analyzed_commit'] = last_analyzed_commit
    except FileNotFoundError:
        result['error'] = (
            "project_snapshot.json not found. "
            "This file is generated by skill-architect at end of Phase 2. "
            "Run Phase 2 before running drift detection."
        )
        print(json.dumps(result, indent=2))
        sys.exit(1)
    except (json.JSONDecodeError, KeyError) as e:
        result['error'] = f"Cannot read project_snapshot.json: {e}"
        print(json.dumps(result, indent=2))
        sys.exit(1)

    # ── Get commits to analyze ─────────────────────────────────────────
    try:
        commits, truncated = get_commits_since(
            last_analyzed_commit, captured_at, project_root, max_commits
        )
        result['history_truncated'] = truncated
    except RuntimeError as e:
        result['error'] = str(e)
        print(json.dumps(result, indent=2))
        sys.exit(1)

    # No new commits — return previous weight as-is
    if not commits:
        result['accumulated_weight'] = previous_weight
        result['threshold_crossed'] = previous_weight >= weight_threshold
        print(json.dumps(result, indent=2))
        sys.exit(2 if result['threshold_crossed'] else 0)

    # ── Mutable sets for global extension and directory tracking ───────
    # These persist across all commits so each new extension/directory
    # is scored only once regardless of how many commits introduce it.
    known_extensions = set(snapshot.get('file_counts', {}).keys())
    known_dirs = set(snapshot.get('top_directories', []))

    # ── Score each commit ──────────────────────────────────────────────
    new_weight = 0
    commits_analyzed = []
    commits_skipped = 0
    commits_errored = []
    last_good_commit = last_analyzed_commit

    for commit_hash, timestamp in commits:
        weight, reasons, had_error = score_commit(
            commit_hash, timestamp, weights, config_files,
            known_extensions, known_dirs, project_root
        )

        if had_error:
            # Stop immediately on first failure.
            # Do not skip over it — skipping would permanently lose
            # this commit from drift accounting on future runs.
            commits_errored.append({
                'commit_hash': commit_hash[:8],
                'timestamp': timestamp,
                'error': reasons[0]['detail'] if reasons else 'unknown error'
            })
            break

        if weight == 0:
            commits_skipped += 1
        else:
            new_weight += weight
            commits_analyzed.append({
                'commit_hash': commit_hash[:8],
                'timestamp': timestamp,
                'weight': weight,
                'reasons': reasons
            })

        # Only advance last_good_commit on non-errored commits
        last_good_commit = commit_hash

    accumulated_weight = previous_weight + new_weight
    threshold_crossed = accumulated_weight >= weight_threshold

    result.update({
        'threshold_crossed': threshold_crossed,
        'accumulated_weight': accumulated_weight,
        'new_commits_weight': new_weight,
        'commits_analyzed': commits_analyzed,
        'commits_skipped': commits_skipped,
        'commits_errored': commits_errored,
        'last_analyzed_commit': last_good_commit
    })

    # ── Update snapshot ────────────────────────────────────────────────
    # Update snapshot only as far as last_good_commit reached.
    # If no commits succeeded at all, do not touch the snapshot.
    # If some succeeded before a failure, record that progress —
    # next run will resume from last_good_commit forward.
    if last_good_commit:
        snapshot['accumulated_commit_weight'] = accumulated_weight
        snapshot['last_drift_check'] = (
            datetime.now(timezone.utc).isoformat()
        )
        snapshot['drift_check_count'] = (
            snapshot.get('drift_check_count', 0) + 1
        )
        snapshot['last_analyzed_commit'] = last_good_commit
        save_json(snapshot_path, snapshot)
    elif commits_errored:
        # No commits succeeded at all
        result['error'] = (
            "All commits failed analysis. "
            "Snapshot not updated. Check git repository state."
        )
        print(json.dumps(result, indent=2))
        sys.exit(1)

    if commits_errored:
        result['error'] = (
            f"Analysis stopped at commit "
            f"{commits_errored[0]['commit_hash']}: "
            f"{commits_errored[0]['error']}. "
            f"Snapshot advanced to last successful commit. "
            f"Resolve the git issue and re-run."
        )

    print(json.dumps(result, indent=2))
    sys.exit(2 if threshold_crossed else 0)


if __name__ == '__main__':
    main()