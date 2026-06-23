import os
import sys

def maybe_crash(hook_name):
    """
    Support seeded random crashes for fault injection testing.
    Reads configuration parameters from environment variables.
    """
    crash_hook = os.environ.get("TEST_CRASH_HOOK")
    if crash_hook == hook_name:
        seed_str = os.environ.get("TEST_CRASH_SEED")
        if seed_str:
            try:
                import random
                random.seed(int(seed_str))
                prob = float(os.environ.get("TEST_CRASH_PROBABILITY", "1.0"))
                if random.random() <= prob:
                    sys.stdout.flush()
                    sys.stderr.flush()
                    os._exit(1)
            except Exception as e:
                sys.stderr.write(f"[fault_injection] crash hook failed: {e}\n")
