#!/usr/bin/env python3
"""
validate_design_md.py (v1.0)

Validates that DESIGN.md's extracted structure matches design_md.schema.json.
This Python script replaces the legacy bash version (validate_design_md.sh).

Usage:
    python .agents/core/validators/validate_design_md.py <design-md-json>
"""

import sys
import os
import subprocess

def main():
    if len(sys.argv) < 2:
        print("Usage: validate_design_md.py <design-md-json>")
        sys.exit(1)
    
    design_md_json = sys.argv[1]
    project_root = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..'))
    schema_path = os.path.join(project_root, '.agents', 'core', 'contracts', 'design_md.schema.json')
    validate_json_script = os.path.join(project_root, '.agents', 'core', 'validators', 'validate_json.py')
    
    print("Validating DESIGN.md extracted structure...")
    
    if not os.path.isfile(validate_json_script):
        print(f"Error: validate_json.py not found at {validate_json_script}")
        sys.exit(1)
        
    proc = subprocess.run([sys.executable, validate_json_script, design_md_json, schema_path])
    if proc.returncode != 0:
        print("DESIGN.md validation failed.")
        sys.exit(1)
        
    print("DESIGN.md validation passed.")
    sys.exit(0)

if __name__ == '__main__':
    main()
