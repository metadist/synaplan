# expected-result: succeeded
# reason:
# truncated-stdout: true
# note: Stream truncated server-side at the log cap; run finishes
"""huge_stdout.py — emit more than COMPUTE_LOG_CAP_BYTES of stdout."""
import sys

MARKER = "SYNAPLAN_HUGE_STDOUT_MARKER"
chunk = (MARKER + "\n") * 64
for _ in range(4096):
    sys.stdout.write(chunk)
sys.stdout.flush()
