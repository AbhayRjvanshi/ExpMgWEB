#!/usr/bin/env python3
"""
lock_helper.py (v1.0)
Implements atomic file-backed process locking with PID validation, process survival checks,
and jittered exponential backoffs. Enforces single-writer boundary.
"""

import os
import sys
import json
import time
import random
import socket
import subprocess

try:
    import psutil
    HAS_PSUTIL = True
except ImportError:
    HAS_PSUTIL = False

from fault_injection import maybe_crash

def get_process_start_time(pid):
    if HAS_PSUTIL:
        try:
            return psutil.Process(pid).create_time()
        except Exception as e:
            sys.stderr.write(f"Warning: Failed to fetch process start time for PID {pid}: {e}\n")
    return -1.0

def check_pid_survival(pid, start_time=None):
    if HAS_PSUTIL:
        try:
            proc = psutil.Process(pid)
            if start_time is not None and start_time > 0:
                # PID reuse safety: verify start time matches with 100ms tolerance
                return abs(proc.create_time() - start_time) < 0.1
            return True
        except (psutil.NoSuchProcess, psutil.AccessDenied) as e:
            sys.stderr.write(f"Info: Process PID {pid} is no longer active or accessible: {e}\n")
            return False
            
    if sys.platform != 'win32':
        try:
            os.kill(pid, 0)
            return True
        except OSError as e:
            sys.stderr.write(f"Info: Process check failed for PID {pid}: {e}\n")
            return False
    else:
        try:
            out = subprocess.check_output(
                ["tasklist", "/FI", f"PID eq {pid}", "/NH"],
                text=True,
                stderr=subprocess.DEVNULL
            )
            return str(pid) in out
        except Exception as e:
            sys.stderr.write(f"Warning: tasklist check failed: {e}. Defaulting process to dead to prevent lock leakage.\n")
            return False

class OrchestratorLock:
    def __init__(self, project_root, lock_name="orchestrator"):
        self.project_root = os.path.abspath(project_root)
        self.lock_path = os.path.join(self.project_root, '.agents', 'orchestration', f"{lock_name}.lock")
        self.pid = os.getpid()
        self.hostname = socket.gethostname()
        self.start_time = get_process_start_time(self.pid)
        self.has_lock = False

    def acquire(self, max_retries=20, base_delay=0.05):
        if os.environ.get("NESTED_ORCHESTRATION") == "1":
            self.has_lock = False
            return True

        retry_count = 0
        
        while retry_count < max_retries:
            meta = {
                "pid": self.pid,
                "created_at": time.time(),
                "hostname": self.hostname,
                "process_start_time": self.start_time
            }
            try:
                fd = os.open(self.lock_path, os.O_CREAT | os.O_EXCL | os.O_WRONLY)
                try:
                    with os.fdopen(fd, 'w', encoding='utf-8') as f:
                        json.dump(meta, f, indent=2)
                        f.flush()
                    maybe_crash("after_lock_write")
                    self.has_lock = True
                    return True
                except Exception as e:
                    try:
                        os.remove(self.lock_path)
                    except OSError as err:
                        sys.stderr.write(f"Warning: Failed to clean up lock file after write failure: {err}\n")
                    raise e
            except FileExistsError:
                # Lock is already held by another process. Inspect if it is stale.
                try:
                    with open(self.lock_path, 'r', encoding='utf-8') as f:
                        meta_read = json.load(f)
                    lock_pid = meta_read.get("pid")
                    lock_start = meta_read.get("process_start_time", 0.0)
                    lock_host = meta_read.get("hostname", "")
                    
                    if not check_pid_survival(lock_pid, lock_start):
                        # Owner is dead: sweep stale lock file. Re-verify metadata.
                        with open(self.lock_path, 'r', encoding='utf-8') as f_check:
                            current_meta = json.load(f_check)
                        if (current_meta.get("pid") == lock_pid and 
                            current_meta.get("process_start_time") == lock_start and
                            current_meta.get("hostname", "") == lock_host):
                            os.remove(self.lock_path)
                            maybe_crash("during_lock_sweep")
                except (FileNotFoundError, IsADirectoryError):
                    # The file was deleted or is a directory, nothing to sweep
                    pass
                except Exception as e:
                    # Rename corrupt lock file to preserve diagnostic evidence.
                    corrupt_path = self.lock_path + ".corrupt"
                    try:
                        try:
                            os.remove(corrupt_path)
                        except FileNotFoundError:
                            pass
                        os.replace(self.lock_path, corrupt_path)
                        sys.stderr.write(f"Warning: Corrupt lock file detected and archived to {corrupt_path}: {e}\n")
                        maybe_crash("during_lock_sweep")
                    except FileNotFoundError:
                        # Lock file disappeared in the meantime
                        pass
                    except OSError as err:
                        sys.stderr.write(f"Warning: Failed to sweep corrupt lock file: {err}\n")
            except OSError as e:
                sys.stderr.write(f"Warning: Lock file system error during acquire: {e}\n")
                            
            retry_count += 1
            backoff = min(2.0, base_delay * (1.5 ** retry_count))
            jittered_delay = backoff * random.uniform(0.8, 1.2)
            time.sleep(jittered_delay)
            
        return False

    def release(self, max_retries=10, base_delay=0.02):
        if not self.has_lock:
            return
        
        retry_count = 0
        while retry_count < max_retries:
            try:
                with open(self.lock_path, 'r', encoding='utf-8') as f:
                    meta = json.load(f)
                if (meta.get("pid") == self.pid and 
                    meta.get("process_start_time") == self.start_time and
                    meta.get("hostname", "") == self.hostname):
                    maybe_crash("before_lock_release")
                    os.remove(self.lock_path)
                    self.has_lock = False
                    return
                else:
                    # Not our lock (concurrency conflict)
                    self.has_lock = False
                    return
            except FileNotFoundError:
                # Lock file already deleted or released
                self.has_lock = False
                return
            except OSError as e:
                sys.stderr.write(f"Warning: Lock release failed with OS error: {e}\n")
                retry_count += 1
                backoff = min(1.0, base_delay * (1.5 ** retry_count))
                jittered_delay = backoff * random.uniform(0.8, 1.2)
                time.sleep(jittered_delay)
            except Exception as e:
                sys.stderr.write(f"Warning: Lock release failed with transient error: {e}\n")
                retry_count += 1
                time.sleep(0.01)
