# expected-result: failed
# reason: program_error
# note: os.fork raises BlockingIOError at the pids limit and the run exits 1; host load unchanged
"""fork_bomb.py — spawn processes until the cgroup pids limit refuses more."""
import os

while True:
    os.fork()
