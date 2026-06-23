#!/usr/bin/env python3
"""
score_commits.py
Analyzes git commits since the project snapshot was captured.
Assigns weight scores based on config.json drift_sensitivity.trigger_2 settings.
Outputs JSON to stdout and updates accumulated_commit_weight in project_snapshot.json.
"""

import json
import os
import subprocess
import sys
import time
from datetime import datetime, timezone

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from git_helper import GitHelper
from fault_injection import maybe_crash
from journal_helper import write_journal_entry, complete_journal_entry, recover_journal
from snapshot_helper import load_json, save_snapshot_atomic, cleanup_stale_tmp_files

class RunContext:
    def __init__(self):
        self.last_mono_time = None

def save_json(path, data):
    return save_snapshot_atomic(path, data)

def validate_state_transition(from_state, to_state):
    VALID_TRANSITIONS = {
        "synced": ["pending_reconciliation"],
        "pending_reconciliation": ["synced", "recovering", "corrupted"],
        "recovering": ["synced", "pending_reconciliation", "warning", "corrupted"],
        "warning": ["synced", "recovering", "corrupted"],
        "corrupted": ["synced"]
    }
    if to_state not in VALID_TRANSITIONS.get(from_state, []):
        raise ValueError(f"Invalid state transition from {from_state} to {to_state}")

def determine_target_state(snapshot, threshold_crossed, run_context):
    now_wall = time.time()
    now_mono = time.monotonic()
    
    if threshold_crossed:
        reconciliation_elapsed_seconds = snapshot.get('reconciliation_elapsed_seconds', 0.0)
        
        if getattr(run_context, 'last_mono_time', None) is not None:
            delta = now_mono - run_context.last_mono_time
        else:
            last_checkpoint_wall = snapshot.get('last_checkpoint_wall', now_wall)
            delta = max(0.0, now_wall - last_checkpoint_wall)
            
        reconciliation_elapsed_seconds += delta
        snapshot['reconciliation_elapsed_seconds'] = reconciliation_elapsed_seconds
        
        maybe_crash("mid_reconciliation")
        
        if reconciliation_elapsed_seconds < 3600:
            target_state = "pending_reconciliation"
        elif reconciliation_elapsed_seconds < 86400:
            target_state = "recovering"
        elif reconciliation_elapsed_seconds < 7 * 86400:
            target_state = "warning"
        else:
            target_state = "corrupted"
    else:
        snapshot['reconciliation_elapsed_seconds'] = 0.0
        target_state = "synced"

    run_context.last_mono_time = now_mono
    snapshot['last_checkpoint_wall'] = now_wall
    return target_state

def run_git(args, cwd):
    git_helper = GitHelper(cwd)
    res = git_helper.run(args)
    return res["return_code"], res["stdout"], res["stderr"]

def get_commits_since(last_analyzed_commit, captured_at, cwd, max_commits):
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
    lines.reverse()
    truncated = len(lines) > max_commits
    if truncated:
        lines = lines[:max_commits]

    commits = []
    for line in lines:
        parts = line.split(' ', 1)
        commit_hash = parts[0]
        timestamp = parts[1] if len(parts) > 1 else ''
        commits.append((commit_hash, timestamp))

    return commits, truncated

def get_commit_diff(commit_hash, cwd):
    code, out, err = run_git(
        ['diff-tree', '--root', '--no-commit-id', '-r', '--numstat',
         commit_hash],
        cwd
    )
    if code != 0:
        raise RuntimeError(f"git diff-tree numstat failed for {commit_hash}: {err}")

    changes = []
    for line in out.splitlines():
        parts = line.split('\t')
        if len(parts) != 3:
            continue
        raw_add, raw_del, filepath = parts
        try:
            additions = int(raw_add)
        except ValueError:
            additions = 0
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
    code, out, err = run_git(
        ['diff-tree', '--root', '--no-commit-id', '-r', '--name-status',
         commit_hash],
        cwd
    )
    if code != 0:
        raise RuntimeError(f"git diff-tree name-status failed for {commit_hash}: {err}")

    status_map = {}
    for line in out.splitlines():
        parts = line.split('\t')
        if len(parts) >= 2:
            status_letter = parts[0][0]
            filepath = parts[-1]
            status_map[filepath] = status_letter
    return status_map

def get_file_extension(filepath):
    _, ext = os.path.splitext(filepath)
    return ext.lower() if ext else ''

def get_top_directory(filepath):
    parts = filepath.replace('\\', '/').split('/')
    return parts[0] if len(parts) > 1 else ''

def score_commit(commit_hash, timestamp, weights, config_files,
                 known_extensions, known_dirs, cwd):
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
        significant_lines = max(additions, deletions)
        status = status_map.get(filepath, 'M')
        filename = os.path.basename(filepath)
        ext = get_file_extension(filepath)
        top_dir = get_top_directory(filepath)

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

        if status == 'A':
            w = weights['new_file_added']
            total_weight += w
            reasons.append({
                'type': 'new_file_added',
                'file': filepath,
                'weight': w,
                'detail': f"New file added: {filepath}"
            })

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

        elif status == 'D':
            w = weights['file_deleted']
            total_weight += w
            reasons.append({
                'type': 'file_deleted',
                'file': filepath,
                'weight': w,
                'detail': f"File deleted: {filepath}"
            })

        elif status == 'M':
            if significant_lines < 10:
                pass
            elif significant_lines <= 50:
                w = weights['file_modified_10_to_50_lines']
                if w > 0:
                    total_weight += w
                    reasons.append({
                        'type': 'file_modified',
                        'file': filepath,
                        'weight': w,
                        'detail': f"File modified ({significant_lines} significant lines): {filepath}"
                    })
            else:
                w = weights['file_modified_over_50_lines']
                total_weight += w
                reasons.append({
                    'type': 'file_modified',
                    'file': filepath,
                    'weight': w,
                    'detail': f"File modified ({significant_lines} significant lines): {filepath}"
                })

    return total_weight, reasons, False

def main():
    run_context = RunContext()
    project_root = os.path.abspath(
        sys.argv[1] if len(sys.argv) > 1 else '.'
    )

    from lock_helper import OrchestratorLock
    lock = OrchestratorLock(project_root)
    if not lock.acquire():
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
            'error': "Could not acquire orchestrator lock: process contention or starvation."
        }
        print(json.dumps(result, indent=2))
        sys.exit(1)

    try:
        config_path = os.path.join(project_root, '.agents', 'core', 'config.json')
        snapshot_path = os.path.join(project_root, '.agents', 'orchestration', 'project_snapshot.json')
        cleanup_stale_tmp_files(os.path.dirname(snapshot_path), max_age_seconds=60)
        recover_journal(project_root)

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

        try:
            config = load_json(config_path)
            trigger_2 = config['drift_sensitivity']['trigger_2']
            weights = trigger_2['commit_weights']
            config_files = trigger_2['config_files']
            weight_threshold = trigger_2['weight_threshold']
            max_commits = trigger_2.get('max_commits_per_run', 500)
            result['weight_threshold'] = weight_threshold
        except FileNotFoundError:
            result['error'] = f"config.json not found at {config_path}."
            print(json.dumps(result, indent=2))
            sys.exit(1)
        except KeyError as e:
            result['error'] = f"config.json is missing required key: {e}."
            print(json.dumps(result, indent=2))
            sys.exit(1)

        try:
            snapshot = load_json(snapshot_path)
            captured_at = snapshot.get('captured_at', '')
            last_analyzed_commit = snapshot.get('last_analyzed_commit', None)
            previous_weight = snapshot.get('accumulated_commit_weight', 0)
            result['snapshot_captured_at'] = captured_at
            result['last_analyzed_commit'] = last_analyzed_commit
        except FileNotFoundError:
            result['error'] = "project_snapshot.json not found. Run Phase 2 first."
            print(json.dumps(result, indent=2))
            sys.exit(1)
        except (json.JSONDecodeError, KeyError) as e:
            result['error'] = f"Cannot read project_snapshot.json: {e}"
            print(json.dumps(result, indent=2))
            sys.exit(1)

        try:
            commits, truncated = get_commits_since(
                last_analyzed_commit, captured_at, project_root, max_commits
            )
            result['history_truncated'] = truncated
        except RuntimeError as e:
            result['error'] = str(e)
            print(json.dumps(result, indent=2))
            sys.exit(1)

        if not commits:
            result['accumulated_weight'] = previous_weight
            result['threshold_crossed'] = previous_weight >= weight_threshold
            print(json.dumps(result, indent=2))
            sys.exit(2 if result['threshold_crossed'] else 0)

        known_extensions = set(snapshot.get('file_counts', {}).keys())
        known_dirs = set(snapshot.get('top_directories', []))

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

        if last_good_commit:
            cleanup_stale_tmp_files(os.path.dirname(snapshot_path), max_age_seconds=60)
            
            git_helper = GitHelper(project_root)
            tracked_branch = git_helper.get_tracked_branch()
            merge_base_commit = git_helper.get_merge_base('origin/' + tracked_branch, 'HEAD') if tracked_branch != "DETACHED" else None
            
            old_state = snapshot.get('cursor_state', 'synced')
            target_state = determine_target_state(snapshot, threshold_crossed, run_context)

            if old_state != target_state:
                validate_state_transition(old_state, target_state)
                snapshot['cursor_state'] = target_state

            if target_state in ("pending_reconciliation", "recovering", "warning", "corrupted"):
                snapshot['pending_last_analyzed_commit'] = last_good_commit
                snapshot['pending_tracked_branch'] = tracked_branch
                snapshot['pending_merge_base_commit'] = merge_base_commit
            else:
                snapshot['last_analyzed_commit'] = last_good_commit
                snapshot['tracked_branch'] = tracked_branch
                snapshot['merge_base_commit'] = merge_base_commit
                snapshot['pending_last_analyzed_commit'] = None
                snapshot['pending_tracked_branch'] = None
                snapshot['pending_merge_base_commit'] = None
                snapshot['reconciliation_started_at'] = None

            snapshot['accumulated_commit_weight'] = accumulated_weight
            snapshot['last_drift_check'] = datetime.now(timezone.utc).isoformat()
            snapshot['drift_check_count'] = snapshot.get('drift_check_count', 0) + 1
            
            run_id = os.environ.get("ORCHESTRATION_RUN_ID", f"run_{int(time.time())}")
            txn_id = os.environ.get("ORCHESTRATION_TXN_ID", f"txn_{run_id}_{int(time.time())}")
            snapshot['last_reconciliation_txn'] = txn_id
            
            expected_outcome = {
                "last_analyzed_commit": last_good_commit,
                "cursor_state": target_state,
                "accumulated_commit_weight": accumulated_weight
            }
            write_journal_entry(project_root, txn_id, "cursor_commit", expected_outcome)
            maybe_crash("before_cursor_commit")
            
            success = save_json(snapshot_path, snapshot)
            if not success:
                # If disk write fails, fail loop immediately
                result['error'] = "Failed to write project snapshot to disk."
                print(json.dumps(result, indent=2))
                sys.exit(1)
                
            complete_journal_entry(project_root, txn_id)
            
            validate_json_script = os.path.join(project_root, '.agents', 'core', 'validators', 'validate_json.py')
            snapshot_schema = os.path.join(project_root, '.agents', 'core', 'contracts', 'project_snapshot.schema.json')
            if os.path.isfile(validate_json_script) and os.path.isfile(snapshot_schema):
                proc = subprocess.run([sys.executable, validate_json_script, snapshot_path, snapshot_schema],
                                       capture_output=True, text=True, timeout=15)
                if proc.returncode != 0:
                    result['error'] = f"Post-write snapshot schema validation failed: {proc.stdout.strip() or proc.stderr.strip()}"
                    print(json.dumps(result, indent=2))
                    sys.exit(1)
        elif commits_errored:
            result['error'] = "All commits failed analysis. Snapshot not updated."
            print(json.dumps(result, indent=2))
            sys.exit(1)

        if commits_errored:
            result['error'] = (
                f"Analysis stopped at commit {commits_errored[0]['commit_hash']}: "
                f"{commits_errored[0]['error']}. Snapshot advanced to last successful commit."
            )

        print(json.dumps(result, indent=2))
        sys.exit(2 if threshold_crossed else 0)
    except Exception as e:
        sys.stderr.write(f"Error: score_commits.py failed with exception: {e}\n")
        sys.exit(1)
    finally:
        lock.release()

if __name__ == '__main__':
    main()