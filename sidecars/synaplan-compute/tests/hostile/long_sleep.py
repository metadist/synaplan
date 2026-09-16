# expected-result: failed
# reason: timeout
# note: Killed at timeoutSec; run failed; no zombie container
"""long_sleep.py — sleep longer than any T1 timeout."""
import time

time.sleep(3600)
