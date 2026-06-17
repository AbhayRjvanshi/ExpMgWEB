#!/usr/bin/env python3
"""
generate_impact_brief.py (v1.0)

Generates a 4-part impact brief for skills and MCPs identified as having
LOW or MINIMAL prominence in the project.

Part 1: Usage Map - relative file paths, match counts, and classifications.
Part 2: Original Justification - the plain-text discovery justification.
Part 3: Cost of Removal - difficulty assessment based on usage density and location.
Part 4: Options A/B/C/D - decision matrix for human-in-the-loop review.

Usage:
    python .agents/core/validators/generate_impact_brief.py [project_root]

Exit codes: 0 success / 1 error
"""

import json
import os
import sys
import subprocess
from datetime import datetime, timezone

def load_json(path):
    with open(path, 'r', encoding='utf-8') as f:
        return json.load(f)

def save_json(path, data):
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(data, f, indent=2)

def fail(message):
    print(json.dumps({'error': message}))
    sys.exit(1)

def evaluate_cost_of_removal(name, evidence, is_mcp=False):
    """
    Computes a heuristic cost of removal:
    - Files with matches (from direct_usage)
    - Core logic presence
    - Declared packages count (dependencies)
    - Configuration signals
    """
    direct = evidence.get('direct_usage', {})
    arch = evidence.get('architectural', {})
    dep = evidence.get('dependency', {})
    conf = evidence.get('configuration', {})
    
    unique_files = direct.get('unique_files_count', 0)
    total_matches = direct.get('total_match_count', 0)
    in_core = arch.get('in_core_logic', False)
    packages_count = dep.get('packages_found_count', 0)
    config_signals = conf.get('total_signals', 0)
    
    notes = []
    
    if unique_files == 0 and packages_count == 0 and config_signals == 0:
        difficulty = "MINIMAL"
        description = "No active usage, dependencies, or configuration settings detected in the project."
    elif unique_files <= 2 and not in_core and packages_count == 0:
        difficulty = "LOW"
        description = f"Referenced in {unique_files} peripheral/other file(s) with {total_matches} total matches. No core logic presence."
    elif in_core or unique_files > 5 or packages_count > 0 or config_signals > 0:
        difficulty = "MEDIUM"
        if in_core:
            notes.append("present in core logic files")
        if packages_count > 0:
            notes.append(f"declares {packages_count} package dependency/dependencies")
        if config_signals > 0:
            notes.append(f"has {config_signals} configuration key/value references")
        notes_str = ", ".join(notes)
        description = f"Used in {unique_files} file(s) ({total_matches} matches), {notes_str}."
    else:
        difficulty = "LOW"
        description = f"Used in {unique_files} files ({total_matches} matches) with no core or package dependencies."
        
    return {
        "difficulty": difficulty,
        "description": description,
        "metrics": {
            "files_impacted": unique_files,
            "match_count": total_matches,
            "in_core_logic": in_core,
            "dependencies_found": packages_count,
            "config_signals_found": config_signals
        }
    }

def main():
    project_root = os.path.abspath(sys.argv[1] if len(sys.argv) > 1 else '.')
    
    snapshot_path = os.path.join(project_root, '.agents', 'orchestration', 'project_snapshot.json')
    prominence_path = os.path.join(project_root, '.agents', 'orchestration', 'prominence_report.json')
    evidence_path = os.path.join(project_root, '.agents', 'orchestration', 'evidence_report.json')
    output_path = os.path.join(project_root, '.agents', 'orchestration', 'impact_brief_report.json')
    
    if not os.path.isfile(snapshot_path):
        fail("project_snapshot.json not found. Perform Phase 2 first.")
    if not os.path.isfile(prominence_path):
        fail("prominence_report.json not found. Run score_prominence.py first.")
    if not os.path.isfile(evidence_path):
        fail("evidence_report.json not found. Run gather_evidence.py first.")
        
    snapshot = load_json(snapshot_path)
    prominence = load_json(prominence_path)
    evidence_report = load_json(evidence_path)
    
    briefs = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "candidates": {
            "skills": {},
            "mcps": {}
        }
    }
    
    # Process Skills
    for name, skill_prom in prominence.get('skills', {}).items():
        verdict = skill_prom.get('prominence_verdict')
        if verdict in ['LOW', 'MINIMAL']:
            snapshot_entry = snapshot.get('skill_justifications', {}).get(name, {})
            orig_justification = snapshot_entry.get('discovery_justification', '')
            
            skill_ev = evidence_report.get('skills', {}).get(name, {})
            usage_map = skill_ev.get('evidence', {}).get('direct_usage', {}).get('files_with_matches', [])
            
            cost = evaluate_cost_of_removal(name, skill_ev.get('evidence', {}), is_mcp=False)
            
            briefs["candidates"]["skills"][name] = {
                "prominence_score": skill_prom.get('prominence_score'),
                "prominence_verdict": verdict,
                "usage_map": usage_map,
                "original_justification": orig_justification,
                "cost_of_removal": cost,
                "options": {
                    "A": "Keep as-is (requires user justification; updates confirmed_by_human and human_confirmed_reason)",
                    "B": "Keep but update scope (modify tags or limits in registry/snapshot)",
                    "C": "Replace (regenerate via skill-architect with different requirements)",
                    "D": "Retire (safely remove files, remove from registry and project snapshot after final confirmation)"
                }
            }
            
    # Process MCPs
    for name, mcp_prom in prominence.get('mcps', {}).items():
        verdict = mcp_prom.get('prominence_verdict')
        if verdict in ['LOW', 'MINIMAL']:
            snapshot_entry = snapshot.get('mcp_justifications', {}).get(name, {})
            orig_justification = snapshot_entry.get('discovery_justification', '')
            
            mcp_ev = evidence_report.get('mcps', {}).get(name, {})
            usage_map = mcp_ev.get('evidence', {}).get('direct_usage', {}).get('files_with_matches', [])
            
            cost = evaluate_cost_of_removal(name, mcp_ev.get('evidence', {}), is_mcp=True)
            
            briefs["candidates"]["mcps"][name] = {
                "prominence_score": mcp_prom.get('prominence_score'),
                "prominence_verdict": verdict,
                "usage_map": usage_map,
                "original_justification": orig_justification,
                "cost_of_removal": cost,
                "options": {
                    "A": "Keep as-is (requires user justification)",
                    "B": "Keep but update scope (update evidence or integration settings)",
                    "C": "Replace (regenerate recommendations via mcp-plugin-discovery)",
                    "D": "Retire (trigger retirement process, removing from recommendations and snapshot after final confirmation)"
                }
            }
            
    save_json(output_path, briefs)
    
    # Post-write schema validation
    validate_json_script = os.path.join(project_root, '.agents', 'core', 'validators', 'validate_json.py')
    brief_schema = os.path.join(project_root, '.agents', 'core', 'contracts', 'impact_brief_report.schema.json')
    if os.path.isfile(validate_json_script) and os.path.isfile(brief_schema):
        proc = subprocess.run([sys.executable, validate_json_script, output_path, brief_schema],
                              capture_output=True, text=True, timeout=15)
        if proc.returncode != 0:
            fail(f"Post-write impact brief schema validation failed: {proc.stdout.strip() or proc.stderr.strip()}")
            
    print(json.dumps({
        "status": "success",
        "brief_written": output_path,
        "skills_candidates_count": len(briefs["candidates"]["skills"]),
        "mcps_candidates_count": len(briefs["candidates"]["mcps"])
    }, indent=2))
    sys.exit(0)

if __name__ == '__main__':
    main()
