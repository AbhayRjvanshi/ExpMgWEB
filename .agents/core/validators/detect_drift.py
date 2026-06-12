#!/usr/bin/env python3
"""
detect_drift.py (v1.0)

Orchestrates the three-trigger drift detection cascade.

    Trigger 1  Phase-entry lightweight scan (implemented here)
    Trigger 2  Weighted commit analysis (delegates to score_commits.py)
    Trigger 3  Drift report generation -> drift_reports/ + drift_report.json

Usage:
    python .agents/core/validators/detect_drift.py [project_root] [--mode phase-entry|manual]

Modes:
    phase-entry  (default) Run on every Phase 4 entry. Silent pass-through
                 unless Trigger 1 fires AND Trigger 2 crosses the weight
                 threshold.
    manual       User-invoked Trigger 3. Runs Triggers 1 and 2 for report
                 data and ALWAYS writes a full report.

Exit codes:
    0   No drift action needed (cascade passed)
    1   Error
    2   Drift report written - pending user response (the agent must
        present Sections 1-4 and collect answers; see global-policy.md)

Outputs:
    .agents/orchestration/drift_reports/drift_YYYYMMDD_NNN.json  (archive)
    .agents/orchestration/drift_report.json                      (latest copy)

Design notes (v1.1):
- This script is deterministic and NEVER interacts with the user. It
  writes user_questions with answered=false; presenting them, recording
  answers, resolving status, and resetting the weight counter on
  user-confirmed exceptions are agent responsibilities defined in
  global-policy.md.
- v1.1: Trigger 2 is UNGATED - both triggers always run on phase entry
  and either can escalate to Trigger 3. This closes the gap where heavy
  internal rewrites (high commit weight, zero structural signals) never
  reached weight analysis under the original T1-gated cascade.
- v1.1: Signals already acknowledged as exceptions in resolved drift
  reports are excluded from escalation, so an acknowledged new
  directory cannot generate repeat reports on every phase entry.
- v1.1: Trigger 2 emits a severity band (LOW/MODERATE/HIGH/
  CRITICAL_DRIFT) from configurable threshold multipliers.
- v1.1: Affected-skill matching expands signals through domain keyword
  lists and related-domain links instead of pure lexical matching.
- v1.2: Exceptions are scoped to the current snapshot lineage - only
  exceptions from reports generated AFTER the snapshot was captured
  are honored, and they expire after
  config trigger_1.exception_expiry_days (default 30, 0 = never).
  A 'temporary' signal still present after the window re-fires
  legitimately. Unparseable timestamps fail OPEN for detection (the
  exception is not honored) because suppression is the dangerous
  direction.
- v1.2: Domain relationship knowledge lives in
  prominence-profiles/domain_relationships.json; the built-in map is
  a portability fallback only.
- v1.2: Reports carry an explicit acknowledged_signals list so
  suppression is transparent, never implicit.
- v1.2: Severity weights rerun recommendations (HIGH/CRITICAL drift
  recommends Phase 1 re-discovery even without structural signals).
  Severity never enforces behavior autonomously - human authority is
  preserved by design.

ROADMAP (long-term, deferred deliberately):
- Activity-based exception expiry (commit count / phase entries /
  snapshot generations) instead of calendar days. Calendar expiry is
  evaluated lazily at check time, so dormant repositories generate no
  noise today.
- Bounded multi-hop domain expansion. One hop is a deliberate
  precision/recall tradeoff - deeper propagation inflates the
  'possibly affected' lists and causes review fatigue.
- Confidence-aware severity weighting once prominence confidence data
  is routinely available at drift time.
- Structured signal keys ({type, value} objects) replacing the
  'type:value' string grammar. The grammar is a stable contract - any
  change to it must be treated as a schema migration.
- Trigger 1 considers only DRIFT_EXTENSIONS (code and code-adjacent
  files) for both new-extension detection and file-count growth, so
  assets, docs and generated junk cannot fire false structural drift.
  The Phase 2 snapshot generator must count the same extension set.
- Stack-file change detection prefers commit-hash replay
  (snapshot.last_analyzed_commit .. HEAD) over timestamp replay, which
  survives rebases and amended commits. Timestamp --since is the
  fallback only when no hash exists yet.
- Reports use the schema field 'triggered' (true = signals fired,
  action needed). The earlier 'passed' name was renamed before any
  consumers existed because it read inverted.
- Malformed output from score_commits.py is an explicit exit-1 error,
  never a silent fallback to a fake-safe state.

ROADMAP (deferred deliberately):
- Interpretation profiles: the INTERPRETATIONS template table will move
  to .agents/core/ profile files if signal->assessment heuristics grow
  beyond a handful of cases, mirroring prominence-profiles/.
"""

import json
import os
import re
import subprocess
import sys
from datetime import datetime, timezone

SKIP_DIRS = frozenset([
    '.git', '.agents', 'vendor', 'node_modules', '.svn', '.hg',
    'dist', 'build', '__pycache__', '.next', '.nuxt', 'coverage',
    '.idea', '.vscode', 'graphify-out', 'logs', 'data'
])

# Code + code-adjacent extensions considered for drift. Superset of
# gather_evidence.py SCANNABLE_EXTENSIONS plus frontend/style and
# emerging-language extensions so a genuinely new stack still registers.
DRIFT_EXTENSIONS = frozenset([
    '.php', '.js', '.mjs', '.ts', '.tsx', '.jsx', '.py', '.rb', '.go',
    '.java', '.cs', '.cpp', '.c', '.vue', '.html', '.htm', '.sql', '.sh',
    '.css', '.scss', '.sass', '.less', '.rs', '.kt', '.swift', '.dart'
])

PAGE_DIR_NAMES = frozenset(['pages', 'views', 'templates', 'screens'])

# Known domain names so a new directory like 'api/' maps to the 'api'
# domain even when prominence-profiles/domain_keywords.json is absent.
DEFAULT_DOMAIN_NAMES = frozenset([
    'database', 'authentication', 'file-management', 'email', 'payment',
    'api', 'testing', 'caching', 'logging', 'deployment', 'storage',
    'messaging', 'search', 'media', 'finance', 'settlement', 'async',
    'notifications'
])

# Built-in FALLBACK for domain relationship knowledge. The canonical
# source is .agents/core/prominence-profiles/domain_relationships.json;
# this copy keeps the script portable when that file is absent.
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

# Signal type -> (assessment template, question template).
# Future: move to interpretation profile files if this table grows.
INTERPRETATIONS = {
    'new_directory': (
        "The new '{0}/' directory suggests a new architectural layer or "
        "domain not present in the original skill snapshot.",
        "A new top-level directory '{0}/' appeared - is this a permanent "
        "new layer of the project or a temporary folder?"
    ),
    'new_extension': (
        "Files of type '{0}' appeared for the first time ({1} files), "
        "suggesting a new technology or tooling entered the project.",
        "A new file type '{0}' appeared in {1} files - does this "
        "represent a permanent addition to the stack?"
    ),
    'stack_file': (
        "Stack-defining file '{0}' changed - dependencies or environment "
        "configuration may have shifted.",
        "'{0}' changed since the snapshot - did the dependency stack or "
        "environment configuration change in a way that affects skills?"
    ),
    'file_growth': (
        "Code file count grew {0}% since the snapshot, indicating "
        "substantial project expansion.",
        "The project's code file count grew {0}% - is this organic "
        "feature growth, or was a new subsystem or vendored code added?"
    ),
}


def load_json(path):
    with open(path, 'r', encoding='utf-8') as f:
        return json.load(f)


def save_json(path, data):
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(data, f, indent=2)


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


# ------------------------- Trigger 1 -------------------------

def scan_current_state(project_root):
    """One tree walk. Counts only DRIFT_EXTENSIONS files so assets,
    docs and generated artifacts cannot inflate drift signals."""
    ext_counts = {}
    top_dirs = set()
    total = 0
    for dirpath, dirnames, filenames in os.walk(project_root):
        dirnames[:] = [
            d for d in dirnames
            if d not in SKIP_DIRS and not d.startswith('.')
        ]
        if os.path.abspath(dirpath) == project_root:
            top_dirs.update(dirnames)
        for filename in filenames:
            ext = os.path.splitext(filename)[1].lower()
            if ext not in DRIFT_EXTENSIONS:
                continue
            ext_counts[ext] = ext_counts.get(ext, 0) + 1
            total += 1
    return ext_counts, sorted(top_dirs), total


def _run_git(args, cwd):
    return subprocess.run(
        ['git'] + args, cwd=cwd, capture_output=True, text=True,
        timeout=GIT_TIMEOUT)


def stack_files_changed_since(project_root, snapshot, stack_files):
    """Stack-defining files changed since the snapshot.

    Prefers commit-hash replay (last_analyzed_commit..HEAD) which is
    stable across rebases and amended commits. Falls back to
    --since=<captured_at> only when no hash has been recorded yet.
    Returns [] if git is unavailable - Trigger 1 then relies on its
    other three checks rather than erroring out."""
    if not stack_files:
        return []

    changed_paths = None
    last_commit = snapshot.get('last_analyzed_commit')

    try:
        if last_commit:
            proc = _run_git(
                ['diff', '--name-only',
                 '{0}..HEAD'.format(last_commit)], project_root)
            if proc.returncode == 0:
                changed_paths = {
                    line.strip() for line in proc.stdout.splitlines()
                    if line.strip()
                }
        if changed_paths is None:
            captured_at = snapshot.get('captured_at')
            if not captured_at:
                return []
            proc = _run_git(
                ['log', '--since={0}'.format(captured_at),
                 '--name-only', '--format=', '--no-merges'],
                project_root)
            if proc.returncode != 0:
                return []
            changed_paths = {
                line.strip() for line in proc.stdout.splitlines()
                if line.strip()
            }
    except (subprocess.TimeoutExpired, FileNotFoundError):
        return []

    changed = []
    for stack_file in stack_files:
        for path in changed_paths:
            if path == stack_file or path.endswith('/' + stack_file):
                changed.append(stack_file)
                break
    return sorted(changed)


def run_trigger_1(project_root, snapshot, t1_config):
    """Lightweight phase-entry scan. Returns (findings, fired, ext_counts).
    findings matches drift_report.schema.json trigger_1_findings.
    fired carries internal booleans for downstream builders."""
    ext_counts, top_dirs, total = scan_current_state(project_root)

    snap_exts = set(snapshot.get('file_counts', {}).keys())
    new_exts = sorted(e for e in ext_counts if e not in snap_exts)
    ext_threshold = t1_config.get('new_file_extension_threshold', 1)
    exts_fired = len(new_exts) >= ext_threshold

    snap_dirs = set(snapshot.get('top_directories', []))
    new_dirs = sorted(d for d in top_dirs if d not in snap_dirs)
    if not t1_config.get('new_top_level_directory_triggers', True):
        new_dirs = []

    stack_changed = stack_files_changed_since(
        project_root, snapshot,
        t1_config.get('stack_file_changes_trigger', []))

    snap_total = snapshot.get('total_file_count', 0)
    growth = (round((total - snap_total) / snap_total * 100, 1)
              if snap_total > 0 else None)
    growth_threshold = t1_config.get(
        'file_count_growth_threshold_percent', 40)
    growth_fired = growth is not None and growth > growth_threshold

    signals = []
    if exts_fired:
        for ext in new_exts:
            signals.append(
                "New file extension '{0}' appeared in {1} files".format(
                    ext, ext_counts[ext]))
    for d in new_dirs:
        signals.append(
            "New top-level directory '{0}/' detected".format(d))
    for f in stack_changed:
        signals.append(
            "Stack-defining file '{0}' changed".format(f))
    if growth_fired:
        signals.append(
            "Code file count grew {0}% (threshold {1}%)".format(
                growth, growth_threshold))

    signal_keys = []
    if exts_fired:
        signal_keys.extend(
            'new_file_extension:{0}'.format(e) for e in new_exts)
    signal_keys.extend(
        'new_top_level_directory:{0}'.format(d) for d in new_dirs)
    signal_keys.extend(
        'stack_file_changed:{0}'.format(f) for f in stack_changed)
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
    """Parse an ISO 8601 timestamp defensively. Naive timestamps are
    assumed UTC. Returns None on failure."""
    if not isinstance(value, str) or not value.strip():
        return None
    try:
        parsed = datetime.fromisoformat(value.replace('Z', '+00:00'))
    except ValueError:
        return None
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed


def load_acknowledged_exceptions(reports_dir, snapshot_captured_at,
                                 expiry_days):
    """Exceptions valid for the CURRENT snapshot lineage only.

    An exception is honored when ALL of the following hold:
    - it comes from a RESOLVED archived report
    - the report was generated AFTER the current snapshot was captured
      (snapshot regeneration invalidates older exceptions - any
      acknowledged structure is baked into the new baseline anyway)
    - the report is younger than expiry_days (a 'temporary' signal
      still present after the window is real drift and must re-fire).
      expiry_days of 0 disables time expiry; lineage scoping remains.

    Unparseable timestamps fail OPEN for detection: the exception is
    NOT honored, because suppression is the dangerous direction.

    Returns {signal_key: {'report_id': ..., 'acknowledged_at': ...}}.
    """
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
            continue  # fail open for detection
        if snapshot_time is not None and generated < snapshot_time:
            continue  # pre-snapshot exception: superseded by baseline
        if expiry_days > 0 and (now - generated).days > expiry_days:
            continue  # expired: a still-present signal must re-fire
        for signal in r.get('user_exceptions', []):
            if isinstance(signal, str):
                exceptions[signal] = {
                    'report_id': r.get('report_id', filename[:-5]),
                    'acknowledged_at': r.get('generated_at')
                }
    return exceptions


def apply_exceptions(fired, exceptions):
    """Filter fired signals down to those NOT previously acknowledged.
    Returns (effective_fired, acknowledged) where acknowledged is the
    explicit suppression record written into the report's
    acknowledged_signals field. The full findings still show
    everything; only escalation and questions use the filtered view."""
    def keep(key):
        return key not in exceptions

    eff = {
        'new_exts': [e for e in fired['new_exts']
                     if keep('new_file_extension:{0}'.format(e))],
        'new_dirs': [d for d in fired['new_dirs']
                     if keep('new_top_level_directory:{0}'.format(d))],
        'stack_changed': [
            f for f in fired['stack_changed']
            if keep('stack_file_changed:{0}'.format(f))],
        'growth_fired': (fired['growth_fired']
                         and keep('file_count_growth')),
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
                'note': ('Previously marked as a non-significant '
                         'exception by the user; excluded from '
                         'escalation and questioning until the '
                         'exception expires or the snapshot is '
                         'regenerated.')
            })
    return eff, acknowledged


# ------------------------- Trigger 2 -------------------------

def severity_for(weight, threshold, severity_cfg):
    """Severity band from accumulated weight as a multiple of the
    threshold. None when the threshold was not crossed."""
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
    """Delegate to score_commits.py and adapt its output to the
    drift_report contract. Flattens its reasons[] lists into the
    singular 'reason' string the schema requires.

    Returns (findings, error_message). error_message is None on
    success; any failure (including malformed stdout) is explicit."""
    script = os.path.join(
        project_root, '.agents', 'core', 'validators',
        'score_commits.py')
    if not os.path.isfile(script):
        return None, 'score_commits.py not found at {0}'.format(script)

    try:
        proc = subprocess.run(
            [sys.executable, script, project_root],
            capture_output=True, text=True, timeout=120)
    except subprocess.TimeoutExpired:
        return None, 'score_commits.py timed out after 120s'

    if proc.returncode == 1:
        snippet = (proc.stdout or proc.stderr or '').strip()[:500]
        return None, 'score_commits.py reported an error: {0}'.format(
            snippet)

    try:
        data = json.loads(proc.stdout)
    except (json.JSONDecodeError, TypeError) as e:
        # Explicit failure - a malformed result must never silently
        # become a fake-safe 'no drift' state.
        snippet = (proc.stdout or '').strip()[:500]
        return None, (
            'Cannot parse score_commits.py output ({0}). '
            'Raw output begins: {1}'.format(e, snippet))

    commits = []
    for c in data.get('commits_analyzed', []):
        reasons = c.get('reasons', [])
        reason_parts = []
        for r in reasons:
            if isinstance(r, dict):
                reason_parts.append(
                    r.get('detail') or r.get('reason') or str(r))
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
        'severity': severity_for(
            accumulated, weight_threshold, severity_cfg),
        'accumulated_weight': accumulated,
        'weight_threshold': weight_threshold,
        'commits_analyzed': commits
    }
    return findings, None


# ------------------------- Trigger 3 -------------------------

def load_domain_keywords(project_root):
    """Load shared domain keyword lists if prominence-profiles ships
    them; gracefully empty otherwise."""
    path = os.path.join(
        project_root, '.agents', 'core', 'prominence-profiles',
        'domain_keywords.json')
    if not os.path.isfile(path):
        return {}
    try:
        return load_json(path).get('domains', {})
    except (json.JSONDecodeError, OSError):
        return {}


def load_domain_relationships(project_root):
    """Load domain relationship knowledge from
    prominence-profiles/domain_relationships.json; fall back to the
    built-in DEFAULT_RELATED_DOMAINS when absent or unparseable."""
    path = os.path.join(
        project_root, '.agents', 'core', 'prominence-profiles',
        'domain_relationships.json')
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
    """Map fired structural signals to implicated domains, then expand
    ONE hop through the domain relationship map (deliberate
    precision/recall tradeoff). A new 'api/' directory implicates the
    api domain directly and authentication/caching/messaging/logging
    through expansion."""
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
    """Skills/MCPs possibly affected. Three rules:
    1. discovery_evidence has file_type:<ext> for a new extension
    2. a tag value lexically matches a new top-level directory
    3. a domain:<d> tag falls inside the implicated-domain set
       (direct + one-hop related expansion)"""
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


def build_assessment(fired, t2, snapshot, implicated_domains,
                     excluded_count):
    suspected = []
    for d in fired['new_dirs']:
        suspected.append(INTERPRETATIONS['new_directory'][0].format(d))
    for ext in fired['new_exts']:
        suspected.append(
            INTERPRETATIONS['new_extension'][0].format(ext, '?'))
    for f in fired['stack_changed']:
        suspected.append(INTERPRETATIONS['stack_file'][0].format(f))
    if fired['growth_fired']:
        suspected.append(
            INTERPRETATIONS['file_growth'][0].format(fired['growth']))

    severity_note = (' Severity: {0}.'.format(t2['severity'])
                     if t2.get('severity') else '')
    excluded_note = (
        ' {0} previously acknowledged signal(s) were excluded.'.format(
            excluded_count) if excluded_count else '')

    signal_count = (len(fired['new_dirs']) + len(fired['new_exts'])
                    + len(fired['stack_changed'])
                    + (1 if fired['growth_fired'] else 0))
    summary = (
        'Trigger 1 fired {0} signal(s) and accumulated commit weight '
        'reached {1} (threshold {2}).{3} The project appears to have '
        'structurally changed since the snapshot was '
        'captured.{4}'.format(
            signal_count, t2['accumulated_weight'],
            t2['weight_threshold'], severity_note, excluded_note))
    if not suspected:
        summary = (
            'Commit weight reached {0} (threshold {1}) without surface '
            'structure changes.{2} Many substantive code modifications '
            'accumulated inside the existing structure since the '
            'snapshot.{3}'.format(
                t2['accumulated_weight'], t2['weight_threshold'],
                severity_note, excluded_note))
        suspected.append(
            'Sustained heavy modification of existing files; project '
            'scope may have shifted without new files or directories.')

    return {
        'summary': summary,
        'suspected_changes': suspected,
        'skills_possibly_affected': match_affected(
            snapshot.get('skill_justifications', {}), fired,
            implicated_domains),
        'mcps_possibly_affected': match_affected(
            snapshot.get('mcp_justifications', {}), fired,
            implicated_domains)
    }


def build_questions(fired, ext_counts):
    """Section 4 - exactly one targeted question per fired signal."""
    questions = []
    for d in fired['new_dirs']:
        questions.append({
            'signal': 'new_top_level_directory:{0}'.format(d),
            'question': INTERPRETATIONS['new_directory'][1].format(d),
            'user_answer': None,
            'answered': False
        })
    for ext in fired['new_exts']:
        questions.append({
            'signal': 'new_file_extension:{0}'.format(ext),
            'question': INTERPRETATIONS['new_extension'][1].format(
                ext, ext_counts.get(ext, 0)),
            'user_answer': None,
            'answered': False
        })
    for f in fired['stack_changed']:
        questions.append({
            'signal': 'stack_file_changed:{0}'.format(f),
            'question': INTERPRETATIONS['stack_file'][1].format(f),
            'user_answer': None,
            'answered': False
        })
    if fired['growth_fired']:
        questions.append({
            'signal': 'file_count_growth',
            'question': INTERPRETATIONS['file_growth'][1].format(
                fired['growth']),
            'user_answer': None,
            'answered': False
        })
    if not questions:
        questions.append({
            'signal': 'commit_weight_threshold',
            'question': ('Commit weight crossed the drift threshold '
                         'through sustained modifications. Has the '
                         'project direction or scope changed since the '
                         'last snapshot?'),
            'user_answer': None,
            'answered': False
        })
    return questions


def design_tokens_stale(project_root, max_age_days):
    """True only if design_tokens.json exists AND is older than the
    configured age. A missing file is not staleness - Phase 3 may
    simply never have run."""
    path = os.path.join(
        project_root, '.agents', 'orchestration', 'design_tokens.json')
    if not os.path.isfile(path):
        return False
    try:
        age_days = (
            datetime.now(timezone.utc)
            - datetime.fromtimestamp(os.path.getmtime(path),
                                     tz=timezone.utc)
        ).days
    except OSError:
        return False
    return age_days > max_age_days


def build_rerun_recommendations(fired, snapshot, ext_counts,
                                phase_rerun_cfg, project_root,
                                severity):
    """Phase dependency chain. Minimum necessary phases - never
    'rerun everything' by default.
        Phase 1 -> skill_requirements.json
        Phase 2 -> consumes Phase 1 -> generated skills + snapshot
        Phase 3 -> consumes Phase 1 -> design tokens (frontend only)
        Phase 4 -> consumes generated skills + design tokens

    Severity WEIGHTS the recommendations - it never enforces behavior.
    HIGH/CRITICAL sustained modification justifies re-discovery even
    when the surface structure is unchanged. The human decides.
    """
    structural = (bool(fired['new_exts']) or bool(fired['new_dirs'])
                  or bool(fired['stack_changed'])
                  or fired['growth_fired'])
    severe = severity in ('HIGH_DRIFT', 'CRITICAL_DRIFT')

    stale_skills = sorted(
        name for name, j in
        snapshot.get('skill_justifications', {}).items()
        if j.get('prominence_verdict') in ('LOW', 'MINIMAL'))

    phase_1 = structural or severe
    if structural:
        phase_1_reason = (
            'Structural drift detected: ' + '; '.join(
                filter(None, [
                    'new file types {0}'.format(fired['new_exts'])
                    if fired['new_exts'] else '',
                    'new directories {0}'.format(fired['new_dirs'])
                    if fired['new_dirs'] else '',
                    'stack files changed {0}'.format(
                        fired['stack_changed'])
                    if fired['stack_changed'] else '',
                    'code file growth {0}%'.format(fired['growth'])
                    if fired['growth_fired'] else ''
                ]))
            + (' (severity: {0})'.format(severity) if severity else ''))
    elif severe:
        phase_1_reason = (
            'No structural signals, but sustained modification reached '
            '{0} - re-discovery is recommended to confirm the project '
            'description still holds.'.format(severity))
    else:
        phase_1_reason = (
            'No new file types, directories, stack changes or major '
            'growth - existing skill_requirements.json still describes '
            'the project.')

    phase_2 = phase_1 or bool(stale_skills)
    if phase_1:
        phase_2_reason = ('Phase 1 output will change, so Phase 2 must '
                          'be rerun (automatic dependency).')
    elif stale_skills:
        phase_2_reason = (
            'Skills with LOW/MINIMAL prominence need review without a '
            'full Phase 1 rerun: {0}'.format(', '.join(stale_skills)))
    else:
        phase_2_reason = ('Generated skills still match the project; no '
                          'staleness flags present.')

    css_now = ext_counts.get('.css', 0)
    css_snap = snapshot.get('file_counts', {}).get('.css', 0)
    css_threshold = phase_rerun_cfg.get(
        'frontend_css_growth_threshold_percent', 50)
    css_fired = (css_snap > 0 and
                 (css_now - css_snap) / css_snap * 100 > css_threshold)
    new_page_dirs = [d for d in fired['new_dirs']
                     if d.lower() in PAGE_DIR_NAMES]
    tokens_old = design_tokens_stale(
        project_root,
        phase_rerun_cfg.get('design_tokens_max_age_days', 90))
    if not phase_rerun_cfg.get('frontend_new_page_directory_triggers',
                               True):
        new_page_dirs = []

    phase_3 = css_fired or bool(new_page_dirs) or tokens_old
    phase_3_reasons = []
    if css_fired:
        phase_3_reasons.append(
            'CSS file count grew beyond {0}% ({1} -> {2})'.format(
                css_threshold, css_snap, css_now))
    if new_page_dirs:
        phase_3_reasons.append(
            'New page directories appeared: {0}'.format(
                ', '.join(new_page_dirs)))
    if tokens_old:
        phase_3_reasons.append('design_tokens.json is significantly old')
    phase_3_reason = ('; '.join(phase_3_reasons) if phase_3 else
                      'No significant frontend scope changes detected.')

    phase_4 = phase_2
    phase_4_reason = (
        'Generated skills will be updated or replaced - re-read them '
        'before Phase 4 coding resumes.'
        if phase_4 else
        'No generated skill changes pending; Phase 4 may continue with '
        'current skills once this report is resolved.')

    return {
        'phase_1': {'recommended': phase_1, 'reason': phase_1_reason},
        'phase_2': {'recommended': phase_2, 'reason': phase_2_reason},
        'phase_3': {'recommended': phase_3, 'reason': phase_3_reason},
        'phase_4': {'recommended': phase_4, 'reason': phase_4_reason}
    }


def next_report_id(reports_dir, now):
    """drift_YYYYMMDD_NNN - sequence derived from archived reports for
    the same UTC date, so report IDs never collide."""
    date_part = now.strftime('%Y%m%d')
    pattern = re.compile(
        r'^drift_{0}_(\d{{3}})\.json$'.format(date_part))
    max_seq = 0
    if os.path.isdir(reports_dir):
        for filename in os.listdir(reports_dir):
            m = pattern.match(filename)
            if m:
                max_seq = max(max_seq, int(m.group(1)))
    return 'drift_{0}_{1:03d}'.format(date_part, max_seq + 1)


def try_validate(project_root, report_path):
    """Best-effort self-validation against the contract. Non-fatal -
    the validator may need libraries unavailable in this environment."""
    validator = os.path.join(
        project_root, '.agents', 'core', 'validators',
        'validate_json.py')
    schema = os.path.join(
        project_root, '.agents', 'core', 'contracts',
        'drift_report.schema.json')
    if not (os.path.isfile(validator) and os.path.isfile(schema)):
        return 'skipped'
    try:
        proc = subprocess.run(
            [sys.executable, validator, report_path, schema],
            capture_output=True, text=True, timeout=30)
    except (subprocess.TimeoutExpired, OSError):
        return 'skipped'
    if proc.returncode == 0:
        return 'passed'
    return 'failed: {0}'.format(
        (proc.stdout or proc.stderr or '').strip()[:300])


def main():
    project_root, mode = parse_args(sys.argv)
    if project_root is None:
        fail('Usage: detect_drift.py [project_root] '
             '[--mode phase-entry|manual]')

    config_path = os.path.join(
        project_root, '.agents', 'core', 'config.json')
    snapshot_path = os.path.join(
        project_root, '.agents', 'orchestration',
        'project_snapshot.json')
    reports_dir = os.path.join(
        project_root, '.agents', 'orchestration', 'drift_reports')
    latest_path = os.path.join(
        project_root, '.agents', 'orchestration', 'drift_report.json')

    try:
        config = load_json(config_path)
    except FileNotFoundError:
        fail('config.json not found at {0}'.format(config_path))
    except json.JSONDecodeError as e:
        fail('Cannot parse config.json: {0}'.format(e))

    drift_cfg = config.get('drift_sensitivity', {})
    t1_cfg = drift_cfg.get('trigger_1', {})
    t2_cfg = drift_cfg.get('trigger_2', {})
    weight_threshold = t2_cfg.get('weight_threshold', 50)
    severity_cfg = t2_cfg.get('severity_multipliers', {})
    phase_rerun_cfg = drift_cfg.get('phase_rerun', {})

    try:
        snapshot = load_json(snapshot_path)
    except FileNotFoundError:
        fail('project_snapshot.json not found. Run Phase 2 before '
             'running drift detection.')
    except json.JSONDecodeError as e:
        fail('Cannot parse project_snapshot.json: {0}'.format(e))

    # -- Triggers 1 and 2: BOTH always run (v1.1 ungated cascade) --
    t1, fired, ext_counts = run_trigger_1(project_root, snapshot, t1_cfg)
    t2, t2_error = run_trigger_2(
        project_root, weight_threshold, severity_cfg)
    if t2_error:
        fail('Trigger 2 failed: {0}'.format(t2_error))

    expiry_days = t1_cfg.get('exception_expiry_days', 30)
    exceptions = load_acknowledged_exceptions(
        reports_dir, snapshot.get('captured_at'), expiry_days)
    eff_fired, acknowledged = apply_exceptions(fired, exceptions)
    excluded_count = len(acknowledged)
    structural_escalation = bool(eff_fired['signal_keys'])
    escalate = t2['triggered'] or structural_escalation

    if mode == 'phase-entry' and not escalate:
        print(json.dumps({
            'status': 'pass',
            'detail': ('No unacknowledged structural signals and '
                       'accumulated commit weight {0} is below '
                       'threshold {1}. Proceed with coding.').format(
                           t2['accumulated_weight'],
                           t2['weight_threshold']),
            'acknowledged_signals_excluded': excluded_count,
            'trigger_1_signals': t1['signals_fired']
        }))
        sys.exit(0)

    # -- Trigger 3 --
    now = datetime.now(timezone.utc)
    if mode == 'manual':
        triggered_by = 'manual'
    elif t2['triggered']:
        triggered_by = 'trigger_2_commit_weight'
    else:
        triggered_by = 'trigger_1_phase_entry'

    domain_keywords_map = load_domain_keywords(project_root)
    related_domains = load_domain_relationships(project_root)
    implicated_domains = domains_for_signals(
        eff_fired, domain_keywords_map, related_domains)

    report = {
        'report_id': next_report_id(reports_dir, now),
        'generated_at': now.isoformat(),
        'triggered_by': triggered_by,
        'trigger_1_findings': t1,
        'trigger_2_findings': t2,
        'agent_assessment': build_assessment(
            eff_fired, t2, snapshot, implicated_domains,
            excluded_count),
        'user_questions': build_questions(eff_fired, ext_counts),
        'user_exceptions': [],
        'acknowledged_signals': acknowledged,
        'rerun_recommendations': build_rerun_recommendations(
            eff_fired, snapshot, ext_counts, phase_rerun_cfg,
            project_root, t2.get('severity')),
        'status': 'pending_user_response',
        'resolved_at': None
    }

    os.makedirs(reports_dir, exist_ok=True)
    archive_path = os.path.join(
        reports_dir, report['report_id'] + '.json')
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
        'next_step': ('Agent must present Sections 1-4 of the report '
                      'to the user and record answers before any '
                      'Phase 4 coding continues. See global-policy.md '
                      'Drift Detection resolution rules.')
    }, indent=2))
    sys.exit(2)


if __name__ == '__main__':
    main()
