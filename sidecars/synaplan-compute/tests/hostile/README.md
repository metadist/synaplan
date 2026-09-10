# Hostile-script corpus (C6)
#
# Every later compute PR must keep this corpus green on T1. Each script
# declares its expected outcome in a header the harness parses:
#
#   expected-result: succeeded | failed
#   reason: timeout | oom | pids_limit | output_limit | program_error | cancelled | (empty)
#   truncated-stdout: true | false
#
# Python scripts live here; the Node mirror is under node/ with the same
# expectations. The sandbox is the boundary — these programs are allowed
# to attempt hostile syscalls; the HostConfig must contain them.
#
# Host-side checks (process count, disk usage, packet capture for
# dns_attempt) are asserted by tests/hostile/hostile_test.go when dockerd
# is available. Header parsing always runs.

# expected-result header schema is one "# key: value" line per field
# before the first non-comment line.
