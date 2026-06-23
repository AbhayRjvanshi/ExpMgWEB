#!/usr/bin/env python3
"""
resolve_drift.py (v1.0)
Automates the mechanical steps of resolving an active drift report:
1. Verifies that all questions in drift_report.json are answered.
2. Validates exceptions against status and report signals.
3. Updates resolved_at to current timestamp.
4. Saves resolved drift_report.json and its archive copy.
5. Invokes validate_drift_resolution.py.
6. Mutates project snapshot and validates against schema.
"""

import json
import os
import sys
import subprocess
import time
from datetime import datetime, timezone

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from git_helper import GitHelper
from fault_injection import maybe_crash
from journal_helper import write_journal_entry, complete_journal_entry, recover_journal
from snapshot_helper import load_json, save_snapshot_atomic, cleanup_stale_tmp_files

class RunContext:
    def __init__(self):
        self.last_mono_time = None

def save_json(path, data):
    return save_snapshot_atomic(path, data)

def fail(message):
    print(json.dumps({'error': message}))
    sys.exit(1)

def validate_state_transition(from_state, to_state):
    VALID_TRANSITIONS = {
        "synced": ["pending_reconciliation"],
        "pending_reconciliation": ["synced", "recovering", "corrupted"],
        "recovering": ["synced", "pending_reconciliation", "warning", "corrupted"],
        "warning": ["synced", "recovering", "corrupted"],
        "corrupted": ["synced"]
    }
    if to_state not in VALID_TRANSITIONS.get(from_state, []):
        raise ValueError(f"Invalid state transition from {from_state} to {to_state}")

def main():
    args = sys.argv[1:]
    
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
    
    from lock_helper import OrchestratorLock
    lock = OrchestratorLock(project_root)
    if not lock.acquire():
        fail("Could not acquire orchestrator lock: process contention or starvation.")

    try:
        latest_path = os.path.join(project_root, '.agents', 'orchestration', 'drift_report.json')
        snapshot_path = os.path.join(project_root, '.agents', 'orchestration', 'project_snapshot.json')
        reports_dir = os.path.join(project_root, '.agents', 'orchestration', 'drift_reports')
        
        cleanup_stale_tmp_files(os.path.dirname(snapshot_path), max_age_seconds=60)
        recover_journal(project_root)
        
        if not os.path.isfile(latest_path):
            fail(f"Drift report not found at {latest_path}. Run detect_drift.py first.")
            
        if not os.path.isfile(snapshot_path):
            fail(f"Snapshot not found at {snapshot_path}.")
            
        report = load_json(latest_path)
        snapshot = load_json(snapshot_path)
        
        status = status_override or report.get('status', 'pending_user_response')
        
        if status == 'pending_user_response':
            fail("Cannot resolve report: status is 'pending_user_response'.")
            
        if status not in ('resolved_rerun', 'resolved_no_action', 'resolved_exceptions_noted'):
            fail(f"Invalid status: '{status}'.")

        if exceptions_input and status not in ('resolved_exceptions_noted', 'resolved_rerun'):
            fail("--exceptions can only be used with appropriate statuses.")
            
        provided_exceptions = []
        if exceptions_input:
            provided_exceptions = [e.strip() for e in exceptions_input.split(',') if e.strip()]
            
            question_signals = {q.get('signal') for q in report.get('user_questions', [])}
            for exc in provided_exceptions:
                if exc not in question_signals:
                    fail(f"Exception signal '{exc}' does not match report questions.")

        for index, q in enumerate(report.get('user_questions', [])):
            answer = q.get('user_answer')
            if not q.get('answered') or answer is None or (isinstance(answer, str) and not answer.strip()):
                fail(f"Question {index} (signal: {q.get('signal')}) is unanswered.")

        report_exceptions = report.get('user_exceptions', [])
        if status == 'resolved_exceptions_noted' and not provided_exceptions and not report_exceptions:
            fail("Status is resolved_exceptions_noted, but no exceptions are specified.")

        report['status'] = status
        if provided_exceptions:
            report['user_exceptions'] = sorted(list(set(report_exceptions + provided_exceptions)))

        report['resolved_at'] = datetime.now(timezone.utc).isoformat()
        
        report_id = report.get('report_id')
        if not report_id:
            fail("Report is missing report_id.")
            
        archive_path = os.path.join(reports_dir, f"{report_id}.json")
        os.makedirs(reports_dir, exist_ok=True)
        
        success = save_json(latest_path, report)
        if not success:
            fail("Failed to write latest drift report.")
        success = save_json(archive_path, report)
        if not success:
            fail("Failed to write archived drift report.")
        
        validator = os.path.join(project_root, '.agents', 'core', 'validators', 'validate_drift_resolution.py')
        if not os.path.isfile(validator):
            fail("validate_drift_resolution.py not found.")
            
        try:
            proc = subprocess.run([sys.executable, validator, project_root, latest_path],
                                  capture_output=True, text=True, timeout=30)
        except subprocess.TimeoutExpired:
            fail("validate_drift_resolution.py timed out after 30s.")
            
        if proc.returncode != 0:
            fail(f"Validation failed: {proc.stdout.strip() or proc.stderr.strip()}")
            
        validation_status = "passed"
        
        old_state = snapshot.get('cursor_state', 'synced')
        target_state = "synced"
        snapshot_mutated = False
        
        if old_state != target_state:
            validate_state_transition(old_state, target_state)
            
            if snapshot.get('pending_last_analyzed_commit'):
                snapshot['last_analyzed_commit'] = snapshot['pending_last_analyzed_commit']
                snapshot['tracked_branch'] = snapshot.get('pending_tracked_branch')
                snapshot['merge_base_commit'] = snapshot.get('pending_merge_base_commit')
                
            snapshot['reconciliation_id'] = snapshot.get('reconciliation_id', 0) + 1
            
            snapshot['pending_last_analyzed_commit'] = None
            snapshot['pending_tracked_branch'] = None
            snapshot['pending_merge_base_commit'] = None
            snapshot['reconciliation_started_at'] = None
            snapshot['reconciliation_elapsed_seconds'] = 0.0
            snapshot['cursor_state'] = target_state
            snapshot_mutated = True

        if status == 'resolved_exceptions_noted':
            snapshot['accumulated_commit_weight'] = 0
            snapshot_mutated = True

        if snapshot_mutated:
            run_id = os.environ.get("ORCHESTRATION_RUN_ID", f"run_{int(time.time())}")
            txn_id = os.environ.get("ORCHESTRATION_TXN_ID", f"txn_{run_id}_{int(time.time())}")
            snapshot['last_reconciliation_txn'] = txn_id
            
            expected_outcome = {
                "last_analyzed_commit": snapshot.get('last_analyzed_commit'),
                "cursor_state": target_state,
                "accumulated_commit_weight": snapshot.get('accumulated_commit_weight', 0)
            }
            write_journal_entry(project_root, txn_id, "cursor_commit", expected_outcome)
            maybe_crash("before_cursor_commit")
            maybe_crash("mid_reconciliation")
            
            success = save_json(snapshot_path, snapshot)
            if not success:
                fail("Failed to write updated project snapshot.")
                
            complete_journal_entry(project_root, txn_id)
            
            validate_json_script = os.path.join(project_root, '.agents', 'core', 'validators', 'validate_json.py')
            snapshot_schema = os.path.join(project_root, '.agents', 'core', 'contracts', 'project_snapshot.schema.json')
            if os.path.isfile(validate_json_script) and os.path.isfile(snapshot_schema):
                try:
                    proc = subprocess.run([sys.executable, validate_json_script, snapshot_path, snapshot_schema],
                                          capture_output=True, text=True, timeout=15)
                except subprocess.TimeoutExpired:
                    fail("validate_json.py timed out after 15s during snapshot validation.")
                if proc.returncode != 0:
                    fail(f"Snapshot schema validation failed: {proc.stdout.strip() or proc.stderr.strip()}")
                    
        print(json.dumps({
            "status": "success",
            "resolved_status": status,
            "resolved_at": report['resolved_at'],
            "exceptions_logged": len(report.get('user_exceptions', [])),
            "archive_written": archive_path,
            "validation": validation_status
        }, indent=2))
        sys.exit(0)
    except Exception as e:
        sys.stderr.write(f"Error: resolve_drift.py failed: {e}\n")
        sys.exit(1)
    finally:
        lock.release()

if __name__ == '__main__':
    main()
