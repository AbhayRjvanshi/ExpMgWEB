import subprocess
import shutil
import sys
import json
import os
import time
import hashlib
import atexit

from fault_injection import maybe_crash

GIT_ERROR_CODES = {
    128: "git_repository_failure",
    129: "invalid_git_arguments"
}

ERROR_RECOVERY_POLICY = {
    "git_not_installed": {"recoverable": True, "recommended_action": "fallback_checksum"},
    "timeout": {"recoverable": True, "recommended_action": "retry"},
    "git_repository_failure": {"recoverable": False, "recommended_action": "abort"},
    "git_command_failed": {"recoverable": False, "recommended_action": "abort"}
}

ERROR_SUBTYPE_RECOVERY_POLICY = {
    "permission_denied": {"recoverable": False, "recommended_action": "manual_intervention"},
    "not_a_repository": {"recoverable": False, "recommended_action": "abort"}
}

class GitHelper:
    # Class-level variables to persist queue state and hash chains across instances
    _event_queue = []
    _last_flush_time = time.monotonic()
    _sequence_counter = 0
    _rolling_hash = "INIT_HASH_SEED"
    _seen_events = {}
    _registered_atexit = False
    _last_telemetry_failure_time = 0.0 # Bounded failure loop prevention
    _timer_thread_started = False
    _telemetry_degraded = False

    @property
    def telemetry_degraded(self):
        return GitHelper._telemetry_degraded

    @telemetry_degraded.setter
    def telemetry_degraded(self, value):
        GitHelper._telemetry_degraded = value

    def __init__(self, project_root, git_timeout_seconds=30, recovery_policy=None, subtype_policy=None):
        self.project_root = os.path.abspath(project_root)
        self.git_timeout_seconds = git_timeout_seconds
        self.recovery_policy = recovery_policy or ERROR_RECOVERY_POLICY
        self.subtype_policy = subtype_policy or ERROR_SUBTYPE_RECOVERY_POLICY
        self.git_path = self._discover_git()
        self.telemetry_degraded = False
        
        # Register atexit flush once globally per process
        if not GitHelper._registered_atexit:
            atexit.register(self.flush_telemetry)
            GitHelper._registered_atexit = True

        # Start a background timer thread to flush telemetry every 30 seconds
        # for long-running processes (e.g. daemons, soak tests) (ISSUE 5)
        if not GitHelper._timer_thread_started:
            import threading
            GitHelper._timer_thread_started = True
            def period_flush():
                while True:
                    time.sleep(30.0)
                    try:
                        GitHelper._flush_telemetry_static(self.project_root)
                    except Exception:
                        pass
            t = threading.Thread(target=period_flush, daemon=True)
            t.start()

    def emit_runtime_event(self, event):
        if not isinstance(event, dict):
            return

        # 1. Telemetry sequence numbers (Phase 4)
        GitHelper._sequence_counter += 1
        event["sequence_id"] = GitHelper._sequence_counter
        
        # 2. Rolling Integrity hashes (Phase 4)
        event_str = json.dumps(event, sort_keys=True)
        hash_input = event_str + GitHelper._rolling_hash
        GitHelper._rolling_hash = hashlib.sha256(hash_input.encode('utf-8')).hexdigest()
        event["rolling_hash"] = GitHelper._rolling_hash

        # 3. Inject correlation IDs from environment
        for var, key in [
            ("ORCHESTRATION_RUN_ID", "run_id"),
            ("ORCHESTRATION_TXN_ID", "txn_id"),
            ("ORCHESTRATION_PARENT_TXN_ID", "parent_txn_id"),
            ("ORCHESTRATION_RECONCILIATION_ID", "reconciliation_id")
        ]:
            val = os.environ.get(var)
            if val and key not in event:
                event[key] = val

        # 4. Adaptive Telemetry Sampling
        sev = str(event.get("severity", "info")).lower()
        if sev in ("info", "debug"):
            event_key = (event.get("component"), event.get("event"), str(event.get("details", "")))
            count = GitHelper._seen_events.get(event_key, 0)
            GitHelper._seen_events[event_key] = count + 1
            if count > 0 and (count % 100) != 0:
                return

        # 5. Bounded memory buffer & Adaptive Shedding (Phase 4)
        queue_limit = 100
        if len(GitHelper._event_queue) >= queue_limit:
            if sev in ("warning", "critical"):
                # Drop oldest low-priority log to accommodate critical alert
                dropped = False
                for idx, queued_event in enumerate(GitHelper._event_queue):
                    queued_sev = str(queued_event.get("severity", "info")).lower()
                    if queued_sev in ("info", "debug"):
                        GitHelper._event_queue.pop(idx)
                        dropped = True
                        break
                if not dropped:
                    GitHelper._event_queue.pop(0)
            else:
                # Shed load: discard current info/debug event
                return

        # 6. Append to queue
        GitHelper._event_queue.append(event)

        # 7. Check write triggering metrics
        # Flush immediately only for warnings/critical errors, if the queue is full (100 events),
        # or if 30 seconds have elapsed since the last flush.
        # Otherwise, let the background timer or atexit handler flush the events.
        now_mono = time.monotonic()
        should_flush = (
            len(GitHelper._event_queue) >= 100 or
            now_mono - GitHelper._last_flush_time >= 30.0 or
            sev in ("warning", "critical")
        )

        if should_flush:
            self.flush_telemetry()

    def flush_telemetry(self):
        GitHelper._flush_telemetry_static(self.project_root)

    @classmethod
    def _flush_telemetry_static(cls, project_root):
        if not cls._event_queue:
            return

        log_dir = os.path.join(project_root, 'logs')
        os.makedirs(log_dir, exist_ok=True)
        log_path = os.path.join(log_dir, 'telemetry.jsonl')

        # Telemetry Log Rotation Backpressure Controls
        try:
            if os.path.exists(log_path) and os.path.getsize(log_path) > 10 * 1024 * 1024:
                old_log_path = os.path.join(log_dir, 'telemetry.old.jsonl')
                if os.path.exists(old_log_path):
                    os.remove(old_log_path)
                os.rename(log_path, old_log_path)
        except Exception as e:
            sys.stderr.write(f"Warning: Telemetry log rotation failure ({e})\n")

        # Log appending without blocking fsync (Point 12)
        try:
            maybe_crash("before_telemetry_append")
            events_to_write = list(cls._event_queue)
            cls._event_queue.clear()
            if events_to_write:
                with open(log_path, 'a', encoding='utf-8') as f:
                    for ev in events_to_write:
                        f.write(json.dumps(ev) + '\n')
            cls._last_flush_time = time.monotonic()
        except Exception as e:
            cls._telemetry_degraded = True
            # Cooldown failure warnings to prevent logging crash loops (Another Missing Improvement)
            now_mono = time.monotonic()
            if now_mono - cls._last_telemetry_failure_time > 60.0:
                cls._last_telemetry_failure_time = now_mono
                sys.stderr.write(f"Warning: Telemetry write failure ({e}). Degraded mode active (suppressing warnings for 60s).\n")
                sys.stderr.flush()

    def _discover_git(self):
        executables = ['git.exe', 'git']
        for name in executables:
            resolved = shutil.which(name)
            if resolved:
                return resolved
        return None

    def get_status(self):
        if not self.git_path:
            policy = self.recovery_policy["git_not_installed"]
            return {
                "status": "error",
                "error_type": "git_not_installed",
                "recoverable": policy["recoverable"],
                "recommended_action": policy["recommended_action"]
            }
        res = self.run(['status', '--porcelain'])
        if not res["success"]:
            return {
                "status": "error",
                "error_type": res["error_type"],
                "error_subtype": res.get("error_subtype"),
                "recoverable": res["recoverable"],
                "recommended_action": res["recommended_action"]
            }
        return {"status": "ok"}

    def run(self, args):
        if not self.git_path:
            policy = self.recovery_policy["git_not_installed"]
            self.emit_runtime_event({
                "component": "git_helper",
                "event": "missing_git",
                "severity": "critical",
                "details": "Git executable not found on host path."
            })
            return {
                "success": False,
                "return_code": -1,
                "stdout": "",
                "stderr": "Git executable not found on host path.",
                "error_type": "git_not_installed",
                "recoverable": policy["recoverable"],
                "recommended_action": policy["recommended_action"]
            }
        
        cmd = args[0] if args else ""
        operation_timeouts = {
            "status": 10,
            "rev-parse": 10,
            "diff": 30,
            "diff-tree": 30,
            "merge-base": 30,
            "log": 60
        }
        timeout = operation_timeouts.get(cmd, self.git_timeout_seconds)

        try:
            proc = subprocess.run(
                [self.git_path] + args,
                cwd=self.project_root,
                capture_output=True,
                text=True,
                timeout=timeout,
                check=False
            )
            error_type = None
            error_subtype = None
            if proc.returncode != 0:
                error_type = GIT_ERROR_CODES.get(proc.returncode, "git_command_failed")
                if proc.returncode == 128:
                    error_type = "git_repository_failure"
                    if "not a git repository" in proc.stderr.lower():
                        error_subtype = "not_a_repository"
                
                if "permission" in proc.stderr.lower() or "denied" in proc.stderr.lower():
                    error_subtype = "permission_denied"

            policy = self.recovery_policy.get(error_type, {"recoverable": False, "recommended_action": "abort"}) if error_type else {"recoverable": False, "recommended_action": "none"}
            if error_subtype in self.subtype_policy:
                policy = self.subtype_policy[error_subtype]

            self.emit_runtime_event({
                "component": "git_helper",
                "event": "git_command_executed",
                "args": args,
                "return_code": proc.returncode,
                "success": proc.returncode == 0
            })

            return {
                "success": proc.returncode == 0,
                "return_code": proc.returncode,
                "stdout": proc.stdout.strip(),
                "stderr": proc.stderr.strip(),
                "error_type": error_type,
                "error_subtype": error_subtype,
                "recoverable": policy["recoverable"],
                "recommended_action": policy["recommended_action"]
            }
        except subprocess.TimeoutExpired:
            policy = self.recovery_policy["timeout"]
            self.emit_runtime_event({
                "component": "git_helper",
                "event": "timeout",
                "severity": "warning",
                "details": f"Command timeout after {timeout} seconds"
            })
            return {
                "success": False,
                "return_code": -1,
                "stdout": "",
                "stderr": "Git command execution timed out.",
                "error_type": "timeout",
                "recoverable": policy["recoverable"],
                "recommended_action": policy["recommended_action"]
            }

    def get_tracked_branch(self):
        res = self.run(['rev-parse', '--abbrev-ref', 'HEAD'])
        return res["stdout"] if res["success"] else "DETACHED"

    def get_merge_base(self, commit1, commit2):
        res = self.run(['merge-base', commit1, commit2])
        return res["stdout"] if res["success"] else None
