# File work (secure compute)

> **Status.** Short Python or Node work on copies of files the user chose.
> Off by default. Needs the compute sidecar (`COMPUTE_URL` + `COMPUTE_TOKEN`)
> **and** `COMPUTE.ENABLED`. Persistent folders and website fetches are
> separate switches, also off. The contract stays frozen at `protocol: 1`.

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
| `COMPUTE.ENABLED` | `FEATURE_COMPUTE_ENABLED` | **off** | Sidecar + this switch must both be on. Every `/api/v1/compute/*` route answers **404** when off. |
| `COMPUTE.WORKSPACES_ENABLED` | `FEATURE_COMPUTE_WORKSPACES_ENABLED` | **off** | Keep one folder per user between runs. Files → **Workspace**. The chat card shows **Open workspace** after a finished run. |
| `COMPUTE.EGRESS_ENABLED` | `FEATURE_COMPUTE_EGRESS_ENABLED` | **off** | A run may fetch from a short list of public websites. Off = every run stays offline. Private or local addresses are always refused. |

Both extra flags sit **under** `COMPUTE.ENABLED`. Turning a child on while
file work itself is off does nothing.

A child flag can only be switched on in the admin UI when the connected
sidecar reports the feature in `GET /v1/health` (`features.workspaces`,
`features.egress`); otherwise the save is refused with one sentence and the
switch stays off. **The sidecar shipped in this repository reports
`features.egress: false`** — it creates every container with
`NetworkMode=none` and refuses any non-empty allow-list (`CP22`, the egress
proxy, is not built yet). The PHP side (resolver, pinning, approval) is
complete and waits for that sidecar release.

Operators switch them under **Operate → System configuration → Processing →
File work**. Seeders insert the rows as `0` when missing and never overwrite
an existing value.

```sql
-- what ComputeConfigSeeder runs (BConfigSeeder::insertIfMissing): a no-op
-- when the row exists, so an operator's value is never reset
INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (0, 'COMPUTE', 'ENABLED', '0');
```

The runtime-config endpoint exposes `features.computeEnabled` and
`features.computeWorkspacesEnabled` so the UI can hide the tab and the chip.

## Compose profile (T1)

Dev and self-host use Compose profile `compute` on the same host. That is
**T1**: a hardened Docker container, no host network, no Docker socket in
PHP. Honest limits: one machine, one Docker daemon, no gVisor.

```bash
make -C sidecars/synaplan-compute images
COMPUTE_TOKEN=$(openssl rand -hex 32) COMPUTE_URL=http://compute:8080 \
  COMPUTE_DOCKER_GID=$(stat -c %g /var/run/docker.sock) \
  docker compose --profile compute up -d
```

Never publish port 8080. The sidecar process is distroless `nonroot`. The
runner never pulls images: build Python/Node first or the first run fails
with image-not-found. See [DEVELOPMENT.md](./DEVELOPMENT.md).

## T2 on a separate node

**T2** is the same API with the gVisor `runsc` runtime, on a **separate
compute node** (dedicated host or a separate Docker daemon). Synaplan Cloud
must not enable file work on the shared web hosts at T1. The node layout
lives in the private `synaplan-platform` repo; the sidecar deploy notes are
in `sidecars/synaplan-compute`.

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
time per folder: a second run while one is using it is refused
(`workspace_busy`, "Another file-work run is still using your folder").
The quota holds after the run too — a run that leaves the folder above
`COMPUTE_WORKSPACE_MB` fails with `workspace_quota_exceeded` and the sidecar
removes what that run added; the sentence says so. PHP stores only
the opaque id and the quota — never a host path. The sidecar allocates the
id and enforces ownership (`owner = user:{id}`). Creation is serialised per
user behind a `LOCK_DSN` lock so two first runs in flight cannot leave an
orphaned sidecar folder behind the unique row. The planner learns the node
param (`params.useWorkspace`, and `params.egressHosts` for egress) from the
skill catalog only while the matching flag is on. **Open workspace** is a
chip on a finished run and a sibling tab under Files. Empty: “Files the AI
creates for you will show up here.” A failed load shows the reason with
**Try again**, never the empty state. Delete starts the AI from an empty
folder next time.

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

## Related

- Desktop client (skills on the user’s computer): [DESKTOP.md](./DESKTOP.md)
- Anthropic-compatible Messages gateway: [ANTHROPIC_COMPATIBLE_API.md](./ANTHROPIC_COMPATIBLE_API.md)
- Feature flags: [FEATURE_FLAGS.md](./FEATURE_FLAGS.md)
- User docs (when published): [docs.synaplan.com — Secure compute](https://docs.synaplan.com/modules/compute)
- Plan of record: `_devextras/planning/202609_secure_compute/`
