#!/usr/bin/env python3
"""
Simulation and Benchmarking Tool for ExpMgWEB Agentic Subsystem (v1.0)
Simulates force-pushes/rebases, concurrent modifications, benchmarks hashing,
and prints a telemetry event dashboard.
"""

import os
import sys
import json
import time
import shutil
import stat
import tempfile
import subprocess
from datetime import datetime, timezone

# Add parent directory to path
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from git_helper import GitHelper
from detect_drift import run_checksum_drift

def remove_readonly(func, path, excinfo):
    # Cross-platform helper to clear read-only permissions before deletion (Windows git-folder fix)
    try:
        os.chmod(path, stat.S_IWRITE)
        func(path)
    except Exception:
        pass

def run_cmd(args, cwd):
    proc = subprocess.run(args, cwd=cwd, capture_output=True, text=True)
    return proc.returncode, proc.stdout, proc.stderr

def print_banner(title):
    print("=" * 60)
    print(f" {title.upper()} ")
    print("=" * 60)

def simulate_rebase_and_force_push():
    print_banner("Simulation 1: Rebases and Force-Pushes")
    
    test_dir = tempfile.mkdtemp()
    try:
        # Initialize Git repo
        run_cmd(["git", "init", "-b", "main"], test_dir)
        run_cmd(["git", "config", "user.name", "Test User"], test_dir)
        run_cmd(["git", "config", "user.email", "test@example.com"], test_dir)
        
        # Initial commit
        file_path = os.path.join(test_dir, "file.py")
        with open(file_path, "w") as f:
            f.write("# Initial line\n")
        run_cmd(["git", "add", "file.py"], test_dir)
        run_cmd(["git", "commit", "-m", "Initial commit"], test_dir)
        
        # Capture commit hash
        _, out, _ = run_cmd(["git", "rev-parse", "HEAD"], test_dir)
        initial_hash = out.strip()
        print(f"Captured initial baseline commit: {initial_hash[:8]}")
        
        # Create a project snapshot
        snapshot = {
            "last_analyzed_commit": initial_hash,
            "accumulated_commit_weight": 0,
            "cursor_state": "synced",
            "reconciliation_elapsed_seconds": 0.0,
            "last_checkpoint_wall": time.time()
        }
        snapshot_path = os.path.join(test_dir, "project_snapshot.json")
        with open(snapshot_path, "w") as f:
            json.dump(snapshot, f)
            
        # Write config.json
        config = {
            "drift_sensitivity": {
                "trigger_2": {
                    "commit_weights": {
                        "config_file_changed": 5,
                        "new_file_type_first_appearance": 5,
                        "new_file_added": 3,
                        "new_directory_created": 4,
                        "file_modified_10_to_50_lines": 2,
                        "file_modified_over_50_lines": 5,
                        "file_deleted": 3
                    },
                    "config_files": ["config.json"],
                    "weight_threshold": 5,
                    "max_commits_per_run": 100
                }
            }
        }
        config_dir = os.path.join(test_dir, ".agents", "core")
        os.makedirs(config_dir, exist_ok=True)
        with open(os.path.join(config_dir, "config.json"), "w") as f:
            json.dump(config, f)
            
        # Create orchestration dir
        os.makedirs(os.path.join(test_dir, ".agents", "orchestration"), exist_ok=True)
        shutil.copy(snapshot_path, os.path.join(test_dir, ".agents", "orchestration", "project_snapshot.json"))
        
        # Simulate rebase by amending the initial commit (rewriting hash)
        with open(file_path, "w") as f:
            f.write("# Rewritten initial line\n")
        run_cmd(["git", "commit", "-a", "--amend", "-m", "Amended initial commit"], test_dir)
        
        _, out, _ = run_cmd(["git", "rev-parse", "HEAD"], test_dir)
        rebased_hash = out.strip()
        print(f"Rebase rewritten commit HEAD to: {rebased_hash[:8]}")
        
        # Run score_commits.py to verify it handles the rewritten hash gracefully
        score_script = os.path.join(os.path.dirname(__file__), "score_commits.py")
        code, sout, serr = run_cmd([sys.executable, score_script, test_dir], test_dir)
        
        print(f"score_commits exit code: {code}")
        try:
            res = json.loads(sout)
            print("Score Commits output:")
            print(json.dumps(res, indent=2))
        except Exception:
            print(f"Raw Output: {sout}\nError: {serr}")
            
    finally:
        shutil.rmtree(test_dir, onerror=remove_readonly)

def simulate_concurrent_mutation():
    print_banner("Simulation 2: Concurrent Mutation Check")
    
    test_dir = tempfile.mkdtemp()
    try:
        os.makedirs(os.path.join(test_dir, "src"), exist_ok=True)
        file_path = os.path.join(test_dir, "src", "worker.py")
        
        # Initial write
        with open(file_path, "w") as f:
            f.write("print('ready')\n")
            
        # Define snapshot state
        snapshot = {
            "last_cache_rebuild": 0,
            "file_counts": {".py": 1},
            "top_directories": ["src"],
            "total_file_count": 1
        }
        
        # Pre-populate directory scan
        os.makedirs(os.path.join(test_dir, ".agents", "orchestration"), exist_ok=True)
        skip_dirs = frozenset([".git"])
        drift_exts = frozenset([".py"])
        run_checksum_drift(test_dir, snapshot, skip_dirs, drift_exts)
        
        # Now modify the file, and immediately run a scan to capture stat concurrency checks
        with open(file_path, "a") as f:
            f.write("print('running concurrently')\n")
            
        metadata = run_checksum_drift(test_dir, snapshot, skip_dirs, drift_exts)
        print("Detected changes during checksum verification:")
        print(json.dumps(metadata, indent=2))
        
    finally:
        shutil.rmtree(test_dir, onerror=remove_readonly)

def benchmark_checksum_mode():
    print_banner("Simulation 3: Checksum Mode Hashing Benchmark")
    
    test_dir = tempfile.mkdtemp()
    try:
        os.makedirs(os.path.join(test_dir, "src"), exist_ok=True)
        
        # Generate 500 files to simulate medium/large codebase changes
        print("Generating 500 mock code files...")
        for i in range(500):
            with open(os.path.join(test_dir, "src", f"file_{i}.py"), "w") as f:
                f.write(f"def func_{i}():\n    return 'val_{i}'\n" * 10)
                
        snapshot = {
            "last_cache_rebuild": 0,
            "file_counts": {".py": 500},
            "top_directories": ["src"],
            "total_file_count": 500
        }
        os.makedirs(os.path.join(test_dir, ".agents", "orchestration"), exist_ok=True)
        skip_dirs = frozenset([".git"])
        drift_exts = frozenset([".py"])
        
        # Cold cache benchmark (full read and hash)
        start_cold = time.perf_counter()
        run_checksum_drift(test_dir, snapshot, skip_dirs, drift_exts)
        cold_duration = time.perf_counter() - start_cold
        
        # Warm cache benchmark (stat checks only)
        start_warm = time.perf_counter()
        run_checksum_drift(test_dir, snapshot, skip_dirs, drift_exts)
        warm_duration = time.perf_counter() - start_warm
        
        print(f"Cold Cache scan (500 files): {cold_duration:.4f} seconds ({500 / cold_duration:.1f} files/sec)")
        print(f"Warm Cache scan (500 files): {warm_duration:.4f} seconds ({500 / warm_duration:.1f} files/sec)")
        
    finally:
        shutil.rmtree(test_dir, onerror=remove_readonly)

def display_telemetry_dashboard():
    print_banner("Simulation 4: Telemetry Event Dashboard")
    
    # Read workspace telemetry file
    project_root = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
    telemetry_path = os.path.join(project_root, "logs", "telemetry.jsonl")
    
    if not os.path.exists(telemetry_path):
        print(f"No telemetry logs found at: {telemetry_path}")
        return
        
    events = []
    try:
        with open(telemetry_path, "r", encoding="utf-8") as f:
            for line in f:
                if line.strip():
                    events.append(json.loads(line))
    except Exception as e:
        print(f"Error reading telemetry: {e}")
        return
        
    print(f"Total Telemetry Events Recorded: {len(events)}")
    print("-" * 60)
    
    # Aggregate statistics
    event_types = {}
    git_commands = {}
    success_count = 0
    failure_count = 0
    
    for ev in events:
        evt = ev.get("event", "unknown")
        event_types[evt] = event_types.get(evt, 0) + 1
        
        if evt == "git_command_executed":
            args = ev.get("args", [])
            cmd = args[0] if args else "unknown"
            git_commands[cmd] = git_commands.get(cmd, 0) + 1
            if ev.get("success", False):
                success_count += 1
            else:
                failure_count += 1
                
    print("Event Type Summary:")
    for evt, count in event_types.items():
        print(f"  - {evt:24}: {count}")
        
    if git_commands:
        print("\nGit Subcommands Executed:")
        for cmd, count in git_commands.items():
            print(f"  - git {cmd:18}: {count}")
        print(f"\nGit command success rate: {success_count} success, {failure_count} failures")

if __name__ == "__main__":
    simulate_rebase_and_force_push()
    simulate_concurrent_mutation()
    benchmark_checksum_mode()
    display_telemetry_dashboard()
