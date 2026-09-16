# Hostile-script corpus (C6)

Every later compute PR must keep this corpus green on T1. Each script
declares its expected outcome in a header the harness parses:

    expected-result: succeeded | failed
    reason: timeout | oom | pids_limit | output_limit | program_error | cancelled | (empty)
    truncated-stdout: true | false
    note: <one line>

Python scripts use `# key: value` lines, the Node mirror under `node/` uses
`// key: value` lines; the header ends at the first non-comment line. Both
mirrors must declare the same result, reason, and truncation.

The sandbox is the boundary — these programs are allowed to attempt hostile
syscalls; the HostConfig must contain them.

## What runs where

- `go test ./tests/hostile/` (hermetic, always): `header_test.go` parses every
  header, checks reasons against the protocol enum, verifies the Node files are
  JavaScript (no `#` lines, no docstrings), and checks that the Python and Node
  mirrors agree. `node --check node/*.js` is part of `make lint`.
- Live execution against a running service is a separate job gated on
  `COMPUTE_HOSTILE_DOCKER=1` plus `COMPUTE_URL` / `COMPUTE_AUTH_TOKEN`
  (`TestHostileLiveCorpusGate`); `run.sh` lists the scripts and prints how to
  submit them. Host-side checks (process count, disk usage, packet capture for
  `dns_attempt`) belong to that job and are not implemented in this repository.

## Reasons the service can and cannot detect

`timeout` (deadline), `oom` (`State.OOMKilled`), `output_limit` (sum of
`/work` + `/out` after the run), `program_error` (non-zero exit), and
`cancelled` are produced by the service. `pids_limit` is **not** detected: a
fork bomb sees `EAGAIN`, exits non-zero, and is reported as `program_error`;
the corpus headers say so.
