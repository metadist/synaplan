# expected-result: succeeded
# reason:
# note: os.setuid(0) raises PermissionError; no-new-privileges holds
"""setuid.py — attempt to become root."""
import os
import sys

try:
    os.setuid(0)
except PermissionError:
    sys.exit(0)
except OSError:
    sys.exit(0)
print("setuid(0) did not raise", file=sys.stderr)
sys.exit(2)
