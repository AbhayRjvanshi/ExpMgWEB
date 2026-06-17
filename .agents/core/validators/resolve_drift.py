#!/usr/bin/env python3
"""
resolve_drift.py (v1.0)

Automates the mechanical steps of resolving an active drift report:
1. Verifies that all questions in drift_report.json are answered and have non-empty answers.
2. Validates exceptions against status and report signals before any writes.
3. Updates resolved_at to the current timestamp.
4. Saves the resolved drift_report.json and its archive copy.
5. Invokes validate_drift_resolution.py to verify parity and correctness.
6. Mutates the project snapshot (resets accumulated_commit_weight to 0) conditionally if status is resolved_exceptions_noted.
7. Validates the mutated snapshot against its schema.

Usage:
    python .agents/core/validators/resolve_drift.py [project_root] --status STATUS [--exceptions EXC1,EXC2]
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

def main():
    args = sys.argv[1:]
    
    # Parse options
    status_override = None
    exceptions_input = None
    cleaned_args = []
    
    i = 0
    while i < len(args):
        if args[i] == '--status':
            if i + 1 < len(args):
                status_override = args[i+1]
                i += 2
            else:
                fail("Missing value for --status option.")
        elif args[i] == '--exceptions':
            if i + 1 < len(args):
                exceptions_input = args[i+1]
                i += 2
            else:
                fail("Missing value for --exceptions option.")
        else:
            cleaned_args.append(args[i])
            i += 1
            
    project_root = os.path.abspath(cleaned_args[0] if cleaned_args else '.')
    
    latest_path = os.path.join(project_root, '.agents', 'orchestration', 'drift_report.json')
    snapshot_path = os.path.join(project_root, '.agents', 'orchestration', 'project_snapshot.json')
    reports_dir = os.path.join(project_root, '.agents', 'orchestration', 'drift_reports')
    
    if not os.path.isfile(latest_path):
        fail(f"Drift report not found at {latest_path}. Run detect_drift.py first.")
        
    if not os.path.isfile(snapshot_path):
        fail(f"Snapshot not found at {snapshot_path}.")
        
    report = load_json(latest_path)
    snapshot = load_json(snapshot_path)
    
    # Apply status override if provided
    status = status_override or report.get('status', 'pending_user_response')
    
    if status == 'pending_user_response':
        fail("Cannot resolve report: status is 'pending_user_response'. Please specify a resolved status (resolved_rerun, resolved_no_action, resolved_exceptions_noted).")
        
    if status not in ('resolved_rerun', 'resolved_no_action', 'resolved_exceptions_noted'):
        fail(f"Invalid status: '{status}'. Valid statuses: resolved_rerun, resolved_no_action, resolved_exceptions_noted.")

    # Guard --exceptions by status (Fix 2)
    if exceptions_input and status not in ('resolved_exceptions_noted', 'resolved_rerun'):
        fail("--exceptions can only be used with --status resolved_exceptions_noted or resolved_rerun.")
        
    # Process and validate provided exceptions (Fix 3)
    provided_exceptions = []
    if exceptions_input:
        provided_exceptions = [e.strip() for e in exceptions_input.split(',') if e.strip()]
        
        question_signals = {q.get('signal') for q in report.get('user_questions', [])}
        for exc in provided_exceptions:
            if exc not in question_signals:
                fail(f"Exception signal '{exc}' does not match any user_questions[].signal in this report. "
                     f"Valid signals: {sorted(question_signals)}")

    # Ensure all questions are answered (Fix 1)
    for index, q in enumerate(report.get('user_questions', [])):
        answer = q.get('user_answer')
        if not q.get('answered') or answer is None or (isinstance(answer, str) and not answer.strip()):
            fail(f"Question {index} (signal: {q.get('signal')}) is unanswered or missing user_answer.")

    # If status is resolved_exceptions_noted, check that there are exceptions (either provided or already present)
    report_exceptions = report.get('user_exceptions', [])
    if status == 'resolved_exceptions_noted' and not provided_exceptions and not report_exceptions:
        fail("Status is resolved_exceptions_noted, but no exceptions are specified in the report or via --exceptions.")

    # Apply changes to report in memory
    report['status'] = status
    if provided_exceptions:
        report['user_exceptions'] = sorted(list(set(report_exceptions + provided_exceptions)))


    # 1. Set resolution timestamp
    report['resolved_at'] = datetime.now(timezone.utc).isoformat()
    
    # 2. Save report and archive FIRST (Fix 4)
    report_id = report.get('report_id')
    if not report_id:
        fail("Report is missing report_id.")
        
    archive_path = os.path.join(reports_dir, f"{report_id}.json")
    os.makedirs(reports_dir, exist_ok=True)
    
    save_json(latest_path, report)
    save_json(archive_path, report)
    
    # 3. Validate BEFORE touching snapshot (Fix 4)
    validator = os.path.join(project_root, '.agents', 'core', 'validators', 'validate_drift_resolution.py')
    if not os.path.isfile(validator):
        fail("validate_drift_resolution.py not found.")
        
    # Timeout on validator subprocess (Fix 7)
    try:
        proc = subprocess.run([sys.executable, validator, project_root, latest_path],
                              capture_output=True, text=True, timeout=30)
    except subprocess.TimeoutExpired:
        fail("validate_drift_resolution.py timed out after 30s.")
        
    if proc.returncode != 0:
        fail(f"Validation failed: {proc.stdout.strip() or proc.stderr.strip()}")
        
    validation_status = "passed"
    
    # 4. Only NOW mutate and write snapshot conditionally (Fix 5)
    if status == 'resolved_exceptions_noted':
        snapshot['accumulated_commit_weight'] = 0
        save_json(snapshot_path, snapshot)
        
        # 5. Snapshot schema validation after write (Fix 6)
        validate_json_script = os.path.join(project_root, '.agents', 'core', 'validators', 'validate_json.py')
        snapshot_schema = os.path.join(project_root, '.agents', 'core', 'contracts', 'project_snapshot.schema.json')
        if os.path.isfile(validate_json_script) and os.path.isfile(snapshot_schema):
            try:
                proc = subprocess.run([sys.executable, validate_json_script, snapshot_path, snapshot_schema],
                                      capture_output=True, text=True, timeout=15)
            except subprocess.TimeoutExpired:
                fail("validate_json.py timed out after 15s during snapshot schema validation.")
            if proc.returncode != 0:
                fail(f"Snapshot schema validation failed after write: {proc.stdout.strip() or proc.stderr.strip()}")
                
    print(json.dumps({
        "status": "success",
        "resolved_status": status,
        "resolved_at": report['resolved_at'],
        "exceptions_logged": len(report.get('user_exceptions', [])),
        "archive_written": archive_path,
        "validation": validation_status
    }, indent=2))
    sys.exit(0)

if __name__ == '__main__':
    main()
