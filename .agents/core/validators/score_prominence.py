#!/usr/bin/env python3
"""
score_prominence.py (v1.2)

Reads evidence_report.json and computes a prominence score (0-100),
a confidence rating (0.0-1.0), and a trend for every skill and MCP.
Writes scores back into project_snapshot.json and emits a full
prominence_report.json for downstream consumers
(generate_impact_brief.py, drift reports, Layer 2 human review).

Usage:
    python .agents/core/validators/score_prominence.py [project_root]

Exit codes:
    0   Success
    1   Error (see 'error' field in stdout JSON)

Scoring (additive, transparent - total 100 points):
    direct_usage     0-40   actual code calls (most concrete)
    architectural    0-20   how central the technology is
    dependency       0-20   declared in manifests (intentional)
    configuration    0-10   present in env / docker (deployed reality)
    commit_history   0-10   actively worked on recently

Confidence (0.0-1.0) - honesty about uncertainty, NOT a second score:
    0.6 x evidence diversity (how many of the 5 categories had signal)
    0.3 x tag resolution rate (profiles found for discovery tags)
    0.1 x data availability (git history was readable)
    A 72 backed by four categories reads very differently in a Layer 2
    brief than a 72 from one noisy category.

Trend - cheap negative evidence:
    Compares the new score against the previous prominence_score stored
    in project_snapshot.json. Emits trend (first_check | rising |
    stable | declining, +/-5 band) and score_delta. Full run-over-run
    history is appended to prominence_history.json (last 20 runs kept)
    so a decline like 85 -> 60 -> 30 across checks is visible.

Verdict thresholds come from config.json
drift_sensitivity.prominence_thresholds.

Notes:
- project_snapshot.json's prominence_verdict field is schema-constrained
  to the enum HIGH | MODERATE | LOW | MINIMAL. The long plain-language
  form ('HIGH - database layer active and central to 18 files') is
  written to prominence_report.json as 'verdict_statement'.
- If git was unavailable during evidence gathering, commit_history
  scores 0 and confidence is reduced - absence of data is not treated
  as evidence of absence.

ROADMAP (deferred deliberately):
- Temporal decay: trend tracking covers decay-over-checks; an
  age-weighted evidence formula may follow once real reports exist to
  tune against.
- Full negative evidence: dependency/config removal diffing between
  checks (trend is the cheap version).
- AST/token parsing and graphify call-graph integration: see
  gather_evidence.py ROADMAP.
"""

import json
import os
import sys
from datetime import datetime, timezone

DEFAULT_THRESHOLDS = {
    'high_min': 70,
    'moderate_min': 40,
    'low_min': 15,
    'minimal_min': 0
}

HISTORY_MAX_RUNS = 20
TREND_BAND = 5


def load_json(path):
    with open(path, 'r', encoding='utf-8') as f:
        return json.load(f)


def save_json(path, data):
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(data, f, indent=2)


def score_direct_usage(ev):
    """Max 40 points.
    Files with matches: 2 points per file, capped at 20.
    Match volume: tiered, capped at 20.
    Extension-only presence (no pattern hits) earns a partial fallback.
    """
    unique_files = ev.get('unique_files_count', 0)
    total_matches = ev.get('total_match_count', 0)
    ext_total = sum(ev.get('extension_counts', {}).values())

    if unique_files == 0:
        if ext_total >= 10:
            return 16
        if ext_total >= 3:
            return 10
        if ext_total >= 1:
            return 6
        return 0

    file_score = min(unique_files * 2, 20)

    if total_matches >= 50:
        match_score = 20
    elif total_matches >= 20:
        match_score = 14
    elif total_matches >= 5:
        match_score = 8
    else:
        match_score = 2

    return min(file_score + match_score, 40)


def score_architectural(ev):
    """Max 20 points.
    Core logic presence: 8 (+4 if core ratio >= 0.5, +2 if >= 0.25)
    Technology-specific exception handling: 4
    Directory spread: 4 (>= 4 dirs) or 2 (>= 2 dirs)
    """
    if ev.get('total_files_with_usage', 0) == 0:
        return 0

    score = 0
    if ev.get('in_core_logic', False):
        score += 8
        ratio = ev.get('core_ratio', 0.0)
        if ratio >= 0.5:
            score += 4
        elif ratio >= 0.25:
            score += 2

    if ev.get('exception_handling_found', False):
        score += 4

    spread = ev.get('directory_spread', 0)
    if spread >= 4:
        score += 4
    elif spread >= 2:
        score += 2

    return min(score, 20)


def score_dependency(ev):
    """Max 20 points. 10 per exact-matched package, capped at 2.
    Declared dependency = intentional, reliable evidence."""
    return min(ev.get('packages_found_count', 0) * 10, 20)


def score_configuration(ev):
    """Max 10 points.
    Env keys found: 4. Env values matched: 2. Docker services: 4."""
    score = 0
    if ev.get('env_keys_found'):
        score += 4
    if ev.get('env_values_matched'):
        score += 2
    if ev.get('docker_services_found'):
        score += 4
    return min(score, 10)


def score_commit_history(ev):
    """Max 10 points.
    Matching commits: tiered up to 6. Keyword diversity: up to 4.
    Scores 0 (not a penalty) when git was unavailable."""
    if not ev.get('searched', False) or ev.get('error'):
        return 0

    commits = ev.get('recent_commits_count', 0)
    keywords = len(ev.get('keywords_found', []))

    if commits >= 8:
        commit_score = 6
    elif commits >= 4:
        commit_score = 4
    elif commits >= 2:
        commit_score = 3
    elif commits >= 1:
        commit_score = 2
    else:
        commit_score = 0

    if keywords >= 4:
        kw_score = 4
    elif keywords >= 2:
        kw_score = 2
    elif keywords >= 1:
        kw_score = 1
    else:
        kw_score = 0

    return min(commit_score + kw_score, 10)


def compute_score(evidence):
    """Compute total prominence score (0-100) and per-category
    breakdown from the 5 evidence categories."""
    breakdown = {
        'direct_usage': score_direct_usage(
            evidence.get('direct_usage', {})),
        'architectural': score_architectural(
            evidence.get('architectural', {})),
        'dependency': score_dependency(
            evidence.get('dependency', {})),
        'configuration': score_configuration(
            evidence.get('configuration', {})),
        'commit_history': score_commit_history(
            evidence.get('commit_history', {}))
    }
    return min(sum(breakdown.values()), 100), breakdown


def compute_confidence(item):
    """Confidence (0.0-1.0): how much the score should be trusted.
    Derived entirely from already-gathered data - no extra analysis."""
    evidence = item.get('evidence', {})
    missing = item.get('evidence_missing', [])
    diversity = (5 - len(missing)) / 5.0

    resolved = len(item.get('tags_resolved', []))
    unresolved = len(item.get('tags_unresolved', []))
    total_tags = resolved + unresolved
    resolution_rate = (resolved / total_tags) if total_tags else 0.0

    hist = evidence.get('commit_history', {})
    git_ok = 1.0 if (hist.get('searched')
                     and not hist.get('error')) else 0.0

    return round(
        0.6 * diversity + 0.3 * resolution_rate + 0.1 * git_ok, 2)


def compute_trend(previous_score, new_score):
    """Trend between consecutive checks. +/-TREND_BAND points is
    considered stable."""
    if previous_score is None:
        return 'first_check', None
    delta = round(new_score - previous_score, 1)
    if delta >= TREND_BAND:
        return 'rising', delta
    if delta <= -TREND_BAND:
        return 'declining', delta
    return 'stable', delta


def get_verdict(score, thresholds):
    """Map a numeric score to the schema enum verdict."""
    if score >= thresholds.get('high_min', 70):
        return 'HIGH'
    if score >= thresholds.get('moderate_min', 40):
        return 'MODERATE'
    if score >= thresholds.get('low_min', 15):
        return 'LOW'
    return 'MINIMAL'


def build_summary(evidence):
    """Plain-language evidence summary, used in drift reports and
    Layer 2 impact briefs."""
    direct = evidence.get('direct_usage', {})
    arch = evidence.get('architectural', {})
    dep = evidence.get('dependency', {})
    conf = evidence.get('configuration', {})
    hist = evidence.get('commit_history', {})

    parts = []

    unique = direct.get('unique_files_count', 0)
    matches = direct.get('total_match_count', 0)
    if unique > 0:
        parts.append(
            'Direct usage in {0} files ({1} pattern matches)'.format(
                unique, matches))

    ext_counts = direct.get('extension_counts', {})
    if ext_counts:
        parts.append('File types: ' + ', '.join(
            '{0}:{1}'.format(ext, n)
            for ext, n in sorted(ext_counts.items())))

    if arch.get('in_core_logic') and arch.get('core_file_count', 0) > 0:
        parts.append(
            'Present in core logic ({0} core files)'.format(
                arch['core_file_count']))

    if arch.get('exception_handling_found'):
        parts.append('Technology-specific exception handling present')

    dirs = arch.get('distinct_directories', [])
    if dirs:
        parts.append('Spread across {0} directories: {1}'.format(
            len(dirs), ', '.join(dirs[:4])))

    pkgs = dep.get('packages_found', [])
    if pkgs:
        parts.append('Declared dependencies: ' + ', '.join(
            p['package'] for p in pkgs))

    if conf.get('env_keys_found'):
        parts.append('Config signals: ' + ', '.join(
            conf['env_keys_found']))
    if conf.get('docker_services_found'):
        parts.append('Docker services: ' + ', '.join(
            conf['docker_services_found']))

    recent = hist.get('recent_commits_count', 0)
    if recent > 0:
        parts.append('{0} recent commits with keywords: {1}'.format(
            recent, ', '.join(hist.get('keywords_found', []))))

    if not parts:
        return 'No evidence found in codebase'
    return ' | '.join(parts)


def score_item(item, previous_score, thresholds, now):
    """Score one skill or MCP. Returns the full result entry."""
    evidence = item.get('evidence', {})
    score, breakdown = compute_score(evidence)
    verdict = get_verdict(score, thresholds)
    summary = build_summary(evidence)
    confidence = compute_confidence(item)
    trend, delta = compute_trend(previous_score, score)

    return {
        'prominence_score': score,
        'prominence_verdict': verdict,
        'verdict_statement': '{0} - {1}'.format(verdict, summary),
        'confidence': confidence,
        'trend': trend,
        'score_delta': delta,
        'previous_score': previous_score,
        'score_breakdown': breakdown,
        'evidence_summary': summary,
        'evidence_missing': item.get('evidence_missing', []),
        'tags_unresolved': item.get('tags_unresolved', []),
        'scored_at': now
    }


def previous_score_of(snapshot, section_key, name):
    entry = snapshot.get(section_key, {}).get(name, {})
    value = entry.get('prominence_score')
    return value if isinstance(value, (int, float)) else None


def write_back(snapshot, section_key, name, result, now):
    """Write score, enum verdict and timestamp into the snapshot's
    justification record (schema-compliant fields only)."""
    section = snapshot.get(section_key, {})
    if name in section:
        section[name]['prominence_score'] = result['prominence_score']
        section[name]['prominence_verdict'] = (
            result['prominence_verdict'])
        section[name]['last_checked_at'] = now


def append_history(history_path, now, output):
    """Append this run's scores to prominence_history.json so multi-run
    declines (e.g. 85 -> 60 -> 30) remain visible. Last
    HISTORY_MAX_RUNS runs are kept."""
    try:
        history = load_json(history_path)
        if not isinstance(history.get('runs'), list):
            history = {'runs': []}
    except (FileNotFoundError, json.JSONDecodeError, OSError):
        history = {'runs': []}

    history['runs'].append({
        'checked_at': now,
        'skills': {
            name: r['prominence_score']
            for name, r in output['skills'].items()
        },
        'mcps': {
            name: r['prominence_score']
            for name, r in output['mcps'].items()
        }
    })
    history['runs'] = history['runs'][-HISTORY_MAX_RUNS:]
    save_json(history_path, history)


def main():
    project_root = os.path.abspath(
        sys.argv[1] if len(sys.argv) > 1 else '.')

    config_path = os.path.join(
        project_root, '.agents', 'core', 'config.json')
    evidence_path = os.path.join(
        project_root, '.agents', 'orchestration',
        'evidence_report.json')
    snapshot_path = os.path.join(
        project_root, '.agents', 'orchestration',
        'project_snapshot.json')
    output_path = os.path.join(
        project_root, '.agents', 'orchestration',
        'prominence_report.json')
    history_path = os.path.join(
        project_root, '.agents', 'orchestration',
        'prominence_history.json')

    thresholds = dict(DEFAULT_THRESHOLDS)
    if os.path.isfile(config_path):
        try:
            config = load_json(config_path)
            configured = config.get('drift_sensitivity', {}).get(
                'prominence_thresholds', {})
            for key in thresholds:
                value = configured.get(key)
                if isinstance(value, (int, float)):
                    thresholds[key] = value
        except json.JSONDecodeError:
            pass

    try:
        report = load_json(evidence_path)
    except FileNotFoundError:
        print(json.dumps({
            'error': ('evidence_report.json not found. '
                      'Run gather_evidence.py first.')
        }))
        sys.exit(1)
    except json.JSONDecodeError as e:
        print(json.dumps({
            'error': 'Cannot parse evidence_report.json: {0}'.format(e)
        }))
        sys.exit(1)

    try:
        snapshot = load_json(snapshot_path)
    except FileNotFoundError:
        print(json.dumps(
            {'error': 'project_snapshot.json not found.'}))
        sys.exit(1)
    except json.JSONDecodeError as e:
        print(json.dumps({
            'error': 'Cannot parse project_snapshot.json: {0}'.format(e)
        }))
        sys.exit(1)

    now = datetime.now(timezone.utc).isoformat()
    output = {'scored_at': now, 'thresholds_used': thresholds,
              'skills': {}, 'mcps': {}}

    for name, item in report.get('skills', {}).items():
        previous = previous_score_of(
            snapshot, 'skill_justifications', name)
        result = score_item(item, previous, thresholds, now)
        output['skills'][name] = result
        write_back(snapshot, 'skill_justifications', name, result, now)

    for name, item in report.get('mcps', {}).items():
        previous = previous_score_of(
            snapshot, 'mcp_justifications', name)
        result = score_item(item, previous, thresholds, now)
        output['mcps'][name] = result
        write_back(snapshot, 'mcp_justifications', name, result, now)

    save_json(snapshot_path, snapshot)
    save_json(output_path, output)
    append_history(history_path, now, output)

    print(json.dumps({
        'status': 'success',
        'skills_scored': len(output['skills']),
        'mcps_scored': len(output['mcps']),
        'snapshot_updated': snapshot_path,
        'report_written': output_path,
        'history_updated': history_path
    }))
    sys.exit(0)


if __name__ == '__main__':
    main()
