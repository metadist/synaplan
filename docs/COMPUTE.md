# File work (secure compute)

> **Status.** Short Python or Node work on copies of files the user chose.
> Local `docker compose up` starts the sidecar and turns file work +
> workspaces on. Website fetches stay off. The contract stays frozen at
> `protocol: 1`.

## What it is

File work lets the assistant run a short Python or Node program on copies of
files you already picked. The result comes back as files on the same chat
card and in **Files** (`source=compute`). The program runs in an isolated
sidecar — PHP never talks to Docker.

It is **not** a general coding agent, not the desktop client, and not a
replacement for Saved Tasks. It is one capability: a named run with a time,
memory and output cap.

## The feature flags

Everything compute-related is gated on `BCONFIG` group `COMPUTE`. Off means
the surface is absent: no chat card, no Files tab, no API tease (U11).

| Flag | Env pin | Default | Effect |
| ---- | ------- | ------- | ------ |
| `COMPUTE.ENABLED` | `FEATURE_COMPUTE_ENABLED` | **on** (local compose; new seed when URL+token set) | Sidecar + this switch must both be on. Every `/api/v1/compute/*` route answers **404** when off. |
| `COMPUTE.WORKSPACES_ENABLED` | `FEATURE_COMPUTE_WORKSPACES_ENABLED` | **on** (same rule as ENABLED) | Keep one folder per user between runs. Files → **Workspace**. The chat card shows **Open workspace** only when that run actually used the folder. |
| `COMPUTE.EGRESS_ENABLED` | `FEATURE_COMPUTE_EGRESS_ENABLED` | **off** | A run may fetch from a short list of public websites. Off = every run stays offline. Private or local addresses are always refused. |
| `COMPUTE.REQUIRE_TIER` | — | `docker` | Minimum isolation the sidecar must report (`docker` < `gvisor` < `microvm`). Below it, file work stays off even when enabled; the admin UI refuses the switch with the reported tier. |

Both extra flags sit **under** `COMPUTE.ENABLED`. Turning a child on while
file work itself is off does nothing.

A child flag can only be switched on in the admin UI when the connected
sidecar reports the feature in `GET /v1/health` (`features.workspaces`,
`features.egress`). If the health check itself fails, the save is refused
and the switch stays off (fail-closed). **The sidecar shipped in this
repository reports `features.egress: false`** — it creates every container
with `NetworkMode=none` and refuses any non-empty allow-list (`CP22`, the
egress proxy, is not built yet). The PHP side (resolver, pinning, approval)
is complete and waits for that sidecar release.

Operators switch them under **Operate → System configuration → Processing →
File work**. Seeders insert missing rows and never overwrite an existing value.

```sql
-- what ComputeConfigSeeder runs (BConfigSeeder::insertIfMissing): a no-op
-- when the row exists, so an operator's value is never reset.
-- New installs start enabled only when COMPUTE_URL and COMPUTE_TOKEN are
-- set at seed time (ComputeConfigSeeder::enabledSeedValue writes '1');
-- otherwise the row is inserted as '0'. Existing rows are never touched.
INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (0, 'COMPUTE', 'ENABLED', '0');
```

The runtime-config endpoint exposes `features.computeEnabled` and
`features.computeWorkspacesEnabled` so the UI can hide the tab and the chip.

## Local compose (T1)

`docker compose up` starts file work on the same host. That is **T1**: a
hardened Docker container, no host network, no Docker socket in PHP. Honest
limits: one machine, one Docker daemon, no gVisor.

```bash
docker compose up -d
```

Compose builds the sidecar and the Python/Node runtimes from this repo,
creates `.compute-data/`, and injects a local token. Never publish port 8080.
Hide it with `COMPUTE_URL=disabled` or `FEATURE_COMPUTE_ENABLED=false`.

A **new** install seeds `COMPUTE.ENABLED` and `COMPUTE.WORKSPACES_ENABLED`
as `1` when `COMPUTE_URL` and `COMPUTE_TOKEN` are set at seed time (local
compose always sets them). An existing row is never overwritten — local
compose also pins `FEATURE_COMPUTE_ENABLED=true` so an older database still
offers the feature. See [DEVELOPMENT.md](./DEVELOPMENT.md).

## T2 on a separate node

**T2** is the same API with the gVisor `runsc` runtime, on a **separate
compute node** (dedicated host or a separate Docker daemon). Synaplan Cloud
must not enable file work on the shared web hosts at T1. The node layout
lives in the private `synaplan-platform` repo; the sidecar deploy notes are
in `sidecars/synaplan-compute`.

Operational rule for Cloud: `COMPUTE_URL` must not resolve to a web host —
the tier gate (`COMPUTE.REQUIRE_TIER = gvisor`) verifies the isolation, but
only the separate node gives the blast-radius separation. The platform
runbook records the check per deploy.

## Quotas

Per subscription lane, in `RATELIMITS_<LEVEL>`:

| Key | What it caps |
| --- | ------------ |
| `COMPUTE_CONCURRENT` | Runs at the same time |
| `COMPUTE_CPU_SECONDS_DAILY` | CPU-seconds per day |
| `COMPUTE_WORKSPACE_MB` | Size of the persistent folder (used only when workspaces are on) |

A weekly file-work count also applies. Hitting a limit ends the run with one
sentence the owner can understand — not an HTTP code. Nothing new is saved.

Instance defaults (timeout, memory, CPU, output size) are set in the same
File work admin section. Values above the sidecar caps from `GET /v1/health`
are refused on save.

## Provenance and scopes

- Result files land in `BFILES` with `source=compute` (same gallery as other
  generated files). The persistent workspace is **not** ingested or
  vectorized unless the user later saves a file through the normal upload
  path.
- API keys need the `compute:run` scope to offer `code_execution` on the
  `/v1` gateways. Session chat uses the product flags, not that scope.
- Assistants stay opted out until `code_run` is listed on the assistant.

## Policy

Interactive (the owner is present): default `auto`. Unattended (a saved
task): default `approve`. An assistant cannot loosen the instance policy.
A run that will fetch from the web additionally asks first when
`COMPUTE.EGRESS_REQUIRES_APPROVAL` is on (default on); the approval preview
names the websites (`websites: api.example.com`). That ask is a floor the
gate applies after the policy decided (`requireApproval`): an assistant's
always-allow rule or a saved task's `allow_unattended` cannot lower it back
to `auto`, while a `block` still wins. If that switch is on but approvals
cannot be produced (`TOOLS.APPROVALS_ENABLED` off), the run is **refused**,
never run unattended — fail closed.

## Workspaces and egress

**Workspace.** One folder per user (`BCOMPUTEWORKSPACES`), one run at a
time per folder: a second run — or a delete — while one is using it is
refused (`workspace_busy`, "Another file-work run is still using your
folder"). The quota holds after the run too, including cancelled runs: a
run that leaves the folder above `COMPUTE_WORKSPACE_MB` fails with
`workspace_quota_exceeded` (a cancel stays cancelled) and the sidecar
removes what that run added. Empty files and folders count as 4 KiB each.
The sentence never claims "nothing was saved" when `/workspace` was
mounted — a timeout can leave files, and a quota rollback keeps
pre-existing files (including ones this run changed). PHP stores only the
opaque id and the quota — never a host path. `WORKSPACE_TTL_DAYS`
(default 90) is honoured: an idle expired folder is dropped so the next
run gets a new one; a folder a run still holds stays until that run
finishes. The sidecar allocates the id and enforces ownership
(`owner = user:{id}`). Creation is serialised per user behind a `LOCK_DSN`
lock so two first runs in flight cannot leave an orphaned sidecar folder
behind the unique row; if the row then fails to save, the sidecar folder
is deleted. The planner learns the node param (`params.useWorkspace`, and
`params.egressHosts` for egress) from the skill catalog only while the
matching flag is on. **Open workspace** is a chip only on a run that used
the persistent folder (`used_workspace`), plus a sibling tab under Files.
Empty: “Files the AI creates for you will show up here.” A failed load
shows the reason with **Try again**, never the empty state. Delete starts
the AI from an empty folder next time.

**Egress.** The planner may name hosts; PHP reduces each entry to a bare
RFC 1123 host name (scheme, credentials, port and path are dropped;
anything that is not a host name is refused), resolves it through
`SsrfGuard` and pins the public addresses on port 443. The guard blocks
literal names such as `localhost`, every private / loopback / link-local
range and — since B3 — everything IANA lists as not globally routable
(`100.64/10` shared space used by overlay VPNs, `192.0.0/24`, `198.18/15`,
documentation prefixes, 6to4). Too many hosts (default 8) refuses the run —
the list is not silently shortened. With the flag off the allow-list is
empty and the run has no network. Egress never lowers the B2 policy
decision.

## Audit

Each run writes `BCOMPUTERUNS` (owner, limits, workspace id, egress hosts,
sidecar run id). When policy asks first, the pause is a row in `BAPPROVALS`;
the two join on the approval id. The owner finds a pending ask under
**Approvals**.

## Rollback

Turning file work off is two independent switches; either one alone
disables it:

1. **Flag off** (`COMPUTE.ENABLED = 0`, Operate → System config): the
   planner stops offering `code_run` on the next turn, gateways stop
   offering `code_execution`, `/api/v1/compute/*` answers 404, and both
   reaper commands exit idle. Run history (`BCOMPUTERUNS`) and artefacts
   in Files stay readable; the Workspace browser shows the
   not-available state instead of an error.
2. **Sidecar down** (`docker compose stop compute`): the System
   status card degrades to unreachable (counts kept), the page itself
   stays up, and new runs fail honestly instead of hanging.

Rehearsed locally 2026-09-19 (flag flip + profile down, assertions in the
track STATUS). The ten-run staging rehearsal from the B4 plan stays an ops
item for the compute v1.x release.

## Related

- Desktop client (skills on the user’s computer): [DESKTOP.md](./DESKTOP.md)
- Anthropic-compatible Messages gateway: [ANTHROPIC_COMPATIBLE_API.md](./ANTHROPIC_COMPATIBLE_API.md)
- Feature flags: [FEATURE_FLAGS.md](./FEATURE_FLAGS.md)
- User docs (when published): [docs.synaplan.com — Secure compute](https://docs.synaplan.com/modules/compute)
- Plan of record: `_devextras/planning/202609_secure_compute/`
