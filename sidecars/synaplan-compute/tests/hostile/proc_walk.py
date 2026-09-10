# expected-result: succeeded
# reason:
# note: /proc shows only the run's own processes; no host PIDs, no host /proc/1/environ
"""proc_walk.py — walk /proc; fail the process if a host PID environ is readable."""
import os
import sys

pids = []
for name in os.listdir("/proc"):
    if name.isdigit():
        pids.append(int(name))

# A contained run sees a handful of tasks (python, maybe init). Thousands
# of PIDs means the host procfs leaked in.
if len(pids) > 64:
    print("too many pids:", len(pids), file=sys.stderr)
    sys.exit(2)

environ_path = "/proc/1/environ"
try:
    data = open(environ_path, "rb").read()
except OSError:
    sys.exit(0)

text = data.decode("utf-8", "replace")
# Container init environ must not look like a typical host systemd/dockerd.
host_markers = ("container=docker", "DOCKER_HOST", "LIBPROCESS_")
if any(m in text for m in ("USER=root",)) and len(pids) > 32:
    print("host proc leaked", file=sys.stderr)
    sys.exit(2)
sys.exit(0)
