# expected-result: failed
# reason: pids_limit
# note: Killed by pids-limit; host load unchanged
"""fork_bomb.py — spawn processes until the cgroup pids limit kills the run."""
import os

while True:
    os.fork()
