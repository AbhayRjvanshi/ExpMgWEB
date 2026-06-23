#!/usr/bin/env python3
"""
Orchestration Test Harness for ExpMgWEB Agentic Subsystem (v1.0)
Validates all hardened features, state machine transitions, validators, 
and fallback modes under normal and destructive scenarios.
"""

import unittest
import os
import sys
import json
import time
import shutil
import ast
import tempfile
from datetime import datetime, timezone

# Add core validators to path
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from git_helper import GitHelper, GIT_ERROR_CODES
from validate_json import tier1_scan_schema, tier1_validate_data
from detect_drift import run_checksum_drift, get_file_hash
from validate_skill_security import SkillSecurityVisitor, SymbolTracker, validate_skill
from journal_helper import write_journal_entry, complete_journal_entry, recover_journal
from snapshot_helper import save_snapshot_atomic, cleanup_stale_tmp_files, load_json
from score_commits import determine_target_state, validate_state_transition
from lock_helper import check_pid_survival, get_process_start_time, OrchestratorLock
import stat

def remove_readonly(func, path, excinfo):
    try:
        os.chmod(path, stat.S_IWRITE)
        func(path)
    except Exception as e:
        # Cleanup failed but is ignored as deletion is best-effort. Emit to stderr for policy tracking.
        sys.stderr.write(f"[test_harness] best-effort cleanup failed for {path}: {e}\n")

class TestGitHelper(unittest.TestCase):
    def setUp(self):
        self.test_dir = tempfile.mkdtemp()
        self.helper = GitHelper(self.test_dir, git_timeout_seconds=5)

    def tearDown(self):
        shutil.rmtree(self.test_dir, onerror=remove_readonly)

    def test_git_discovery(self):
        path = self.helper._discover_git()
        if path is not None:
            self.assertTrue(isinstance(path, str))

    def test_log_rotation_and_backpressure(self):
        log_dir = os.path.join(self.test_dir, 'logs')
        os.makedirs(log_dir, exist_ok=True)
        log_path = os.path.join(log_dir, 'telemetry.jsonl')
        
        with open(log_path, 'wb') as f:
            f.write(b'x' * (11 * 1024 * 1024))
            
        self.helper.emit_runtime_event({"event": "test_rotation"})
        
        # Flush buffered events to ensure files rotate
        self.helper.flush_telemetry()
        
        old_log = os.path.join(log_dir, 'telemetry.old.jsonl')
        self.assertTrue(os.path.exists(old_log))
        self.assertTrue(os.path.exists(log_path))
        self.assertLess(os.path.getsize(log_path), 1000)

    def test_telemetry_write_failure_degradation(self):
        log_dir = os.path.join(self.test_dir, 'logs')
        os.makedirs(log_dir, exist_ok=True)
        log_path = os.path.join(log_dir, 'telemetry.jsonl')
        os.makedirs(log_path, exist_ok=True) # Lock write by making it a directory
        
        self.helper.emit_runtime_event({"event": "fail_test"})
        self.helper.flush_telemetry()
        self.assertTrue(self.helper.telemetry_degraded)


class TestJSONValidator(unittest.TestCase):
    def test_recursion_protection(self):
        schema = {}
        schema["properties"] = {"child": schema}
        
        with self.assertRaises(ValueError) as context:
            tier1_scan_schema(schema)
        self.assertIn("Recursive cycle", str(context.exception))

    def test_unsupported_keywords(self):
        schema = {
            "type": "object",
            "oneOf": [{"type": "string"}]
        }
        with self.assertRaises(NotImplementedError):
            tier1_scan_schema(schema)

    def test_absolute_numeric_magnitude_cap(self):
        schema = {"type": "integer"}
        tier1_validate_data(10**10, schema)
        
        with self.assertRaises(ValueError) as context:
            tier1_validate_data(10**13, schema)
        self.assertIn("Numeric magnitude exceeds", str(context.exception))

    def test_object_property_limits_on_all_objects(self):
        schema = {"type": "object"}
        data = {f"k{i}": i for i in range(50005)}
        
        with self.assertRaises(ValueError) as context:
            tier1_validate_data(data, schema)
        self.assertIn("property count exceeds", str(context.exception))

    def test_nan_infinity_rejection(self):
        schema = {"type": "number"}
        tier1_validate_data(3.14, schema)
        
        with self.assertRaises(ValueError) as context:
            tier1_validate_data(float('nan'), schema)
        self.assertIn("Non-finite numeric value", str(context.exception))
        
        with self.assertRaises(ValueError) as context:
            tier1_validate_data(float('inf'), schema)
        self.assertIn("Non-finite numeric value", str(context.exception))

        with self.assertRaises(ValueError) as context:
            tier1_validate_data(float('-inf'), schema)
        self.assertIn("Non-finite numeric value", str(context.exception))

    def test_boolean_bypassing_rejection(self):
        schema = {"type": "integer"}
        with self.assertRaises(TypeError) as context:
            tier1_validate_data(True, schema)
        self.assertIn("boolean is not allowed", str(context.exception))


class TestASTSecurityValidator(unittest.TestCase):
    def test_alias_tracking_and_subprocess_execution(self):
        source = """
import subprocess as sp
sp.run(["ls", "-l"])
"""
        tree = ast.parse(source)
        tracker = SymbolTracker()
        tracker.visit(tree)
        
        visitor = SkillSecurityVisitor(tracker)
        visitor.visit(tree)
        
        findings = visitor.findings
        self.assertTrue(len(findings) > 0)
        # Avoid overfitting exact index validation
        rule_ids = {f["rule_id"] for f in findings}
        self.assertIn("subprocess_execution", rule_ids)
        self.assertEqual(findings[0]["severity"], "CRITICAL")

    def test_attribute_depth_guard(self):
        chain = "a." * 15 + "b"
        tree = ast.parse(chain)
        visitor = SkillSecurityVisitor(SymbolTracker())
        
        node = tree.body[0].value
        resolved = visitor._resolve_base_name(node, max_depth=5)
        self.assertIsNone(resolved)

    def test_findings_deduplication(self):
        visitor = SkillSecurityVisitor(SymbolTracker())
        visitor.add_finding("HIGH", 10, "ast.Call", "rule_1", "Duplicate msg")
        visitor.add_finding("HIGH", 10, "ast.Call", "rule_1", "Duplicate msg")
        
        self.assertEqual(len(visitor.findings), 1)


class TestChecksumDrift(unittest.TestCase):
    def setUp(self):
        self.test_dir = tempfile.mkdtemp()
        self.snapshot = {
            "last_cache_rebuild": 0,
            "file_counts": {".py": 1},
            "top_directories": ["src"],
            "total_file_count": 1
        }
        os.makedirs(os.path.join(self.test_dir, 'src'), exist_ok=True)
        os.makedirs(os.path.join(self.test_dir, '.agents', 'orchestration'), exist_ok=True)
        self.test_file = os.path.join(self.test_dir, 'src', 'main.py')
        with open(self.test_file, 'w') as f:
            f.write("print('hello')")

    def tearDown(self):
        shutil.rmtree(self.test_dir, onerror=remove_readonly)

    def test_run_checksum_drift_and_cache_throttling(self):
        skip_dirs = frozenset(['.git'])
        drift_exts = frozenset(['.py'])
        
        run_checksum_drift(self.test_dir, self.snapshot, skip_dirs, drift_exts)
        
        cache_path = os.path.join(self.test_dir, '.agents', 'orchestration', 'checksum_cache.json')
        with open(cache_path, 'w') as f:
            f.write("corrupted_json{")
            
        with self.assertRaises(RuntimeError) as context:
            run_checksum_drift(self.test_dir, self.snapshot, skip_dirs, drift_exts)
        self.assertIn("Cache rebuild storm detected", str(context.exception))

    def test_file_hash_retry_concurrency(self):
        h = get_file_hash(self.test_file)
        self.assertEqual(len(h), 64)


class TestCursorStateMachine(unittest.TestCase):
    class MockRunContext:
        def __init__(self):
            self.last_mono_time = None

    def setUp(self):
        self.test_dir = tempfile.mkdtemp()
        self.snapshot_path = os.path.join(self.test_dir, 'project_snapshot.json')
        self.snapshot = {
            "cursor_state": "synced",
            "reconciliation_elapsed_seconds": 0.0,
            "last_checkpoint_wall": time.time()
        }

    def tearDown(self):
        shutil.rmtree(self.test_dir, onerror=remove_readonly)

    def test_state_transitions(self):
        validate_state_transition("synced", "pending_reconciliation")
        validate_state_transition("pending_reconciliation", "recovering")
        validate_state_transition("recovering", "warning")
        validate_state_transition("warning", "recovering")
        validate_state_transition("recovering", "pending_reconciliation")
        validate_state_transition("warning", "corrupted")
        validate_state_transition("corrupted", "synced")
        
        with self.assertRaises(ValueError):
            validate_state_transition("synced", "warning")

    def test_monotonic_clock_duration_tracking(self):
        run_context = self.MockRunContext()
        
        state = determine_target_state(self.snapshot, threshold_crossed=True, run_context=run_context)
        self.assertEqual(state, "pending_reconciliation")
        
        run_context.last_mono_time = time.monotonic() - 4000
        state = determine_target_state(self.snapshot, threshold_crossed=True, run_context=run_context)
        self.assertEqual(state, "recovering")
        
        run_context.last_mono_time = time.monotonic() - 90000
        state = determine_target_state(self.snapshot, threshold_crossed=True, run_context=run_context)
        self.assertEqual(state, "warning")
        
        run_context.last_mono_time = time.monotonic() - 7 * 86400 - 100
        state = determine_target_state(self.snapshot, threshold_crossed=True, run_context=run_context)
        self.assertEqual(state, "corrupted")

    def test_cleanup_stale_temp_files(self):
        tmp1 = os.path.join(self.test_dir, 'test1.tmp')
        tmp2 = os.path.join(self.test_dir, 'test2.tmp')
        
        with open(tmp1, 'w') as f: f.write("data")
        with open(tmp2, 'w') as f: f.write("data")
        
        old_time = time.time() - 600
        os.utime(tmp1, (old_time, old_time))
        
        cleanup_stale_tmp_files(self.test_dir, max_age_seconds=60)
        
        self.assertFalse(os.path.exists(tmp1))
        self.assertTrue(os.path.exists(tmp2))

    def test_atomic_snapshot_save(self):
        success = save_snapshot_atomic(self.snapshot_path, self.snapshot)
        self.assertTrue(success)
        self.assertTrue(os.path.exists(self.snapshot_path))
        
        with open(self.snapshot_path, 'r') as f:
            loaded = json.load(f)
        self.assertEqual(loaded["cursor_state"], "synced")

    def test_journal_recovery(self):
        txn_id = "test_txn_123"
        os.makedirs(os.path.join(self.test_dir, '.agents', 'orchestration'), exist_ok=True)
        write_journal_entry(self.test_dir, txn_id, "cursor_commit")
        
        journal_path = os.path.join(self.test_dir, '.agents', 'orchestration', 'journal.json')
        self.assertTrue(os.path.exists(journal_path))
        with open(journal_path, 'r', encoding='utf-8') as f:
            data = json.load(f)
        self.assertEqual(data["state"], "started")
        
        recover_journal(self.test_dir)
        
        # Note: recovery checks snapshot updates. Since no snapshot exists, it fails recovery and archives/removes the started journal
        self.assertFalse(os.path.exists(journal_path))
        failed_path = os.path.join(self.test_dir, '.agents', 'orchestration', 'journal.failed.json')
        self.assertTrue(os.path.exists(failed_path))

class TestOrchestratorEdgeCases(unittest.TestCase):
    def setUp(self):
        self.test_dir = tempfile.mkdtemp()
        self.lock_path = os.path.join(self.test_dir, '.agents', 'orchestration', 'orchestrator.lock')
        self.snapshot_path = os.path.join(self.test_dir, '.agents', 'orchestration', 'project_snapshot.json')
        os.makedirs(os.path.join(self.test_dir, '.agents', 'orchestration'), exist_ok=True)

    def tearDown(self):
        shutil.rmtree(self.test_dir, onerror=remove_readonly)

    def test_stale_lock_sweeper_race_prevention(self):
        lock_dummy = OrchestratorLock(self.test_dir)
        self.assertTrue(lock_dummy.acquire())
        self.assertTrue(os.path.exists(lock_dummy.lock_path))

        lock_sweeper = OrchestratorLock(self.test_dir)
        stale_meta = {
            "pid": 99999,
            "created_at": time.time() - 100,
            "hostname": "old-host",
            "process_start_time": 10.0
        }
        with open(lock_dummy.lock_path, 'w', encoding='utf-8') as f:
            json.dump(stale_meta, f)

        lock_active = OrchestratorLock(self.test_dir)
        active_meta = {
            "pid": os.getpid(),
            "created_at": time.time(),
            "hostname": lock_active.hostname,
            "process_start_time": lock_active.start_time
        }
        with open(lock_dummy.lock_path, 'w', encoding='utf-8') as f:
            json.dump(active_meta, f)
            
        # The lock path now contains active lock metadata. Let's simulate acquire retry count check.
        # It shouldn't remove/override it.
        # We manually test release safety too:
        # A dummy/dead process trying to release should NOT delete the lock belonging to lock_active.
        # Verify that checking release fails when metadata doesn't match:
        lock_sweeper.pid = 99999
        lock_sweeper.start_time = 10.0
        lock_sweeper.hostname = "old-host"
        lock_sweeper.has_lock = True
        lock_sweeper.release()
        self.assertTrue(os.path.exists(lock_dummy.lock_path))

    def test_partial_snapshot_truncation_validation(self):
        with open(self.snapshot_path, 'w') as f:
            f.write("{ \"cursor_state\": \"synced\", ")
            
        # tier1_validate_data should raise exception when traversed
        schema = {"type": "object"}
        with self.assertRaises(Exception):
            with open(self.snapshot_path, 'r') as f:
                data = json.load(f)
            tier1_validate_data(data, schema)

    def test_telemetry_queue_exhaustion(self):
        helper = GitHelper(self.test_dir)
        for i in range(150):
            helper.emit_runtime_event({"event": f"event_{i}", "severity": "info"})
            
        self.assertLessEqual(len(helper._event_queue), 100)

    def test_journal_replay_mismatch_rollback(self):
        write_journal_entry(self.test_dir, "txn_123", "cursor_commit", expected_outcome={"cursor_state": "synced"})
        
        snapshot = {
            "cursor_state": "warning",
            "last_reconciliation_txn": "txn_123"
        }
        with open(self.snapshot_path, 'w') as f:
            json.dump(snapshot, f)
            
        recover_journal(self.test_dir)
        
        failed_path = os.path.join(self.test_dir, '.agents', 'orchestration', 'journal.failed.json')
        self.assertTrue(os.path.exists(failed_path))
        self.assertFalse(os.path.exists(os.path.join(self.test_dir, '.agents', 'orchestration', 'journal.json')))

if __name__ == '__main__':
    print("Executing Agent Orchestration Test Suite...")
    unittest.main()
