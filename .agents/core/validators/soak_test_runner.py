#!/usr/bin/env python3
"""
soak_test_runner.py (v1.0)
Continuously executes orchestration loops under randomized workload jitter.
Tracks memory metrics (RSS, heap_mb, live objects, descriptors) under profiling/realistic modes.
"""

import os
import sys
import json
import time
import random
import gc
import subprocess
import tracemalloc

# Add parent directory to path to import helpers
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from git_helper import GitHelper

# Handle psutil import gracefully (GAP 2 portability fallback)
try:
    import psutil
    HAS_PSUTIL = True
except ImportError:
    HAS_PSUTIL = False

def get_process_memory_rss():
    if HAS_PSUTIL:
        try:
            return psutil.Process().memory_info().rss / (1024 * 1024)
        except Exception:
            pass
    return -1.0

def get_open_fd_count():
    if HAS_PSUTIL:
        try:
            proc = psutil.Process()
            # Windows uses handles, Unix uses FDs
            if hasattr(proc, "num_handles"):
                return proc.num_handles()
            return proc.num_fds()
        except Exception:
            pass
    return -1

def main():
    project_root = os.path.abspath(sys.argv[1] if len(sys.argv) > 1 else '.')
    soak_mode = os.environ.get("SOAK_MODE", "realistic").lower()
    
    # Duration limits (default to 60 seconds if running as smoke check, or environment overrides)
    run_duration = float(os.environ.get("SOAK_DURATION", "60.0"))
    
    # Load config and determine ceilings
    config_path = os.path.join(project_root, '.agents', 'core', 'config.json')
    try:
        with open(config_path, 'r', encoding='utf-8') as f:
            config = json.load(f)
    except Exception:
        config = {}
        
    log_dir = os.path.join(project_root, 'logs')
    os.makedirs(log_dir, exist_ok=True)
    soak_log_path = os.path.join(log_dir, 'soak_metrics.jsonl')
    
    print(f"Starting Soak Test Runner in '{soak_mode}' mode.")
    print(f"Target duration: {run_duration} seconds. Logging to {soak_log_path}")
    
    if soak_mode == "profiling":
        tracemalloc.start()
        print("tracemalloc profiling enabled.")
        
    start_time_mono = time.monotonic()
    last_gc_check = start_time_mono
    last_tracemalloc_check = start_time_mono
    cycle_count = 0
    
    run_id = f"run_{int(time.time())}"
    
    # Clock drift handling policy: elapsed durations use monotonic time (GAP 8)
    while time.monotonic() - start_time_mono < run_duration:
        cycle_count += 1
        cycle_start_mono = time.monotonic()
        txn_id = f"txn_{run_id}_{cycle_count}"
        
        # 1. Simulate Workload (run detect_drift.py)
        detect_script = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'detect_drift.py')
        try:
            # Inject correlation IDs (GAP 3)
            env = dict(os.environ)
            env["ORCHESTRATION_RUN_ID"] = run_id
            env["ORCHESTRATION_TXN_ID"] = txn_id
            
            proc = subprocess.run(
                [sys.executable, detect_script, project_root],
                env=env,
                capture_output=True,
                text=True,
                timeout=15
            )
        except subprocess.TimeoutExpired:
            print(f"Cycle {cycle_count}: detect_drift.py timed out.", file=sys.stderr)
            
        # 2. Gather cycle-based metrics (RSS and FDs measured every cycle) (FIX 16)
        rss_mb = get_process_memory_rss()
        open_fds = get_open_fd_count()
        
        # 3. Gather periodic metrics (object count every 5 mins, heap snapshot every 10 mins)
        current_mono = time.monotonic()
        
        python_object_count = -1
        if current_mono - last_gc_check >= 300.0 or cycle_count == 1:
            # Sample objects periodically to prevent performance distortion (FIX 16)
            python_object_count = len(gc.get_objects())
            last_gc_check = current_mono
            
        heap_mb = -1.0
        if soak_mode == "profiling" and (current_mono - last_tracemalloc_check >= 600.0 or cycle_count == 1):
            snapshot = tracemalloc.take_snapshot()
            # Calculate heap allocation size in MB
            heap_mb = sum(stat.size for stat in snapshot.statistics('filename')) / (1024 * 1024)
            last_tracemalloc_check = current_mono
            
        # 4. Enforce resource ceiling limit (GAP 7)
        if open_fds > 2048:
            print(f"CRITICAL: Open file descriptor count ({open_fds}) exceeds hard ceiling of 2048. Halting.", file=sys.stderr)
            sys.exit(1)
            
        # Write stats to JSONL (GAP 8: UTC wall clock for logs)
        metrics = {
            "timestamp": datetime_utc_iso(),
            "run_id": run_id,
            "txn_id": txn_id,
            "cycle": cycle_count,
            "soak_mode": soak_mode,
            "metrics": {
                "rss_mb": rss_mb,
                "heap_mb": heap_mb,
                "python_object_count": python_object_count,
                "open_fd_count": open_fds,
                "thread_count": psutil.Process().num_threads() if HAS_PSUTIL else 1
            }
        }
        
        try:
            with open(soak_log_path, 'a', encoding='utf-8') as f:
                f.write(json.dumps(metrics) + '\n')
        except Exception as e:
            print(f"Error writing soak metrics: {e}", file=sys.stderr)
            
        # Workload Jitter Sleep (FIX 2)
        sleep_delay = random.uniform(0.5, 2.0)
        time.sleep(sleep_delay)

def datetime_utc_iso():
    # Helper to return formatted UTC time (GAP 8)
    from datetime import datetime, timezone
    return datetime.now(timezone.utc).isoformat()

if __name__ == '__main__':
    main()
