import os
import sys
import json
import time
from fault_injection import maybe_crash
from snapshot_helper import load_json

def write_journal_entry(project_root, txn_id, intent, expected_outcome=None):
    """
    Writes a single-active-transaction recovery marker.
    Uses fsync for durability guarantees.
    """
    journal_path = os.path.join(project_root, '.agents', 'orchestration', 'journal.json')
    entry = {
        "txn_id": txn_id,
        "intent": intent,
        "state": "started",
        "timestamp": time.time(),
        "idempotent": True,
        "expected_outcome": expected_outcome
    }
    tmp_path = journal_path + '.tmp'
    try:
        with open(tmp_path, 'w', encoding='utf-8') as f:
            json.dump(entry, f, indent=2)
            f.flush()
            os.fsync(f.fileno()) # Fsync required on journal start
        maybe_crash("before_journal_write")
        os.replace(tmp_path, journal_path)
    except Exception as e:
        sys.stderr.write(f"Warning: Failed to write journal start entry: {e}\n")

def complete_journal_entry(project_root, txn_id):
    """
    Completes the active single-transaction state.
    """
    journal_path = os.path.join(project_root, '.agents', 'orchestration', 'journal.json')
    if os.path.exists(journal_path):
        try:
            with open(journal_path, 'r', encoding='utf-8') as f:
                entry = json.load(f)
            if entry.get("txn_id") == txn_id:
                entry["state"] = "completed"
                tmp_path = journal_path + '.tmp'
                with open(tmp_path, 'w', encoding='utf-8') as f:
                    json.dump(entry, f, indent=2)
                    f.flush()
                maybe_crash("before_journal_complete")
                os.replace(tmp_path, journal_path)
        except Exception as e:
            sys.stderr.write(f"Warning: Failed to complete journal entry: {e}\n")

def recover_journal(project_root):
    """
    Performs transactional recovery check.
    
    CRITICAL ARCHITECTURAL BOUNDARY (ISSUE 6):
    Under the chosen Option A architecture, the snapshot is the sole authoritative
    orchestration state boundary. This recovery mechanism does NOT verify external
    side effects, related filesystem mutations (e.g. drift report files), or partially
    completed downstream orchestration actions. These are handled as best-effort
    side effects of the snapshot transition.
    
    If a transaction was marked "started" but the snapshot was not advanced on disk,
    it rolls back the active journal state and archives the failed journal, signaling
    that the transaction did not durably complete.
    """
    journal_path = os.path.join(project_root, '.agents', 'orchestration', 'journal.json')
    snapshot_path = os.path.join(project_root, '.agents', 'orchestration', 'project_snapshot.json')
    if os.path.exists(journal_path):
        try:
            with open(journal_path, 'r', encoding='utf-8') as f:
                entry = json.load(f)
            if entry.get("state") == "started":
                txn_id = entry.get("txn_id")
                outcome = entry.get("expected_outcome")
                # Cross-reference snapshot to verify if writes completed
                if os.path.exists(snapshot_path):
                    snapshot = load_json(snapshot_path)
                    # Verify txn_id AND expected outcome details match
                    txn_matches = snapshot.get("last_reconciliation_txn") == txn_id
                    outcome_matches = True
                    if outcome and isinstance(outcome, dict):
                        for k, v in outcome.items():
                            if snapshot.get(k) != v:
                                outcome_matches = False
                                break
                    
                    if txn_matches and outcome_matches:
                        # Transaction was indeed committed before crash. Complete it.
                        entry["state"] = "completed"
                        with open(journal_path, 'w', encoding='utf-8') as f:
                            json.dump(entry, f, indent=2)
                        return
                
                # Otherwise, the snapshot write was not durable. Rollback and archive evidence.
                sys.stderr.write(f"Warning: Incomplete transaction {txn_id} detected. Rolling back state.\n")
                failed_path = os.path.join(project_root, '.agents', 'orchestration', 'journal.failed.json')
                try:
                    os.replace(journal_path, failed_path)
                except OSError as e:
                    sys.stderr.write(f"Warning: Failed to rename incomplete journal to failed: {e}\n")
                    try:
                        os.remove(journal_path)
                    except OSError as err:
                        sys.stderr.write(f"Warning: Failed to remove incomplete journal: {err}\n")
        except Exception as e:
            sys.stderr.write(f"Warning: Journal recovery failed: {e}\n")
