import os
import sys
import json
import time
from fault_injection import maybe_crash

def load_json(path):
    with open(path, 'r', encoding='utf-8') as f:
        return json.load(f)

def save_snapshot_atomic(path, data):
    """
    Writes snapshots atomically. Returns success flag.
    Uses fsync for absolute durability on Unix. Note that directory-level fsync
    via O_DIRECTORY is not supported on Windows, so directory durability guarantees
    are best-effort on Windows platforms.
    """
    tmp_path = path + '.tmp'
    try:
        with open(tmp_path, 'w', encoding='utf-8') as f:
            json.dump(data, f, indent=2)
            f.flush()
            os.fsync(f.fileno()) # Fsync required on snapshot save
        maybe_crash("before_replace")
        os.replace(tmp_path, path)
        
        if hasattr(os, "O_DIRECTORY"):
            try:
                dir_fd = os.open(os.path.dirname(path), os.O_DIRECTORY)
                try:
                    os.fsync(dir_fd)
                finally:
                    os.close(dir_fd)
            except OSError as e:
                sys.stderr.write(f"Warning: Failed to fsync snapshot directory: {e}\n")
        return True
    except OSError as e:
        sys.stderr.write(f"Error: Atomic write failed for {path}: {e}\n")
        return False

def cleanup_stale_tmp_files(directory, max_age_seconds=60):
    current_time = time.time()
    try:
        for filename in os.listdir(directory):
            if filename.endswith(".tmp"):
                filepath = os.path.join(directory, filename)
                try:
                    stat_info = os.stat(filepath)
                    if current_time - stat_info.st_mtime > max_age_seconds:
                        os.remove(filepath)
                except OSError as e:
                    sys.stderr.write(f"Warning: Failed to clean temp file {filepath}: {e}\n")
    except OSError as e:
        sys.stderr.write(f"Warning: Failed to scan directory {directory} for temp files: {e}\n")
