#!/usr/bin/env python3
"""
validate_policy.py (v1.0)

Validates that no blocklisted runtime-specific tools or commands appear
in the ## POLICY section of any SKILL.md files. This keeps skill policies
fully portable and environment-agnostic.

Usage:
    python .agents/core/validators/validate_policy.py <skills_dir> <blocklist_file>
"""

import sys
import re
from pathlib import Path

def validate_skill_policy(skill_file, blocklist):
    """
    Extracts the '## POLICY' section of a SKILL.md file and checks if any
    of the blocklisted terms appear as distinct words.
    """
    try:
        content = Path(skill_file).read_text(encoding='utf-8')
    except Exception as e:
        print(f"Error reading {skill_file}: {e}", file=sys.stderr)
        return 0

    # Match everything between '## POLICY' and the next '## ' header or end of file
    match = re.search(r'## POLICY(.*?)(## |$)', content, re.DOTALL)
    if not match:
        return 0

    policy_text = match.group(1)
    violations = 0

    for term in blocklist:
        term = term.strip()
        if not term or term.startswith('#'):
            continue
        
        # Match term with word boundaries (using \b)
        pattern = r'\b' + re.escape(term) + r'\b'
        if re.search(pattern, policy_text):
            print(f"Violation: Forbidden term '{term}' found in {skill_file}", file=sys.stderr)
            violations += 1

    return violations

def main():
    if len(sys.argv) < 3:
        print("Usage: validate_policy.py <skills_dir> <blocklist_file>", file=sys.stderr)
        sys.exit(1)

    skills_dir = Path(sys.argv[1])
    blocklist_file = Path(sys.argv[2])

    if not skills_dir.exists():
        print(f"Error: Skills directory not found: {skills_dir}", file=sys.stderr)
        sys.exit(1)

    if not blocklist_file.is_file():
        print(f"Error: Blocklist file not found: {blocklist_file}", file=sys.stderr)
        sys.exit(1)

    try:
        blocklist = blocklist_file.read_text(encoding='utf-8').splitlines()
    except Exception as e:
        print(f"Error reading blocklist file: {e}", file=sys.stderr)
        sys.exit(1)

    total_violations = 0
    for file_path in skills_dir.rglob('SKILL.md'):
        violations = validate_skill_policy(file_path, blocklist)
        total_violations += violations

    if total_violations > 0:
        print(f"Policy validation failed: {total_violations} total violations found.", file=sys.stderr)
        sys.exit(1)

    print("Policy validation passed.")
    sys.exit(0)

if __name__ == '__main__':
    main()