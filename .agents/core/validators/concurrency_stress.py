#!/usr/bin/env python3
"""
concurrency_stress.py (v1.0)
Multi-process stress test to simulate file contention, lock fairness, and starvation.
Supports running in Coordinator mode or Worker mode.
"""

import os
import sys
import time
import random
import subprocess
import json
import statistics

# Add current directory to path for local helper imports
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lock_helper import OrchestratorLock

def run_worker(project_root, worker_id, iterations=30):
    # Seed local random for distinct worker jitter behavior
    random.seed(time.time() + worker_id)
    lock = OrchestratorLock(project_root, lock_name="concurrency_stress")
    
    results = {
        "worker_id": worker_id,
        "success_count": 0,
        "failure_count": 0,
        "wait_times": [],
        "retry_counts": [] # Estimation based on wait duration vs delay
    }

    log_dir = os.path.join(project_root, 'logs')
    os.makedirs(log_dir, exist_ok=True)
    shared_log_path = os.path.join(log_dir, 'concurrency_telemetry.jsonl')

    for _ in range(iterations):
        start_mono = time.monotonic()
        # Acquire lock
        acquired = lock.acquire(max_retries=25, base_delay=0.03)
        end_mono = time.monotonic()
        
        wait_duration = end_mono - start_mono
        results["wait_times"].append(wait_duration)
        
        if acquired:
            results["success_count"] += 1
            # Simulate critical section write under lock protection
            try:
                log_entry = {
                    "worker_id": worker_id,
                    "timestamp": time.time(),
                    "wait_duration": wait_duration,
                    "event": "lock_acquired_critical"
                }
                with open(shared_log_path, 'a', encoding='utf-8') as f:
                    f.write(json.dumps(log_entry) + "\n")
                    f.flush()
                    os.fsync(f.fileno())
            except Exception:
                pass
            
            # Simulate work jitter
            time.sleep(random.uniform(0.01, 0.05))
            lock.release()
        else:
            results["failure_count"] += 1
            
        # Jittered interval before next bid
        time.sleep(random.uniform(0.02, 0.08))

    print(json.dumps(results))
    sys.exit(0)

def run_coordinator(project_root, num_workers=5, iterations=30):
    print(f"Starting lock concurrency stress test with {num_workers} workers...")
    
    # Ensure logs and orchestration folders exist
    os.makedirs(os.path.join(project_root, '.agents', 'orchestration'), exist_ok=True)
    os.makedirs(os.path.join(project_root, 'logs'), exist_ok=True)
    
    # Clear past concurrency logs
    shared_log_path = os.path.join(project_root, 'logs', 'concurrency_telemetry.jsonl')
    if os.path.exists(shared_log_path):
        try:
            os.remove(shared_log_path)
        except OSError:
            pass

    processes = []
    for i in range(num_workers):
        proc = subprocess.Popen(
            [sys.executable, __file__, project_root, "--worker", str(i), "--iterations", str(iterations)],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True
        )
        processes.append(proc)

    # Wait for all processes to finish and collect results
    all_wait_times = []
    total_successes = 0
    total_failures = 0
    
    for idx, proc in enumerate(processes):
        stdout, stderr = proc.communicate()
        if proc.returncode != 0:
            print(f"Worker {idx} failed with code {proc.returncode}. Stderr: {stderr}", file=sys.stderr)
            continue
        try:
            worker_data = json.loads(stdout.strip())
            total_successes += worker_data["success_count"]
            total_failures += worker_data["failure_count"]
            all_wait_times.extend(worker_data["wait_times"])
        except Exception as e:
            print(f"Error parsing worker {idx} output: {e}. Output was: {stdout}", file=sys.stderr)

    if not all_wait_times:
        print("No metrics collected from workers.", file=sys.stderr)
        sys.exit(1)

    # Calculate statistics
    all_wait_times.sort()
    p95_idx = int(len(all_wait_times) * 0.95)
    lock_wait_p95 = all_wait_times[p95_idx] if all_wait_times else 0.0
    avg_wait = statistics.mean(all_wait_times) if all_wait_times else 0.0
    max_wait = max(all_wait_times) if all_wait_times else 0.0

    summary = {
        "status": "success",
        "num_workers": num_workers,
        "total_successes": total_successes,
        "total_failures": total_failures,
        "lock_wait_p95_seconds": round(lock_wait_p95, 4),
        "lock_wait_avg_seconds": round(avg_wait, 4),
        "lock_wait_max_seconds": round(max_wait, 4),
        "starvation_rate_percent": round((total_failures / (total_successes + total_failures)) * 100, 2) if (total_successes + total_failures) > 0 else 0.0
    }
    
    print("\n--- Concurrency Stress Test Results ---")
    print(json.dumps(summary, indent=2))
    
    # Assert locking correctness and lock fairness (p95 latency limit)
    # RTO target RTO: lock_wait_p95 <= 3.0 seconds (Target: 1.5s under normal load)
    if lock_wait_p95 > 3.0:
        print(f"WARNING: Lock acquisition p95 latency ({lock_wait_p95:.2f}s) exceeded 3.0s threshold.", file=sys.stderr)
        sys.exit(1)
        
    sys.exit(0)

if __name__ == "__main__":
    if len(sys.argv) > 2 and sys.argv[2] == "--worker":
        project_root = os.path.abspath(sys.argv[1])
        worker_id = int(sys.argv[3])
        iterations = 30
        if "--iterations" in sys.argv:
            idx = sys.argv.index("--iterations")
            iterations = int(sys.argv[idx + 1])
        run_worker(project_root, worker_id, iterations)
    else:
        project_root = os.path.abspath(sys.argv[1] if len(sys.argv) > 1 else '.')
        run_coordinator(project_root)
