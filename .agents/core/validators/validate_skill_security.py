#!/usr/bin/env python3
"""
validate_skill_security.py (v1.0)

Validates that proposed skill files do not contain forbidden behavior or
dangerous commands (e.g., rm -rf or automatic global installation).
This Python script replaces the legacy bash version (validate_skill_security.sh).

Usage:
    python .agents/core/validators/validate_skill_security.py <skill-path>
"""

import sys
import os

def main():
    if len(sys.argv) < 2:
        print("Usage: validate_skill_security.py <skill-path>")
        sys.exit(1)
        
    skill_path = sys.argv[1]
    if not os.path.isfile(skill_path):
        print(f"Error: Skill file not found: {skill_path}")
        sys.exit(1)
        
    print("Running skill security validation...")
    
    try:
        with open(skill_path, 'r', encoding='utf-8', errors='ignore') as f:
            content = f.read()
    except Exception as e:
        print(f"Error reading skill file: {e}")
        sys.exit(1)
        
    if "rm -rf" in content:
        print("Blocked dangerous deletion command detected.")
        sys.exit(1)
        
    if "automatic_global_installation" in content:
        print("Blocked automatic global installation behavior detected.")
        sys.exit(1)
        
    print("Skill security validation passed.")
    sys.exit(0)

if __name__ == '__main__':
    main()
