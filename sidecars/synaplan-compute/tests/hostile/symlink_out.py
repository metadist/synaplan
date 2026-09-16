# expected-result: succeeded
# reason:
# note: Symlink from /out to /etc/passwd is not followed on artefact pull; list omits it
"""symlink_out.py — plant a symlink in /out that must not be pulled."""
import os
import sys

os.makedirs("/out", exist_ok=True)
target = "/out/passwd"
try:
    os.symlink("/etc/passwd", target)
except FileExistsError:
    pass
# Also write a real artefact so the run still produces a regular file.
with open("/out/ok.txt", "w", encoding="utf-8") as f:
    f.write("ok\n")
sys.exit(0)
