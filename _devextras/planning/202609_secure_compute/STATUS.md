# Status — Secure Compute

Track 5 of [`../20260903_roadmap.md`](../20260903_roadmap.md). Plan of record:
[`00_master_plan.md`](./00_master_plan.md). **Decision checklist (§0) ticked 2026-09-03.**

## Steps

| Sprint / step | Branch / repo | State | Notes |
| ------------- | ------------- | ----- | ----- |
| A0 Spike & threat model | `sidecars/synaplan-compute` | implemented | Go/no-go: **own Go sidecar**. Threat model + HostConfig dump. |
| A1 Runner MVP | same | implemented | Health, auth, Python/Node images, `TestHostConfigHardening`, hostile corpus scripts. |
| A2 Workspaces & tiers | same | implemented | Workspace layout + contract fixtures. PHP never mounts `docker.sock`. |
| A3 Freeze | — | planned | Wave 5. |
| B1 Client & capability | — | planned | Wave 5 — PHP `ComputeClient` + `COMPUTE.ENABLED`. |
| B2 Tools & policy | — | planned | Wave 5 — `code_run` write-class tool. |
| B3 Workspaces & egress | — | planned | Wave 5. |
| B4 Hardening & GA | — | planned | Wave 5. |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-09-03 | Track created from the September 2026 partner feedback; order fixed in the roadmap. |
| 2026-09-03 | All 16 checklist rows accepted; two tightened: row 6 ships **Python + Node** images in v1 (LibreOffice v2); row 14 makes **T2 (gVisor) on a separate compute node mandatory** before enabling on Synaplan Cloud. |
| 2026-09-03 | Open questions resolved: Go; adopt an OSS runner only on A0-proven parity (isolation ≥ T1, push/pull, egress policy, multi-arch, permissive license, no mandatory extra infra); `sh` allowed inside the sandbox; interactive default `auto`, unattended `approve`. |
| 2026-09-03 | Persistent user workspaces and per-run egress allow-lists stay in B3 (default off). |
| 2026-09-07 | **UX contract.** J-CP-1/2: chat-native run card, quota as a sentence, no new page. Wireframe `compute-run-card.md`. |
| 2026-09-10 | A0–A2 land in this repo under `sidecars/synaplan-compute`. No PHP integration in Wave 4. |

## Review log

**2026-09-03 (first pass):** master plan drafted against the verified
codebase state (see roadmap §5).

**2026-09-03 (second pass):** all §0 rows ticked via the product-owner
questionnaire; open questions converted into the master plan's decisions table;
sprint files written. Next: technical plan review (roadmap §7 step 3).

**2026-09-07 (UX contract):** results stay in the thread. See
[`../202609_ux_user_flows.md`](../202609_ux_user_flows.md) §5.5.

**2026-09-10 (Wave 4 implementation):** sidecar A0–A2 implemented. Phase B waits on
the approval policy (this wave) being enabled in production.

**2026-09-10 (review):** Egress allow-lists stay Wave 5 (B3); A1/A2 do not
implement network policy. PHP still never mounts `docker.sock`.

**2026-09-10 (review follow-up):** `safepath` now `openat`+`O_NOFOLLOW`s every
component including the root; chown uses sandbox uid + service gid so `/out`
listing stays readable; `ValidateHardened` rejects `Env`, recursive binds, and
symlink mount sources; artefact list I/O errors return `500` instead of `200 []`.
