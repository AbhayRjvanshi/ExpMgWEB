#!/usr/bin/env python3
"""
detect_drift.py (v1.0)
Orchestrates the three-trigger drift detection cascade.
"""

import json
import os
import re
import subprocess
import sys
import hashlib
import time
from datetime import datetime, timezone

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from git_helper import GitHelper
from fault_injection import maybe_crash
from snapshot_helper import load_json, save_snapshot_atomic

def get_file_hash(filepath):
    try:
        before = os.stat(filepath)
    except OSError:
        return ""
    
    hasher = hashlib.sha256()
    try:
        with open(filepath, 'rb') as f:
            for chunk in iter(lambda: f.read(65536), b''):
                hasher.update(chunk)
        file_hash = hasher.hexdigest()
    except OSError:
        return ""
        
    try:
        after = os.stat(filepath)
        if before.st_mtime != after.st_mtime or before.st_size != after.st_size:
            hasher = hashlib.sha256()
            with open(filepath, 'rb') as f:
                for chunk in iter(lambda: f.read(65536), b''):
                    hasher.update(chunk)
            file_hash = hasher.hexdigest()
    except OSError:
        pass
    return file_hash

def run_checksum_drift(project_root, snapshot, skip_dirs, drift_extensions):
    scan_started_at = time.time()
    concurrency_warning = None
    
    cache_path = os.path.join(project_root, '.agents', 'orchestration', 'checksum_cache.json')
    cache = {}
    cache_loaded_successfully = False
    if os.path.isfile(cache_path):
        try:
            with open(cache_path, 'r', encoding='utf-8') as f:
                loaded = json.load(f)
                if isinstance(loaded, dict):
                    valid = True
                    for k, v in loaded.items():
                        if not (isinstance(v, dict) and 
                                isinstance(v.get('mtime'), (int, float)) and 
                                isinstance(v.get('size'), int) and 
                                isinstance(v.get('hash'), str)):
                            valid = False
                            break
                    if valid:
                        cache = loaded
                        cache_loaded_successfully = True
        except Exception as e:
            sys.stderr.write(f"Warning: Checksum cache read failed or corrupt: {e}. Rebuilding...\n")

    # Ephemeral rebuild storm marker file persistence to prevent crash loops (ISSUE 3)
    if not cache_loaded_successfully:
        rebuild_marker_path = os.path.join(project_root, '.agents', 'orchestration', 'rebuild_storm.lock')
        last_rebuild = 0.0
        if os.path.exists(rebuild_marker_path):
            try:
                with open(rebuild_marker_path, 'r', encoding='utf-8') as f:
                    last_rebuild = float(f.read().strip())
            except Exception:
                pass
        
        if scan_started_at - last_rebuild < 30.0:
            raise RuntimeError("Cache rebuild storm detected: rebuild requested too frequently.")
            
        try:
            with open(rebuild_marker_path, 'w', encoding='utf-8') as f:
                f.write(str(scan_started_at))
                f.flush()
        except OSError as e:
            sys.stderr.write(f"Warning: Failed to save cache rebuild marker to disk: {e}\n")

    current_metadata = {}
    changed_files = []
    
    for dirpath, dirnames, filenames in os.walk(project_root):
        # Point 10: Do NOT generic-skip dot-prefixed folders. Strictly rely on skip_dirs list.
        dirnames[:] = [d for d in dirnames if d not in skip_dirs]
        for filename in filenames:
            ext = os.path.splitext(filename)[1].lower()
            if ext not in drift_extensions:
                continue
            
            filepath = os.path.join(dirpath, filename)
            rel_path = os.path.relpath(filepath, project_root).replace('\\', '/')
            
            try:
                stat = os.stat(filepath)
                mtime = stat.st_mtime
                size = stat.st_size
                inode = getattr(stat, 'st_ino', None)
                
                if mtime > scan_started_at:
                    concurrency_warning = "Files modified concurrently during snapshot scan walk."
            except OSError:
                continue

            maybe_crash("during_checksum_walk")

            cached_item = cache.get(rel_path, {})
            if (cached_item.get('mtime') == mtime and 
                cached_item.get('size') == size and 
                (inode is None or cached_item.get('inode') == inode)):
                file_hash = cached_item['hash']
            else:
                file_hash = get_file_hash(filepath)
                if not file_hash:
                    continue
                if cached_item.get('hash') != file_hash:
                    changed_files.append(rel_path)

            current_metadata[rel_path] = {
                'mtime': mtime,
                'size': size,
                'inode': inode,
                'hash': file_hash
            }

    tmp_cache_path = cache_path + '.tmp'
    try:
        with open(tmp_cache_path, 'w', encoding='utf-8') as f:
            json.dump(current_metadata, f, indent=2)
            f.flush()
            # No fsync needed on cache updates (Point 12)
        maybe_crash("before_cache_write")
        os.replace(tmp_cache_path, cache_path)
        maybe_crash("after_cache_write")
    except Exception as e:
        sys.stderr.write(f"Warning: Failed to save checksum cache to disk: {e}\n")

    previous_files = set(cache.keys())
    current_files = set(current_metadata.keys())
    deleted_files = sorted(list(previous_files - current_files))
    
    drift_metadata = {
        "drift_source": "checksum",
        "drift_confidence": "reduced",
        "consistency_mode": "best_effort",
        "checksum_snapshot_version": "1.0.0",
        "concurrency_warning": concurrency_warning,
        "deleted_files": deleted_files,
        "changed_files": changed_files
    }
    return drift_metadata

SKIP_DIRS = frozenset([
    '.git', '.agents', 'vendor', 'node_modules', '.svn', '.hg',
    'dist', 'build', '__pycache__', '.next', '.nuxt', 'coverage',
    '.idea', '.vscode', 'graphify-out', 'logs', 'data'
])

_DRIFT_EXTENSIONS_FALLBACK = frozenset([
    '.php', '.js', '.mjs', '.ts', '.tsx', '.jsx', '.py', '.rb', '.go',
    '.java', '.cs', '.cpp', '.c', '.vue', '.html', '.htm', '.sql', '.sh',
    '.css', '.scss', '.sass', '.less', '.rs', '.kt', '.swift', '.dart'
])

def load_drift_extensions(config):
    exts = config.get('drift_sensitivity', {}).get('drift_extensions')
    if exts and isinstance(exts, list):
        return frozenset(e.strip().lower() for e in exts if isinstance(e, str))
    return _DRIFT_EXTENSIONS_FALLBACK

PAGE_DIR_NAMES = frozenset(['pages', 'views', 'templates', 'screens'])

DEFAULT_DOMAIN_NAMES = frozenset([
    'database', 'authentication', 'file-management', 'email', 'payment',
    'api', 'testing', 'caching', 'logging', 'deployment', 'storage',
    'messaging', 'search', 'media', 'finance', 'settlement', 'async',
    'notifications'
])

DEFAULT_RELATED_DOMAINS = {
    'api': ['authentication', 'caching', 'messaging', 'logging'],
    'database': ['caching'],
    'messaging': ['caching', 'async', 'notifications'],
    'async': ['messaging', 'caching'],
    'authentication': ['api'],
    'payment': ['finance', 'api'],
    'notifications': ['messaging'],
    'deployment': ['logging']
}

GIT_TIMEOUT = 30

INTERPRETATIONS = {
    'new_directory': (
        "The new '{0}/' directory suggests a new architectural layer or domain not present in the original skill snapshot.",
        "A new top-level directory '{0}/' appeared - is this a permanent new layer of the project or a temporary folder?"
    ),
    'new_extension': (
        "Files of type '{0}' appeared for the first time ({1} files), suggesting a new technology or tooling entered the project.",
        "A new file type '{0}' appeared in {1} files - does this represent a permanent addition to the stack?"
    ),
    'stack_file': (
        "Stack-defining file '{0}' changed - dependencies or environment configuration may have shifted.",
        "'{0}' changed since the snapshot - did the dependency stack or environment configuration change in a way that affects skills?"
    ),
    'file_growth': (
        "Code file count grew {0}% since the snapshot, indicating substantial project expansion.",
        "The project's code file count grew {0}% - is this organic growth, or was a new subsystem added?"
    ),
}

def save_json(path, data):
    return save_snapshot_atomic(path, data)

def fail(message):
    print(json.dumps({'error': message}))
    sys.exit(1)

def parse_args(argv):
    project_root = '.'
    mode = 'phase-entry'
    args = argv[1:]
    i = 0
    while i < len(args):
        arg = args[i]
        if arg == '--mode':
            if i + 1 >= len(args):
                return None, None
            mode = args[i + 1]
            i += 2
        elif arg.startswith('--mode='):
            mode = arg.split('=', 1)[1]
            i += 1
        elif not arg.startswith('-'):
            project_root = arg
            i += 1
        else:
            return None, None
    if mode not in ('phase-entry', 'manual'):
        return None, None
    return os.path.abspath(project_root), mode

def scan_current_state(project_root, drift_extensions):
    ext_counts = {}
    top_dirs = set()
    total = 0
    for dirpath, dirnames, filenames in os.walk(project_root):
        # Point 10: Rely strictly on SKIP_DIRS list
        dirnames[:] = [d for d in dirnames if d not in SKIP_DIRS]
        if os.path.abspath(dirpath) == project_root:
            top_dirs.update(dirnames)
        for filename in filenames:
            ext = os.path.splitext(filename)[1].lower()
            if ext not in drift_extensions:
                continue
            ext_counts[ext] = ext_counts.get(ext, 0) + 1
            total += 1
    return ext_counts, sorted(top_dirs), total

def _run_git(args, cwd):
    return subprocess.run(['git'] + args, cwd=cwd, capture_output=True, text=True, timeout=GIT_TIMEOUT)

def stack_files_changed_since(project_root, snapshot, stack_files):
    if not stack_files:
        return []

    changed_paths = None
    last_commit = snapshot.get('last_analyzed_commit')

    try:
        if last_commit:
            proc = _run_git(['diff', '--name-only', f'{last_commit}..HEAD'], project_root)
            if proc.returncode == 0:
                changed_paths = {line.strip() for line in proc.stdout.splitlines() if line.strip()}
        if changed_paths is None:
            captured_at = snapshot.get('captured_at')
            if not captured_at:
                return []
            proc = _run_git(['log', f'--since={captured_at}', '--name-only', '--format=', '--no-merges'], project_root)
            if proc.returncode != 0:
                return []
            changed_paths = {line.strip() for line in proc.stdout.splitlines() if line.strip()}
    except (subprocess.TimeoutExpired, FileNotFoundError):
        return []

    changed = []
    for stack_file in stack_files:
        for path in changed_paths:
            if path == stack_file or path.endswith('/' + stack_file):
                changed.append(stack_file)
                break
    return sorted(changed)

def run_trigger_1(project_root, snapshot, t1_config, drift_extensions):
    ext_counts, top_dirs, total = scan_current_state(project_root, drift_extensions)

    snap_exts = set(snapshot.get('file_counts', {}).keys())
    new_exts = sorted(e for e in ext_counts if e not in snap_exts)
    ext_threshold = t1_config.get('new_file_extension_threshold', 1)
    exts_fired = len(new_exts) >= ext_threshold

    snap_dirs = set(snapshot.get('top_directories', []))
    new_dirs = sorted(d for d in top_dirs if d not in snap_dirs)
    if not t1_config.get('new_top_level_directory_triggers', True):
        new_dirs = []

    stack_changed = stack_files_changed_since(project_root, snapshot, t1_config.get('stack_file_changes_trigger', []))

    snap_total = snapshot.get('total_file_count', 0)
    growth = (round((total - snap_total) / snap_total * 100, 1) if snap_total > 0 else None)
    growth_threshold = t1_config.get('file_count_growth_threshold_percent', 40)
    growth_fired = growth is not None and growth > growth_threshold

    signals = []
    if exts_fired:
        for ext in new_exts:
            signals.append(f"New file extension '{ext}' appeared in {ext_counts[ext]} files")
    for d in new_dirs:
        signals.append(f"New top-level directory '{d}/' detected")
    for f in stack_changed:
        signals.append(f"Stack-defining file '{f}' changed")
    if growth_fired:
        signals.append(f"Code file count grew {growth}% (threshold {growth_threshold}%)")

    signal_keys = []
    if exts_fired:
        signal_keys.extend(f'new_file_extension:{e}' for e in new_exts)
    signal_keys.extend(f'new_top_level_directory:{d}' for d in new_dirs)
    signal_keys.extend(f'stack_file_changed:{f}' for f in stack_changed)
    if growth_fired:
        signal_keys.append('file_count_growth')

    findings = {
        'ran': True,
        'triggered': bool(signals),
        'new_file_extensions': new_exts if exts_fired else [],
        'new_top_level_directories': new_dirs,
        'stack_files_changed': stack_changed,
        'file_count_growth_percent': growth,
        'signals_fired': signals
    }
    fired = {
        'new_exts': new_exts if exts_fired else [],
        'new_dirs': new_dirs,
        'stack_changed': stack_changed,
        'growth_fired': growth_fired,
        'growth': growth,
        'signal_keys': signal_keys
    }
    return findings, fired, ext_counts

def _parse_iso(value):
    if not isinstance(value, str) or not value.strip():
        return None
    try:
        parsed = datetime.fromisoformat(value.replace('Z', '+00:00'))
    except ValueError:
        return None
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed

def load_acknowledged_exceptions(reports_dir, snapshot_captured_at, expiry_days):
    exceptions = {}
    if not os.path.isdir(reports_dir):
        return exceptions
    snapshot_time = _parse_iso(snapshot_captured_at)
    now = datetime.now(timezone.utc)
    for filename in sorted(os.listdir(reports_dir)):
        if not filename.endswith('.json'):
            continue
        try:
            r = load_json(os.path.join(reports_dir, filename))
        except (json.JSONDecodeError, OSError):
            continue
        if r.get('status') == 'pending_user_response':
            continue
        generated = _parse_iso(r.get('generated_at'))
        if generated is None:
            continue
        if snapshot_time is not None and generated < snapshot_time:
            continue
        if expiry_days > 0 and (now - generated).days > expiry_days:
            continue
        for signal in r.get('user_exceptions', []):
            if isinstance(signal, str):
                exceptions[signal] = {
                    'report_id': r.get('report_id', filename[:-5]),
                    'acknowledged_at': r.get('generated_at')
                }
    return exceptions

def apply_exceptions(fired, exceptions):
    def keep(key):
        return key not in exceptions

    eff = {
        'new_exts': [e for e in fired['new_exts'] if keep(f'new_file_extension:{e}')],
        'new_dirs': [d for d in fired['new_dirs'] if keep(f'new_top_level_directory:{d}')],
        'stack_changed': [f for f in fired['stack_changed'] if keep(f'stack_file_changed:{f}')],
        'growth_fired': (fired['growth_fired'] and keep('file_count_growth')),
        'growth': fired['growth'],
        'signal_keys': [k for k in fired['signal_keys'] if keep(k)]
    }

    acknowledged = []
    for key in fired['signal_keys']:
        if key in exceptions:
            meta = exceptions[key]
            acknowledged.append({
                'signal': key,
                'acknowledged_in': meta.get('report_id'),
                'acknowledged_at': meta.get('acknowledged_at'),
                'note': 'Previously marked as a non-significant exception.'
            })
    return eff, acknowledged

def severity_for(weight, threshold, severity_cfg):
    if threshold <= 0 or weight < threshold:
        return None
    ratio = weight / threshold
    if ratio >= severity_cfg.get('critical_min', 4.0):
        return 'CRITICAL_DRIFT'
    if ratio >= severity_cfg.get('high_min', 2.5):
        return 'HIGH_DRIFT'
    if ratio >= severity_cfg.get('moderate_min', 1.5):
        return 'MODERATE_DRIFT'
    return 'LOW_DRIFT'

def run_trigger_2(project_root, weight_threshold, severity_cfg):
    script = os.path.join(project_root, '.agents', 'core', 'validators', 'score_commits.py')
    if not os.path.isfile(script):
        return None, f'score_commits.py not found at {script}'

    try:
        env = dict(os.environ)
        env["NESTED_ORCHESTRATION"] = "1"
        proc = subprocess.run([sys.executable, script, project_root], env=env, capture_output=True, text=True, timeout=120)
    except subprocess.TimeoutExpired:
        return None, 'score_commits.py timed out after 120s'

    if proc.returncode == 1:
        snippet = (proc.stdout or proc.stderr or '').strip()[:500]
        return None, f'score_commits.py reported error: {snippet}'

    try:
        data = json.loads(proc.stdout)
    except (json.JSONDecodeError, TypeError) as e:
        snippet = (proc.stdout or '').strip()[:500]
        return None, f'Cannot parse score_commits.py output: {e}. Raw: {snippet}'

    commits = []
    for c in data.get('commits_analyzed', []):
        reasons = c.get('reasons', [])
        reason_parts = []
        for r in reasons:
            if isinstance(r, dict):
                reason_parts.append(r.get('detail') or r.get('reason') or str(r))
            else:
                reason_parts.append(str(r))
        commits.append({
            'commit_hash': c.get('commit_hash', '?'),
            'weight': c.get('weight', 0),
            'reason': '; '.join(reason_parts) or 'weight contribution'
        })

    accumulated = data.get('accumulated_weight', 0)
    findings = {
        'ran': True,
        'triggered': proc.returncode == 2,
        'severity': severity_for(accumulated, weight_threshold, severity_cfg),
        'accumulated_weight': accumulated,
        'weight_threshold': weight_threshold,
        'commits_analyzed': commits
    }
    return findings, None

def load_domain_keywords(project_root):
    path = os.path.join(project_root, '.agents', 'core', 'prominence-profiles', 'domain_keywords.json')
    if not os.path.isfile(path):
        return {}
    try:
        return load_json(path).get('domains', {})
    except (json.JSONDecodeError, OSError):
        return {}

def load_domain_relationships(project_root):
    path = os.path.join(project_root, '.agents', 'core', 'prominence-profiles', 'domain_relationships.json')
    if not os.path.isfile(path):
        return DEFAULT_RELATED_DOMAINS
    try:
        data = load_json(path)
        relationships = data.get('relationships', {})
        if isinstance(relationships, dict) and relationships:
            return relationships
    except (json.JSONDecodeError, OSError):
        pass
    return DEFAULT_RELATED_DOMAINS

def domains_for_signals(fired, domain_keywords_map, related_domains):
    tokens = {d.lower() for d in fired['new_dirs']}
    hit = set()
    for token in tokens:
        if token in DEFAULT_DOMAIN_NAMES:
            hit.add(token)
    for domain, keywords in domain_keywords_map.items():
        keyword_set = {k.lower() for k in keywords}
        for token in tokens:
            if token == domain or token in keyword_set:
                hit.add(domain)
    expanded = set(hit)
    for domain in hit:
        expanded.update(related_domains.get(domain, []))
    return expanded

def match_affected(justifications, fired, implicated_domains):
    affected = []
    new_exts = set(fired['new_exts'])
    new_dirs = {d.lower() for d in fired['new_dirs']}
    for name, justification in justifications.items():
        for tag in justification.get('discovery_evidence', []):
            category, _, value = tag.partition(':')
            category = category.strip().lower()
            value = value.strip().lower()
            if category == 'file_type' and value in new_exts:
                affected.append(name)
                break
            if value and value in new_dirs:
                affected.append(name)
                break
            if category == 'domain' and value in implicated_domains:
                affected.append(name)
                break
    return sorted(set(affected))

def build_assessment(fired, t2, snapshot, implicated_domains, excluded_count):
    suspected = []
    for d in fired['new_dirs']:
        suspected.append(INTERPRETATIONS['new_directory'][0].format(d))
    for ext in fired['new_exts']:
        suspected.append(INTERPRETATIONS['new_extension'][0].format(ext, '?'))
    for f in fired['stack_changed']:
        suspected.append(INTERPRETATIONS['stack_file'][0].format(f))
    if fired['growth_fired']:
        suspected.append(INTERPRETATIONS['file_growth'][0].format(fired['growth']))

    severity_note = f' Severity: {t2["severity"]}.' if t2.get('severity') else ''
    excluded_note = f' {excluded_count} previously acknowledged signal(s) were excluded.' if excluded_count else ''

    signal_count = len(fired['new_dirs']) + len(fired['new_exts']) + len(fired['stack_changed']) + (1 if fired['growth_fired'] else 0)
    summary = (
        f'Trigger 1 fired {signal_count} signal(s) and accumulated commit weight reached '
        f'{t2["accumulated_weight"]} (threshold {t2["weight_threshold"]}).{severity_note} '
        f'The project appears to have structurally changed since the snapshot.{excluded_note}'
    )
    if not suspected:
        summary = (
            f'Commit weight reached {t2["accumulated_weight"]} (threshold {t2["weight_threshold"]}) without surface structure changes.{severity_note}{excluded_note}'
        )
        suspected.append('Sustained heavy modification of existing files; scope may have shifted.')

    return {
        'summary': summary,
        'suspected_changes': suspected,
        'skills_possibly_affected': match_affected(snapshot.get('skill_justifications', {}), fired, implicated_domains),
        'mcps_possibly_affected': match_affected(snapshot.get('mcp_justifications', {}), fired, implicated_domains)
    }

def build_questions(fired, ext_counts):
    questions = []
    for d in fired['new_dirs']:
        questions.append({
            'signal': f'new_top_level_directory:{d}',
            'question': INTERPRETATIONS['new_directory'][1].format(d),
            'user_answer': None,
            'answered': False
        })
    for ext in fired['new_exts']:
        questions.append({
            'signal': f'new_file_extension:{ext}',
            'question': INTERPRETATIONS['new_extension'][1].format(ext, ext_counts.get(ext, 0)),
            'user_answer': None,
            'answered': False
        })
    for f in fired['stack_changed']:
        questions.append({
            'signal': f'stack_file_changed:{f}',
            'question': INTERPRETATIONS['stack_file'][1].format(f),
            'user_answer': None,
            'answered': False
        })
    if fired['growth_fired']:
        questions.append({
            'signal': 'file_count_growth',
            'question': INTERPRETATIONS['file_growth'][1].format(fired['growth']),
            'user_answer': None,
            'answered': False
        })
    if not questions:
        questions.append({
            'signal': 'commit_weight_threshold',
            'question': 'Commit weight crossed the drift threshold. Has the project direction changed?',
            'user_answer': None,
            'answered': False
        })
    return questions

def design_tokens_stale(project_root, max_age_days):
    path = os.path.join(project_root, '.agents', 'orchestration', 'design_tokens.json')
    if not os.path.isfile(path):
        return False
    try:
        age_days = (datetime.now(timezone.utc) - datetime.fromtimestamp(os.path.getmtime(path), tz=timezone.utc)).days
    except OSError:
        return False
    return age_days > max_age_days

def build_rerun_recommendations(fired, snapshot, ext_counts, phase_rerun_cfg, project_root, severity):
    structural = bool(fired['new_exts']) or bool(fired['new_dirs']) or bool(fired['stack_changed']) or fired['growth_fired']
    severe = severity in ('HIGH_DRIFT', 'CRITICAL_DRIFT')

    stale_skills = sorted(
        name for name, j in snapshot.get('skill_justifications', {}).items()
        if j.get('prominence_verdict') in ('LOW', 'MINIMAL')
    )

    phase_1 = structural or severe
    if structural:
        reasons_list = []
        if fired['new_exts']: reasons_list.append(f"new file types {fired['new_exts']}")
        if fired['new_dirs']: reasons_list.append(f"new directories {fired['new_dirs']}")
        if fired['stack_changed']: reasons_list.append(f"stack files changed {fired['stack_changed']}")
        if fired['growth_fired']: reasons_list.append(f"code file growth {fired['growth']}%")
        phase_1_reason = 'Structural drift detected: ' + '; '.join(reasons_list) + (f' (severity: {severity})' if severity else '')
    elif severe:
        phase_1_reason = f'No structural signals, but sustained modification reached {severity} - re-discovery recommended.'
    else:
        phase_1_reason = 'No major surface changes; current specifications hold.'

    phase_2 = phase_1 or bool(stale_skills)
    phase_2_reason = 'Phase 1 output will change, Phase 2 must be rerun.' if phase_1 else (f"LOW/MINIMAL prominence skills need review: {', '.join(stale_skills)}" if stale_skills else 'Generated skills match target state.')

    css_now = ext_counts.get('.css', 0)
    css_snap = snapshot.get('file_counts', {}).get('.css', 0)
    css_threshold = phase_rerun_cfg.get('frontend_css_growth_threshold_percent', 50)
    css_fired = (css_snap > 0 and (css_now - css_snap) / css_snap * 100 > css_threshold)
    new_page_dirs = [d for d in fired['new_dirs'] if d.lower() in PAGE_DIR_NAMES]
    tokens_old = design_tokens_stale(project_root, phase_rerun_cfg.get('design_tokens_max_age_days', 90))

    phase_3 = css_fired or bool(new_page_dirs) or tokens_old
    phase_3_reasons = []
    if css_fired: phase_3_reasons.append(f"CSS file count grew ({css_snap} -> {css_now})")
    if new_page_dirs: phase_3_reasons.append(f"New page directories: {', '.join(new_page_dirs)}")
    if tokens_old: phase_3_reasons.append('design_tokens.json is significantly old')
    phase_3_reason = '; '.join(phase_3_reasons) if phase_3 else 'No significant frontend changes.'

    phase_4 = phase_2
    phase_4_reason = 'Skills will be updated - re-read before Phase 4 coding.' if phase_4 else 'No generated skill changes pending.'

    return {
        'phase_1': {'recommended': phase_1, 'reason': phase_1_reason},
        'phase_2': {'recommended': phase_2, 'reason': phase_2_reason},
        'phase_3': {'recommended': phase_3, 'reason': phase_3_reason},
        'phase_4': {'recommended': phase_4, 'reason': phase_4_reason}
    }

def next_report_id(reports_dir, now):
    date_part = now.strftime('%Y%m%d')
    pattern = re.compile(r'^drift_{0}_(\d{{3}})\.json$'.format(date_part))
    max_seq = 0
    if os.path.isdir(reports_dir):
        for filename in os.listdir(reports_dir):
            m = pattern.match(filename)
            if m:
                max_seq = max(max_seq, int(m.group(1)))
    return f'drift_{date_part}_{max_seq + 1:03d}'

def try_validate(project_root, report_path):
    validator = os.path.join(project_root, '.agents', 'core', 'validators', 'validate_json.py')
    schema = os.path.join(project_root, '.agents', 'core', 'contracts', 'drift_report.schema.json')
    if not (os.path.isfile(validator) and os.path.isfile(schema)):
        return 'skipped'
    try:
        proc = subprocess.run([sys.executable, validator, report_path, schema], capture_output=True, text=True, timeout=30)
    except (subprocess.TimeoutExpired, OSError):
        return 'skipped'
    if proc.returncode == 0:
        return 'passed'
    return f"failed: {(proc.stdout or proc.stderr or '').strip()[:300]}"

def main():
    project_root, mode = parse_args(sys.argv)
    if project_root is None:
        fail('Usage: detect_drift.py [project_root] [--mode phase-entry|manual]')

    from lock_helper import OrchestratorLock
    lock = OrchestratorLock(project_root)
    if not lock.acquire():
        fail("Could not acquire orchestrator lock: process contention or starvation.")

    try:
        config_path = os.path.join(project_root, '.agents', 'core', 'config.json')
        snapshot_path = os.path.join(project_root, '.agents', 'orchestration', 'project_snapshot.json')
        reports_dir = os.path.join(project_root, '.agents', 'orchestration', 'drift_reports')
        latest_path = os.path.join(project_root, '.agents', 'orchestration', 'drift_report.json')

        try:
            config = load_json(config_path)
        except FileNotFoundError:
            fail(f'config.json not found at {config_path}')
        except json.JSONDecodeError as e:
            fail(f'Cannot parse config.json: {e}')

        drift_cfg = config.get('drift_sensitivity', {})
        t1_cfg = drift_cfg.get('trigger_1', {})
        t2_cfg = drift_cfg.get('trigger_2', {})
        weight_threshold = t2_cfg.get('weight_threshold', 50)
        severity_cfg = t2_cfg.get('severity_multipliers', {})
        page_rerun_cfg = drift_cfg.get('phase_rerun', {})
        drift_extensions = load_drift_extensions(config)

        try:
            snapshot = load_json(snapshot_path)
        except FileNotFoundError:
            fail('project_snapshot.json not found. Run Phase 2 first.')
        except json.JSONDecodeError as e:
            fail(f'Cannot parse project_snapshot.json: {e}')

        git_helper = GitHelper(project_root)
        git_status = git_helper.get_status()
        
        if git_status["status"] == "error":
            try:
                ext_counts, top_dirs, total = scan_current_state(project_root, drift_extensions)
                # Persist rebuild timestamp before start via ephemeral marker (ISSUE 3)
                drift_metadata = run_checksum_drift(project_root, snapshot, SKIP_DIRS, drift_extensions)
                has_drift = bool(drift_metadata["changed_files"] or drift_metadata["deleted_files"])
                
                t1 = {
                    'ran': True,
                    'triggered': has_drift,
                    'new_file_extensions': [],
                    'new_top_level_directories': [],
                    'stack_files_changed': [],
                    'file_count_growth_percent': 0.0,
                    'signals_fired': [f"Checksum drift: {f}" for f in drift_metadata["changed_files"] + drift_metadata["deleted_files"]]
                }
                fired = {
                    'new_exts': [],
                    'new_dirs': [],
                    'stack_changed': [],
                    'growth_fired': False,
                    'growth': 0.0,
                    'signal_keys': [f"checksum_drift:{f}" for f in drift_metadata["changed_files"] + drift_metadata["deleted_files"]]
                }
                t2 = {
                    'ran': True,
                    'triggered': has_drift,
                    'severity': 'MODERATE_DRIFT' if has_drift else None,
                    'accumulated_weight': 100 if has_drift else 0,
                    'weight_threshold': weight_threshold,
                    'commits_analyzed': []
                }
                save_json(snapshot_path, snapshot)
            except Exception as e:
                fail(f'Checksum drift scan failed: {e}')
        else:
            t1, fired, ext_counts = run_trigger_1(project_root, snapshot, t1_cfg, drift_extensions)
            t2, t2_error = run_trigger_2(project_root, weight_threshold, severity_cfg)
            if t2_error:
                fail(f'Trigger 2 failed: {t2_error}')

        expiry_days = t1_cfg.get('exception_expiry_days', 30)
        exceptions = load_acknowledged_exceptions(reports_dir, snapshot.get('captured_at'), expiry_days)
        eff_fired, acknowledged = apply_exceptions(fired, exceptions)
        excluded_count = len(acknowledged)
        structural_escalation = bool(eff_fired['signal_keys'])
        escalate = t2['triggered'] or structural_escalation

        if mode == 'phase-entry' and not escalate:
            print(json.dumps({
                'status': 'pass',
                'detail': f"No drift action needed. Weight {t2['accumulated_weight']} < {t2['weight_threshold']}.",
                'acknowledged_signals_excluded': excluded_count,
                'trigger_1_signals': t1['signals_fired']
            }))
            sys.exit(0)

        now = datetime.now(timezone.utc)
        triggered_by = 'manual' if mode == 'manual' else ('trigger_2_commit_weight' if t2['triggered'] else 'trigger_1_phase_entry')

        domain_keywords_map = load_domain_keywords(project_root)
        related_domains = load_domain_relationships(project_root)
        implicated_domains = domains_for_signals(eff_fired, domain_keywords_map, related_domains)

        report = {
            'report_id': next_report_id(reports_dir, now),
            'generated_at': now.isoformat(),
            'triggered_by': triggered_by,
            'trigger_1_findings': t1,
            'trigger_2_findings': t2,
            'agent_assessment': build_assessment(eff_fired, t2, snapshot, implicated_domains, excluded_count),
            'user_questions': build_questions(eff_fired, ext_counts),
            'user_exceptions': [],
            'acknowledged_signals': acknowledged,
            'rerun_recommendations': build_rerun_recommendations(eff_fired, snapshot, ext_counts, phase_rerun_cfg, project_root, t2.get('severity')),
            'status': 'pending_user_response',
            'resolved_at': None
        }

        os.makedirs(reports_dir, exist_ok=True)
        archive_path = os.path.join(reports_dir, report['report_id'] + '.json')
        save_json(archive_path, report)
        save_json(latest_path, report)
        validation = try_validate(project_root, latest_path)

        print(json.dumps({
            'status': 'drift_report_generated',
            'report_id': report['report_id'],
            'triggered_by': triggered_by,
            'severity': t2.get('severity'),
            'archive': archive_path,
            'latest': latest_path,
            'schema_validation': validation,
            'signals_fired': t1['signals_fired'],
            'acknowledged_signals_excluded': excluded_count,
            'accumulated_weight': t2['accumulated_weight'],
            'next_step': 'Agent must collect responses to Section 4 questions before resuming coding.'
        }, indent=2))
        sys.exit(2)
    except Exception as e:
        sys.stderr.write(f"Error: detect_drift.py failed: {e}\n")
        sys.exit(1)
    finally:
        lock.release()

if __name__ == '__main__':
    main()
