#!/usr/bin/env python3
"""
generate_snapshot.py (v1.1)

Captures the Phase 2 baseline snapshot consumed by the entire drift
detection system. Deterministic mechanical capture only - human
confirmation of justifications is the skill-architect agent's job.

Usage:
    python .agents/core/validators/generate_snapshot.py [project_root] [--regenerate]

Exit codes: 0 success / 1 error
"""

import json
import os
import subprocess
import sys
from datetime import datetime, timezone

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

CANONICAL_DOMAINS = frozenset([
    'database', 'authentication', 'file-management', 'email', 'payment',
    'api', 'testing', 'caching', 'logging', 'deployment', 'storage',
    'messaging', 'search', 'media', 'finance', 'settlement', 'async',
    'notifications'
])

def load_json(path):
    with open(path, 'r', encoding='utf-8') as f:
        return json.load(f)

def save_json(path, data):
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(data, f, indent=2)

def fail(message):
    print(json.dumps({'error': message}))
    sys.exit(1)

def git_head(project_root):
    try:
        proc = subprocess.run(
            ['git', 'rev-parse', 'HEAD'],
            cwd=project_root, capture_output=True, text=True, timeout=10)
        if proc.returncode == 0:
            return proc.stdout.strip()
    except (subprocess.TimeoutExpired, FileNotFoundError):
        pass
    return None

def scan_tree(project_root, drift_extensions):
    ext_counts = {}
    top_dirs = set()
    total = 0
    for dirpath, dirnames, filenames in os.walk(project_root, followlinks=False):
        dirnames[:] = [
            d for d in dirnames
            if d not in SKIP_DIRS and not d.startswith('.')
        ]
        if os.path.abspath(dirpath) == project_root:
            top_dirs.update(dirnames)
        for filename in filenames:
            ext = os.path.splitext(filename)[1].lower()
            if ext not in drift_extensions:
                continue
            ext_counts[ext] = ext_counts.get(ext, 0) + 1
            total += 1
    return ext_counts, sorted(top_dirs), total

def canonicalize_tag(tag, stack):
    t = tag.strip().lower()
    if t.startswith('.'):
        return 'file_type:' + t
    if ':' in t:
        return t
    if t == stack.get('language', '').lower():
        return 'language:' + t
    if t == stack.get('database', '').lower():
        return 'database:' + t
    if t == stack.get('framework', '').lower():
        return 'framework:' + t
    if t in CANONICAL_DOMAINS:
        return 'domain:' + t
    return 'domain:' + t

def seed_skill_justifications(registry, requirements, stack, existing):
    registry_names = {s.get('name') for s in registry.get('skills', []) if s.get('name')}
    justifications = {}
    for name, entry in existing.items():
        if name in registry_names:
            justifications[name] = entry
        else:
            orphaned = dict(entry)
            orphaned['orphaned'] = True
            justifications[name] = orphaned
    phase1_reasons = {
        s.get('skill_name'): s.get('justification', '')
        for s in requirements.get('required_skills', [])
    }
    for skill in registry.get('skills', []):
        name = skill.get('name')
        if not name or name in justifications:
            continue
        tags = skill.get('tags', [])
        evidence = sorted({canonicalize_tag(t, stack) for t in tags})
        justifications[name] = {
            'discovery_evidence': evidence,
            'discovery_justification': phase1_reasons.get(name, ''),
            'confirmed_by_human': False,
            'confirmed_at': None,
            'prominence_score': None,
            'prominence_verdict': None,
            'last_checked_at': None,
        }
    return justifications

def seed_mcp_justifications(recommendations, existing):
    justifications = dict(existing)
    for mcp in recommendations.get('recommended_mcps', []):
        name = mcp.get('mcp_name')
        if not name or name in justifications:
            continue
        category = mcp.get('category', '').lower()
        evidence = ['domain:' + category] if category else []
        justifications[name] = {
            'discovery_evidence': evidence,
            'discovery_justification': mcp.get('justification', ''),
            'confirmed_by_human': False,
            'confirmed_at': None,
            'prominence_score': None,
            'prominence_verdict': None,
            'last_checked_at': None,
        }
    return justifications

def main():
    args = sys.argv[1:]
    regenerate = '--regenerate' in args
    args = [a for a in args if a != '--regenerate']
    project_root = os.path.abspath(args[0]) if args else os.path.abspath('.')

    config_path = os.path.join(project_root, '.agents', 'core', 'config.json')
    req_path = os.path.join(project_root, '.agents', 'orchestration', 'skill_requirements.json')
    registry_path = os.path.join(project_root, '.agents', 'orchestration', 'skill_registry.json')
    mcp_path = os.path.join(project_root, '.agents', 'orchestration', 'mcp_recommendations.json')
    snapshot_path = os.path.join(project_root, '.agents', 'orchestration', 'project_snapshot.json')

    if not os.path.isfile(req_path):
        fail('skill_requirements.json not found. Run Phase 1 first.')

    requirements = load_json(req_path)
    registry = load_json(registry_path) if os.path.isfile(registry_path) else {}
    mcp_recs = load_json(mcp_path) if os.path.isfile(mcp_path) else {}
    
    try:
        config = load_json(config_path)
        exts = config.get('drift_sensitivity', {}).get('drift_extensions')
        if exts and isinstance(exts, list):
            drift_extensions = frozenset(e.strip().lower() for e in exts if isinstance(e, str))
        else:
            drift_extensions = _DRIFT_EXTENSIONS_FALLBACK
    except (FileNotFoundError, json.JSONDecodeError):
        drift_extensions = _DRIFT_EXTENSIONS_FALLBACK

    existing = {}
    if os.path.isfile(snapshot_path):
        if not regenerate:
            fail('project_snapshot.json already exists. Re-run with '
                 '--regenerate to update it while preserving justifications.')
        existing = load_json(snapshot_path)

    ext_counts, top_dirs, total = scan_tree(project_root, drift_extensions)
    head = git_head(project_root)

    stack = requirements.get('detected_stack', {})
    source = requirements.get('source', 'codebase_scan')
    
    existing_skills = existing.get('skill_justifications', {})
    existing_mcps = existing.get('mcp_justifications', {})

    new_skills = seed_skill_justifications(registry, requirements, stack, existing_skills)
    new_mcps = seed_mcp_justifications(mcp_recs, existing_mcps)

    snapshot = {
        'captured_at': datetime.now(timezone.utc).isoformat(),
        'captured_by_phase': 'PHASE_2_ARCHITECT',
        'source': source,
        'file_counts': ext_counts,
        'total_file_count': total,
        'top_directories': top_dirs,
        'domains': requirements.get('detected_domains', []),
        'stack': stack,
        'skill_justifications': new_skills,
        'mcp_justifications': new_mcps,
        'last_drift_check': None,
        'accumulated_commit_weight': 0,
        'drift_check_count': 0,
        'last_analyzed_commit': head,
    }

    save_json(snapshot_path, snapshot)
    
    # Best-effort validation
    validator = os.path.join(project_root, '.agents', 'core', 'validators', 'validate_json.py')
    schema = os.path.join(project_root, '.agents', 'core', 'contracts', 'project_snapshot.schema.json')
    validation = 'skipped'
    if os.path.isfile(validator) and os.path.isfile(schema):
        try:
            proc = subprocess.run(
                [sys.executable, validator, snapshot_path, schema],
                capture_output=True, text=True, timeout=15)
            validation = 'passed' if proc.returncode == 0 else 'failed'
        except (subprocess.TimeoutExpired, OSError):
            validation = 'skipped'
        
    unconfirmed = [
        name for name, s in new_skills.items() if not s.get('confirmed_by_human')
    ] + [
        name for name, m in new_mcps.items() if not m.get('confirmed_by_human')
    ]

    print(json.dumps({
        'status': 'success',
        'mode': 'regenerate' if regenerate else 'create',
        'skills_seeded': len(new_skills) - len(existing_skills),
        'skills_preserved': len(existing_skills),
        'unconfirmed_justifications': unconfirmed,
        'schema_validation': validation
    }, indent=2))

if __name__ == '__main__':
    main()
