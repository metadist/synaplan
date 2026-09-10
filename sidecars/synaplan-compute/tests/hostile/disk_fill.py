# expected-result: failed
# reason: output_limit
# note: Stops at the /work /tmp size caps; host disk unchanged
"""disk_fill.py — write until ENOSPC or the output ulimit."""
import os
import sys

path = "/work/fill.bin"
chunk = b"A" * (1024 * 1024)
written = 0
try:
    with open(path, "wb") as f:
        while True:
            f.write(chunk)
            written += len(chunk)
            f.flush()
            if written > 512 * 1024 * 1024:
                break
except OSError as exc:
    print("stopped:", exc, file=sys.stderr)
    sys.exit(1)
print("filled without hitting a cap", file=sys.stderr)
sys.exit(1)
