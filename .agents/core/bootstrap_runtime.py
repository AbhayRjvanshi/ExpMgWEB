#!/usr/bin/env python3
"""
bootstrap_runtime.py (v1.0)

Enforces runtime readiness of the .agents directory structure and states:
1. Enforces existence of orchestration/ and drift_reports/ directories.
2. Initializes starter JSON files (skill_registry.json, phase.json, skill_trust_registry.json) if missing.
3. Validates existing JSON orchestration configurations using validate_json.py where schemas are defined.
4. Emits clear next-step instructions based on current orchestration phase.

Usage:
    python .agents/core/bootstrap_runtime.py [project_root]
"""

import json
import os
import sys
import subprocess

def load_json(path):
    with open(path, 'r', encoding='utf-8') as f:
        return json.load(f)

def save_json(path, data):
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(data, f, indent=2)

def main():
    project_root = os.path.abspath(sys.argv[1] if len(sys.argv) > 1 else '.')
    
    orchestration_dir = os.path.join(project_root, '.agents', 'orchestration')
    drift_reports_dir = os.path.join(orchestration_dir, 'drift_reports')
    core_dir = os.path.join(project_root, '.agents', 'core')
    contracts_dir = os.path.join(core_dir, 'contracts')
    validators_dir = os.path.join(core_dir, 'validators')
    
    # 1. Enforce required directories
    os.makedirs(orchestration_dir, exist_ok=True)
    os.makedirs(drift_reports_dir, exist_ok=True)
    
    print("Enforced directory structures: orchestration/, drift_reports/")
    
    # 2. Initialize starter JSON files if missing
    skill_registry_path = os.path.join(orchestration_dir, 'skill_registry.json')
    if not os.path.isfile(skill_registry_path):
        save_json(skill_registry_path, {
            "last_updated": "1970-01-01T00:00:00Z",
            "skills": []
        })
        print("Initialized empty skill_registry.json")
        
    phase_path = os.path.join(orchestration_dir, 'phase.json')
    if not os.path.isfile(phase_path):
        save_json(phase_path, {
            "current_phase": "PHASE_1_DISCOVERY",
            "status": "PENDING",
            "last_error": None,
            "retry_count": 0,
            "max_retries": 3
        })
        print("Initialized phase.json at PHASE_1_DISCOVERY")
        
    trust_registry_path = os.path.join(core_dir, 'skill_trust_registry.json')
    if not os.path.isfile(trust_registry_path):
        save_json(trust_registry_path, {
            "registry_metadata": {
                "version": "1.0.0",
                "last_updated": "1970-01-01T00:00:00Z",
                "registry_type": "skill_trust_registry"
            },
            "trust_levels": {
                "trusted": {
                    "score_range": [8.0, 10.0],
                    "allow_auto_install": False,
                    "requires_human_review": true,
                    "sandbox_required": False
                },
                "restricted": {
                    "score_range": [5.0, 7.9],
                    "allow_auto_install": False,
                    "requires_human_review": true,
                    "sandbox_required": true
                },
                "untrusted": {
                    "score_range": [0.0, 4.9],
                    "allow_auto_install": False,
                    "requires_human_review": true,
                    "sandbox_required": true
                }
            },
            "validation_rules": {
                "require_source_url": true,
                "require_skill_description": true,
                "require_skill_version": true,
                "require_non_empty_policy_section": true,
                "require_non_empty_safety_rules": true,
                "require_non_empty_failure_states": true,
                "require_no_destructive_commands": true,
                "require_no_self_modifying_behavior": true
            },
            "blocked_behaviors": [
                "silent_file_deletion",
                "automatic_shell_execution",
                "self-replication",
                "hidden_network_access",
                "automatic_global_installation",
                "modification_of_existing_skills_without_approval"
            ],
            "compatibility_rules": {
                "require_runtime_declaration": true,
                "require_adapter_hints": true,
                "require_contract_section": true,
                "require_versioning_section": true
            },
            "permission_categories": {
                "filesystem_access": { "requires_explicit_approval": true },
                "network_access": { "requires_explicit_approval": true },
                "shell_execution": { "requires_explicit_approval": true },
                "skill_installation": { "requires_explicit_approval": true }
            },
            "installed_skills": []
        })
        print("Initialized skill_trust_registry.json")
        
    # 3. Validate phase.json if validate_json.py and phase.schema.json exist
    validate_json_script = os.path.join(validators_dir, 'validate_json.py')
    phase_schema = os.path.join(contracts_dir, 'phase.schema.json')
    if os.path.isfile(validate_json_script) and os.path.isfile(phase_schema):
        print("Validating phase.json against contract schema...")
        proc = subprocess.run([sys.executable, validate_json_script, phase_path, phase_schema], capture_output=True, text=True)
        if proc.returncode != 0:
            print(f"Error: phase.json contract validation failed: {proc.stdout.strip() or proc.stderr.strip()}", file=sys.stderr)
            sys.exit(1)
        print("phase.json is valid.")
        
    # 4. Output next step instructions
    try:
        phase_data = load_json(phase_path)
        current_phase = phase_data.get('current_phase', 'PHASE_1_DISCOVERY')
        print(f"\nCurrent Phase: {current_phase}")
        if current_phase == 'PHASE_1_DISCOVERY':
            print("Next Step: Run project-skill-discovery and mcp-plugin-discovery scripts.")
        elif current_phase == 'PHASE_2_ARCHITECT':
            print("Next Step: Run skill-architect script to analyze project requirements and generate skills.")
        elif current_phase == 'PHASE_3_DESIGN':
            print("Next Step: Run design-system-planner and design-evaluator to propose visual assets.")
        elif current_phase == 'PHASE_4_CODE':
            print("Next Step: Run detect_drift.py to perform baseline checking and commence coding tasks.")
        else:
            print(f"Next Step: System is in phase: {current_phase}.")
    except Exception as e:
        print(f"Error reading current phase for next-step generation: {e}", file=sys.stderr)
        
    sys.exit(0)

if __name__ == '__main__':
    main()
