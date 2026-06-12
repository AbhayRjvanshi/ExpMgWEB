#!/usr/bin/env python3
"""
gather_evidence.py (v1.2)

Gathers multi-category prominence evidence for every skill and MCP listed
in project_snapshot.json. Evidence strategies are loaded from per-tag
profile files in .agents/core/prominence-profiles/.

Pipeline:
    project_snapshot.json + prominence-profiles/
        --> gather_evidence.py
        --> .agents/orchestration/evidence_report.json
        --> score_prominence.py (run next)

Usage:
    python .agents/core/validators/gather_evidence.py [project_root]

Exit codes:
    0   Success - evidence_report.json written
    1   Error (see 'error' field in stdout JSON)

Design notes (v1.2):
- The project tree is walked exactly ONCE. An extension->files index is
  built and reused for every skill/MCP (O(files), not O(skills x files)).
- Pattern matching is restricted to each profile's file_extensions to
  prevent cross-language pattern bleed.
- Domain keywords are matched with word boundaries (case-insensitive) to
  avoid noisy substring hits (e.g. 'db' inside 'feedback').
- Dependency matching is EXACT on package names - no substring guessing.
- Tags with no profile file fall back to a loose keyword strategy so
  unknown tags still produce evidence instead of being silently skipped.
- Scan limits come from config.json drift_sensitivity.prominence and are
  not hardcoded.
- Git does not track empty directories; directory classification is
  file-based inference, which is correct for git-based projects.

ROADMAP (deferred deliberately - see score_prominence.py for rationale):
- AST/token-level parsing to replace substring matching. Deferred to
  preserve dependency-free portability; revisit only if false positives
  are observed in real Layer 2 reviews.
- Negative evidence diffing (dependency/config removals between checks).
  Trend tracking in score_prominence.py covers the cheap version.
- Graphify integration: consume graphify-out/ import/call graphs as an
  optional sixth evidence category instead of building graph analysis
  here from scratch.
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

SCANNABLE_EXTENSIONS = frozenset([
    '.php', '.js', '.mjs', '.ts', '.tsx', '.jsx', '.py', '.rb', '.go',
    '.java', '.cs', '.cpp', '.c', '.vue', '.html', '.htm', '.sql', '.sh'
])

CORE_LOGIC_DIRS = frozenset([
    'src', 'app', 'lib', 'core', 'domain', 'services', 'controllers',
    'models', 'handlers', 'business', 'routes', 'api', 'modules',
    'features', 'middleware', 'includes'
])

PERIPHERAL_DIRS = frozenset([
    'utils', 'helpers', 'test', 'tests', 'spec', 'specs', 'fixtures',
    'mocks', 'migrations', 'seeds', 'scripts', 'docs', 'examples'
])

DEFAULT_LIMITS = {
    'commit_history_days': 45,
    'max_files_to_scan': 2000,
    'max_file_size_bytes': 500000
}

CONTENT_CACHE_MAX_ENTRIES = 600

_CONTENT_CACHE = {}


def load_json(path):
    with open(path, 'r', encoding='utf-8') as f:
        return json.load(f)


def save_json(path, data):
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(data, f, indent=2)


def get_limits(config):
    """Read scan limits from config.json drift_sensitivity.prominence.
    Falls back to DEFAULT_LIMITS for any missing or invalid value."""
    prominence = config.get('drift_sensitivity', {}).get('prominence', {})
    limits = dict(DEFAULT_LIMITS)
    for key in limits:
        value = prominence.get(key)
        if isinstance(value, int) and value > 0:
            limits[key] = value
    return limits


def walk_project(project_root, max_files, max_size_bytes):
    """Walk the project tree exactly once.
    Returns (all_files, ext_index) where ext_index maps '.ext' -> [paths].
    """
    all_files = []
    ext_index = {}
    for dirpath, dirnames, filenames in os.walk(project_root):
        dirnames[:] = [
            d for d in dirnames
            if d not in SKIP_DIRS and not d.startswith('.')
        ]
        for filename in filenames:
            _, ext = os.path.splitext(filename)
            ext = ext.lower()
            if ext not in SCANNABLE_EXTENSIONS:
                continue
            filepath = os.path.join(dirpath, filename)
            try:
                if os.path.getsize(filepath) > max_size_bytes:
                    continue
            except OSError:
                continue
            all_files.append(filepath)
            ext_index.setdefault(ext, []).append(filepath)
            if len(all_files) >= max_files:
                return all_files, ext_index
    return all_files, ext_index


def read_file_cached(filepath):
    """Read file content with a bounded in-memory cache so multiple
    skills scanning the same files do not re-read from disk."""
    if filepath in _CONTENT_CACHE:
        return _CONTENT_CACHE[filepath]
    try:
        with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
            content = f.read()
    except (OSError, IOError):
        content = ''
    if len(_CONTENT_CACHE) < CONTENT_CACHE_MAX_ENTRIES:
        _CONTENT_CACHE[filepath] = content
    return content


def classify_file(filepath, project_root):
    """Classify a file as 'core', 'peripheral', or 'other' based on the
    directories in its relative path. First matching directory wins,
    checked from the project root outward."""
    rel = os.path.relpath(filepath, project_root)
    parts = rel.replace('\\', '/').split('/')
    for part in parts[:-1]:
        part_lower = part.lower()
        if part_lower in CORE_LOGIC_DIRS:
            return 'core'
        if part_lower in PERIPHERAL_DIRS:
            return 'peripheral'
    if len(parts) == 1:
        return 'peripheral'
    return 'other'


def tag_to_profile_filename(tag):
    """Convert a discovery_evidence tag to its profile filename.
    'database:mysql'  -> 'database_mysql.json'
    'file_type:.sql'  -> 'file_type_sql.json'
    """
    return tag.replace(':', '_').replace('.', '').lower() + '.json'


def empty_strategy():
    return {
        'code_patterns': [],
        'string_patterns': [],
        'domain_keywords': [],
        'file_extensions': [],
        'exception_patterns': [],
        'composer_packages': [],
        'npm_packages': [],
        'pip_packages': [],
        'env_key_patterns': [],
        'env_value_patterns': [],
        'docker_service_names': [],
        'commit_keywords': []
    }


def _extend_unique(target, items):
    for item in items:
        if item not in target:
            target.append(item)


def merge_profile_into(strategy, profile):
    """Merge one profile file's evidence_strategies into a strategy dict."""
    strategies = profile.get('evidence_strategies', {})
    direct = strategies.get('direct_usage', {})
    arch = strategies.get('architectural', {})
    dep = strategies.get('dependency', {})
    conf = strategies.get('configuration', {})

    _extend_unique(strategy['code_patterns'],
                   direct.get('code_patterns', []))
    _extend_unique(strategy['string_patterns'],
                   direct.get('string_patterns', []))
    _extend_unique(strategy['file_extensions'],
                   direct.get('file_extensions', []))
    _extend_unique(strategy['exception_patterns'],
                   arch.get('exception_patterns', []))
    _extend_unique(strategy['composer_packages'],
                   dep.get('composer_packages', []))
    _extend_unique(strategy['npm_packages'],
                   dep.get('npm_packages', []))
    _extend_unique(strategy['pip_packages'],
                   dep.get('pip_packages', []))
    _extend_unique(strategy['env_key_patterns'],
                   conf.get('env_key_patterns', []))
    _extend_unique(strategy['env_value_patterns'],
                   conf.get('env_value_patterns', []))
    _extend_unique(strategy['docker_service_names'],
                   conf.get('docker_service_names', []))
    _extend_unique(strategy['commit_keywords'],
                   strategies.get('commit_keywords', []))


def resolve_tags(tags, profiles_dir, domain_keywords_map,
                 domain_default_exts):
    """Resolve discovery_evidence tags into one merged strategy.

    Resolution order per tag:
      1. Dedicated profile file (e.g. database_mysql.json)
      2. domain:<name> -> shared domain_keywords.json lookup
      3. Fallback: keyword extracted from the tag value (word-boundary
         matched) + commit keyword. file_type tags add the extension.

    Returns (strategy, tags_resolved, tags_unresolved).
    """
    strategy = empty_strategy()
    tags_resolved = []
    tags_unresolved = []

    for tag in tags:
        profile_path = os.path.join(
            profiles_dir, tag_to_profile_filename(tag))
        profile = None
        if os.path.isfile(profile_path):
            try:
                profile = load_json(profile_path)
            except (json.JSONDecodeError, OSError):
                profile = None

        if profile is not None:
            merge_profile_into(strategy, profile)
            tags_resolved.append(tag)
            continue

        category, _, value = tag.partition(':')
        category = category.strip().lower()
        value = value.strip().lower()

        if category == 'domain' and value in domain_keywords_map:
            _extend_unique(strategy['domain_keywords'],
                           domain_keywords_map[value])
            _extend_unique(strategy['commit_keywords'],
                           domain_keywords_map[value])
            _extend_unique(strategy['file_extensions'],
                           domain_default_exts)
            tags_resolved.append(tag)
            continue

        # Fallback for unknown tags - never silently skip
        tags_unresolved.append(tag)
        if category == 'file_type' and value.startswith('.'):
            _extend_unique(strategy['file_extensions'], [value])
            continue
        keyword = value.replace('.', '').strip()
        if keyword:
            _extend_unique(strategy['domain_keywords'], [keyword])
            _extend_unique(strategy['commit_keywords'], [keyword])
            _extend_unique(strategy['file_extensions'],
                           domain_default_exts)

    return strategy, tags_resolved, tags_unresolved


def candidate_files_for(strategy, ext_index):
    """Files eligible for pattern scanning: only those whose extension is
    declared by the merged strategy. Prevents cross-language bleed."""
    files = []
    seen = set()
    for ext in strategy['file_extensions']:
        for filepath in ext_index.get(ext.lower(), []):
            if filepath not in seen:
                seen.add(filepath)
                files.append(filepath)
    return files


def gather_direct_usage(strategy, ext_index, project_root):
    """Category 1 - Direct usage.
    code_patterns: case-sensitive substring count
    string_patterns: case-insensitive substring count
    domain_keywords: word-boundary regex, case-insensitive
    """
    code_patterns = strategy['code_patterns']
    string_patterns = strategy['string_patterns']
    keyword_regexes = [
        (kw, re.compile(r'\b' + re.escape(kw) + r'\b', re.IGNORECASE))
        for kw in strategy['domain_keywords']
    ]

    candidates = candidate_files_for(strategy, ext_index)

    extension_counts = {
        ext: len(ext_index.get(ext.lower(), []))
        for ext in strategy['file_extensions']
        if len(ext_index.get(ext.lower(), [])) > 0
    }

    files_with_matches = []
    total_match_count = 0
    patterns_matched = set()
    core_count = 0
    peripheral_count = 0

    has_patterns = bool(
        code_patterns or string_patterns or keyword_regexes)

    if has_patterns:
        for filepath in candidates:
            content = read_file_cached(filepath)
            if not content:
                continue
            content_lower = content.lower()
            match_count = 0

            for pattern in code_patterns:
                count = content.count(pattern)
                if count:
                    match_count += count
                    patterns_matched.add(pattern)

            for pattern in string_patterns:
                count = content_lower.count(pattern.lower())
                if count:
                    match_count += count
                    patterns_matched.add(pattern)

            for kw, regex in keyword_regexes:
                count = len(regex.findall(content))
                if count:
                    match_count += count
                    patterns_matched.add(kw)

            if match_count == 0:
                continue

            classification = classify_file(filepath, project_root)
            files_with_matches.append({
                'path': os.path.relpath(filepath, project_root),
                'match_count': match_count,
                'classification': classification
            })
            total_match_count += match_count
            if classification == 'core':
                core_count += 1
            elif classification == 'peripheral':
                peripheral_count += 1

    return {
        'files_with_matches': files_with_matches,
        'unique_files_count': len(files_with_matches),
        'total_match_count': total_match_count,
        'patterns_matched': sorted(patterns_matched),
        'extension_counts': extension_counts,
        'core_file_count': core_count,
        'peripheral_file_count': peripheral_count,
        'core_files_sample': [
            f['path'] for f in files_with_matches
            if f['classification'] == 'core'
        ][:5]
    }, candidates


def gather_architectural(direct_result, candidates, strategy,
                         project_root):
    """Category 2 - Architectural signals.
    Mostly derived from direct usage; also checks whether the codebase
    contains exception handling specific to this technology."""
    files = direct_result['files_with_matches']
    total = len(files)
    core_count = direct_result['core_file_count']
    core_ratio = round(core_count / total, 2) if total > 0 else 0.0

    top_dirs = set()
    for f in files:
        parts = f['path'].replace('\\', '/').split('/')
        if len(parts) > 1:
            top_dirs.add(parts[0])

    exception_found = False
    exception_sample_file = None
    exception_patterns = strategy['exception_patterns']
    if exception_patterns:
        for filepath in candidates:
            content = read_file_cached(filepath)
            if content and any(
                    p in content for p in exception_patterns):
                exception_found = True
                exception_sample_file = os.path.relpath(
                    filepath, project_root)
                break

    return {
        'total_files_with_usage': total,
        'in_core_logic': core_count > 0,
        'core_file_count': core_count,
        'core_ratio': core_ratio,
        'distinct_directories': sorted(top_dirs),
        'directory_spread': len(top_dirs),
        'exception_handling_found': exception_found,
        'exception_sample_file': exception_sample_file
    }


def _pip_package_name(line):
    """Extract the bare package name from a requirements.txt line."""
    line = line.strip()
    if not line or line.startswith('#') or line.startswith('-'):
        return None
    return re.split(r'[<>=!~\[; ]', line, 1)[0].strip().lower() or None


def gather_dependency(project_root, strategy):
    """Category 3 - Dependency signals. EXACT package-name matching."""
    found_packages = []

    composer_targets = strategy['composer_packages']
    if composer_targets:
        composer_path = os.path.join(project_root, 'composer.json')
        if os.path.isfile(composer_path):
            try:
                data = load_json(composer_path)
                installed = {}
                installed.update(data.get('require', {}))
                installed.update(data.get('require-dev', {}))
                for target in composer_targets:
                    if target in installed:
                        found_packages.append(
                            {'type': 'composer', 'package': target})
            except (json.JSONDecodeError, OSError):
                pass

    npm_targets = strategy['npm_packages']
    if npm_targets:
        package_path = os.path.join(project_root, 'package.json')
        if os.path.isfile(package_path):
            try:
                data = load_json(package_path)
                installed = {}
                installed.update(data.get('dependencies', {}))
                installed.update(data.get('devDependencies', {}))
                for target in npm_targets:
                    if target == '*':
                        found_packages.append({
                            'type': 'npm',
                            'package': 'package.json present'
                        })
                    elif target in installed:
                        found_packages.append(
                            {'type': 'npm', 'package': target})
            except (json.JSONDecodeError, OSError):
                pass

    pip_targets = [p.lower() for p in strategy['pip_packages']]
    if pip_targets:
        req_path = os.path.join(project_root, 'requirements.txt')
        if os.path.isfile(req_path):
            try:
                with open(req_path, 'r', encoding='utf-8') as f:
                    names = {
                        _pip_package_name(line) for line in f
                    } - {None}
                for target in pip_targets:
                    if target in names:
                        found_packages.append(
                            {'type': 'pip', 'package': target})
            except OSError:
                pass

    return {
        'packages_found': found_packages,
        'packages_found_count': len(found_packages)
    }


def gather_configuration(project_root, strategy):
    """Category 4 - Configuration signals (.env keys/values, docker
    services). Configuration reflects actual deployed reality."""
    env_key_patterns = strategy['env_key_patterns']
    env_value_patterns = strategy['env_value_patterns']
    docker_service_names = strategy['docker_service_names']

    env_keys_found = []
    env_values_matched = []
    docker_services_found = []

    for env_filename in ['.env', '.env.example', '.env.local']:
        env_path = os.path.join(project_root, env_filename)
        if not os.path.isfile(env_path):
            continue
        try:
            with open(env_path, 'r', encoding='utf-8',
                      errors='ignore') as f:
                for line in f:
                    line = line.strip()
                    if (not line or line.startswith('#')
                            or '=' not in line):
                        continue
                    key, _, value = line.partition('=')
                    key = key.strip().upper()
                    value = value.strip().lower()
                    for pattern in env_key_patterns:
                        p_upper = pattern.upper()
                        if key == p_upper or key.startswith(p_upper):
                            if key not in env_keys_found:
                                env_keys_found.append(key)
                    for pattern in env_value_patterns:
                        if pattern.lower() in value:
                            if pattern not in env_values_matched:
                                env_values_matched.append(pattern)
        except OSError:
            pass
        break  # only the first env file found is read

    for dc_filename in ['docker-compose.yml', 'docker-compose.yaml']:
        dc_path = os.path.join(project_root, dc_filename)
        if not os.path.isfile(dc_path):
            continue
        try:
            with open(dc_path, 'r', encoding='utf-8') as f:
                content = f.read().lower()
            for service in docker_service_names:
                if re.search(
                        r'\b' + re.escape(service.lower()) + r'\b',
                        content):
                    if service not in docker_services_found:
                        docker_services_found.append(service)
        except OSError:
            pass
        break

    total = (len(env_keys_found) + len(env_values_matched)
             + len(docker_services_found))

    return {
        'env_keys_found': env_keys_found,
        'env_values_matched': env_values_matched,
        'docker_services_found': docker_services_found,
        'total_signals': total
    }


def gather_commit_history(project_root, keywords, window_days):
    """Category 5 - Commit history signals. Word-boundary keyword
    matching against recent commit subjects (merges excluded)."""
    base = {
        'searched': False,
        'recent_commits_count': 0,
        'total_commits_in_window': 0,
        'keywords_found': [],
        'commit_window_days': window_days
    }
    if not keywords:
        return base

    try:
        result = subprocess.run(
            ['git', 'log',
             '--since={0} days ago'.format(window_days),
             '--format=%s', '--no-merges'],
            cwd=project_root, capture_output=True, text=True,
            timeout=20
        )
    except (subprocess.TimeoutExpired, FileNotFoundError) as e:
        base['error'] = 'git unavailable or timed out: {0}'.format(e)
        return base

    if result.returncode != 0:
        base['error'] = result.stderr.strip()
        return base

    base['searched'] = True
    lines = [l for l in result.stdout.splitlines() if l.strip()]
    base['total_commits_in_window'] = len(lines)
    if not lines:
        return base

    keyword_regexes = [
        (kw, re.compile(r'\b' + re.escape(kw.lower()) + r'\b'))
        for kw in keywords
    ]

    keywords_found = set()
    matching_commits = 0
    for line in lines:
        line_lower = line.lower()
        hit = False
        for kw, regex in keyword_regexes:
            if regex.search(line_lower):
                keywords_found.add(kw)
                hit = True
        if hit:
            matching_commits += 1

    base['recent_commits_count'] = matching_commits
    base['keywords_found'] = sorted(keywords_found)
    return base


def compute_evidence_missing(evidence):
    """List the categories that produced zero signal. Consumed by
    score_prominence.py (confidence) and generate_impact_brief.py."""
    missing = []
    direct = evidence['direct_usage']
    if (direct['unique_files_count'] == 0
            and sum(direct['extension_counts'].values()) == 0):
        missing.append('direct_usage')
    if evidence['architectural']['total_files_with_usage'] == 0:
        missing.append('architectural')
    if evidence['dependency']['packages_found_count'] == 0:
        missing.append('dependency')
    if evidence['configuration']['total_signals'] == 0:
        missing.append('configuration')
    if evidence['commit_history']['recent_commits_count'] == 0:
        missing.append('commit_history')
    return missing


def gather_for_item(justification, profiles_dir, domain_keywords_map,
                    domain_default_exts, ext_index, project_root,
                    window_days):
    """Run all 5 evidence categories for one skill or MCP."""
    tags = justification.get('discovery_evidence', [])
    strategy, tags_resolved, tags_unresolved = resolve_tags(
        tags, profiles_dir, domain_keywords_map, domain_default_exts
    )

    direct, candidates = gather_direct_usage(
        strategy, ext_index, project_root)
    architectural = gather_architectural(
        direct, candidates, strategy, project_root)
    dependency = gather_dependency(project_root, strategy)
    configuration = gather_configuration(project_root, strategy)
    commit_history = gather_commit_history(
        project_root, strategy['commit_keywords'], window_days)

    evidence = {
        'direct_usage': direct,
        'architectural': architectural,
        'dependency': dependency,
        'configuration': configuration,
        'commit_history': commit_history
    }

    return {
        'discovery_evidence_tags': tags,
        'tags_resolved': tags_resolved,
        'tags_unresolved': tags_unresolved,
        'evidence': evidence,
        'evidence_missing': compute_evidence_missing(evidence)
    }


def main():
    project_root = os.path.abspath(
        sys.argv[1] if len(sys.argv) > 1 else '.')

    config_path = os.path.join(
        project_root, '.agents', 'core', 'config.json')
    profiles_dir = os.path.join(
        project_root, '.agents', 'core', 'prominence-profiles')
    snapshot_path = os.path.join(
        project_root, '.agents', 'orchestration',
        'project_snapshot.json')
    output_path = os.path.join(
        project_root, '.agents', 'orchestration',
        'evidence_report.json')

    try:
        snapshot = load_json(snapshot_path)
    except FileNotFoundError:
        print(json.dumps({
            'error': ('project_snapshot.json not found. '
                      'Run Phase 2 first.')
        }))
        sys.exit(1)
    except json.JSONDecodeError as e:
        print(json.dumps({
            'error': 'Cannot parse project_snapshot.json: {0}'.format(e)
        }))
        sys.exit(1)

    config = {}
    if os.path.isfile(config_path):
        try:
            config = load_json(config_path)
        except json.JSONDecodeError:
            config = {}
    limits = get_limits(config)

    domain_keywords_map = {}
    domain_default_exts = ['.php', '.js', '.py', '.ts']
    dk_path = os.path.join(profiles_dir, 'domain_keywords.json')
    if os.path.isfile(dk_path):
        try:
            dk = load_json(dk_path)
            domain_keywords_map = dk.get('domains', {})
            domain_default_exts = dk.get(
                'file_extensions', domain_default_exts)
        except (json.JSONDecodeError, OSError):
            pass

    print('Scanning project files...', file=sys.stderr)
    all_files, ext_index = walk_project(
        project_root, limits['max_files_to_scan'],
        limits['max_file_size_bytes'])
    print('Found {0} scannable files.'.format(len(all_files)),
          file=sys.stderr)

    report = {
        'generated_at': datetime.now(timezone.utc).isoformat(),
        'project_root': project_root,
        'files_scanned': len(all_files),
        'limits_used': limits,
        'skills': {},
        'mcps': {}
    }

    for name, justification in snapshot.get(
            'skill_justifications', {}).items():
        print('Gathering evidence: skill {0}'.format(name),
              file=sys.stderr)
        report['skills'][name] = gather_for_item(
            justification, profiles_dir, domain_keywords_map,
            domain_default_exts, ext_index, project_root,
            limits['commit_history_days'])

    for name, justification in snapshot.get(
            'mcp_justifications', {}).items():
        print('Gathering evidence: MCP {0}'.format(name),
              file=sys.stderr)
        report['mcps'][name] = gather_for_item(
            justification, profiles_dir, domain_keywords_map,
            domain_default_exts, ext_index, project_root,
            limits['commit_history_days'])

    save_json(output_path, report)
    print(json.dumps({
        'status': 'success',
        'output': output_path,
        'files_scanned': len(all_files),
        'skills_analyzed': len(report['skills']),
        'mcps_analyzed': len(report['mcps'])
    }))
    sys.exit(0)


if __name__ == '__main__':
    main()
