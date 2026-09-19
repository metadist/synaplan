# Status — Secure Compute

Track 5 of [`../20260903_roadmap.md`](../20260903_roadmap.md). Plan of record:
[`00_master_plan.md`](./00_master_plan.md). **Decision checklist (§0) ticked 2026-09-03.**

## Steps

| Sprint / step | Branch / repo | State | Notes |
| ------------- | ------------- | ----- | ----- |
| A0 Spike & threat model | `sidecars/synaplan-compute` | implemented | Go/no-go: **own Go sidecar**. Threat model + HostConfig dump. |
| A1 Runner MVP | same | implemented | Health, auth, Python/Node images, `TestHostConfigHardening`, hostile corpus scripts. |
| A2 Workspaces & tiers | same | implemented | Workspace layout + contract fixtures. PHP never mounts `docker.sock`. |
| A3 Freeze | `feat/wave5-compute-b2` (#1860) | implemented | Protocol 1 fixtures vendored + checksums; compose profile `compute`. `COMPUTE_TOKEN` interpolates empty so `docker compose` works without the profile. |
| B1 Client & capability | same | implemented | FeatureModule `compute`, `ComputeClient`, `code_run`, run card, artefacts as `BFILES` `source=compute`. Flag default off. |
| B2 Tools & policy | same | implemented | `code_execution` is offered only with `compute:run`. Write-class / unattended default `approve`. |
| B3 Workspaces & egress | `main` (#1870) | in progress (repo-local done) | CS18–CS23 on `main` (#1870): flags `COMPUTE.WORKSPACES_ENABLED` and `COMPUTE.EGRESS_ENABLED` default off (seeder `0`), J-CP-2 verified 2026-09-18 (run card keeps “Open workspace”, WorkspaceView reuses FilesTabs, empty copy verbatim, no egress control on the card). Roadmap row-1 bar (flags + J-CP-2) is met, but the track exit (§4) is not: criterion 2 (working egress) is blocked — shipped sidecar reports `features.egress=false` (CP22 proxy not built), switch stays fail-closed off; criterion 4 (docs-site page) needs CS24 (`synaplan-docs`) + CS25 (`synaplan-platform`, private), both excluded from #1870 and still outstanding. |
| B4 Hardening & GA | — | planned | Wave 5. Needs T2 (gVisor) on a compute node before Cloud, plus the CP22 egress-proxy sidecar release for B3 egress. |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-09-03 | Track created from the September 2026 partner feedback; order fixed in the roadmap. |
| 2026-09-03 | All 16 checklist rows accepted; two tightened: row 6 ships **Python + Node** images in v1 (LibreOffice v2); row 14 makes **T2 (gVisor) on a separate compute node mandatory** before enabling on Synaplan Cloud. |
| 2026-09-03 | Open questions resolved: Go; adopt an OSS runner only on A0-proven parity (isolation ≥ T1, push/pull, egress policy, multi-arch, permissive license, no mandatory extra infra); `sh` allowed inside the sandbox; interactive default `auto`, unattended `approve`. |
| 2026-09-03 | Persistent user workspaces and per-run egress allow-lists stay in B3 (default off). |
| 2026-09-07 | **UX contract.** J-CP-1/2: chat-native run card, quota as a sentence, no new page. Wireframe `compute-run-card.md`. |
| 2026-09-10 | A0–A2 land in this repo under `sidecars/synaplan-compute`. No PHP integration in Wave 4. |
| 2026-09-10 | A0–A2 merged to `main` as [#1774](https://github.com/metadist/synaplan/pull/1774). |
| 2026-09-10 | **Wave 5 decisions ticked:** Desktop is not the compute runtime. A3 + B1–B4 stay the next compute strain after Tools S5. `code_run` is write-class / unattended default `approve`. Cloud stays off until T2 on a compute node. Born as a feature module when B1 starts. |
| 2026-09-13 | Research [`01_compute_vs_headless_desktop.md`](../2026-archive/20260910-wave5-architecture-research/01_compute_vs_headless_desktop.md) §7: rows 1–4 and 6 settled (Desktop ≠ runtime; compute-node vocabulary; B1–B4 stay Wave 5; headless Desktop is a Desktop backlog item, not compute; doc stays in this repo). Row 5 (Wave 5 marketing name) stays open. |
| 2026-09-13 | **Compose (CP30):** opt-in `compute` profile builds `sidecars/synaplan-compute` and mounts `docker.sock` **only** on that service. Backend/worker `COMPUTE_URL`/`COMPUTE_TOKEN` stay empty unless the operator sets them. |
| 2026-09-14 | Review follow-up on #1860: unique artefact names, refuse oversized tool input, grant `compute:run` with `desktop:messages`/`desktop:files` (no `*`), enforce concurrent/CPU quotas, cancel the sidecar on PHP wait timeout, unique multipart names, fail missing inputs, document docker GID + runtime-image preload. Workspace MB stays B3. |
| 2026-09-14 | B1/B2 merged as #1860. B3 starts on `feat/wave5-compute-b3` (workspaces + egress, both default off). |
| 2026-09-14 | B3 merged as [#1870](https://github.com/metadist/synaplan/pull/1870) (workspaces + egress behind default-off flags, SSRF widening, fail-closed approval gating, sidecar nested listing + quota). |
| 2026-09-18 | **B3 repo-local scope closed (CS18–CS23 on `main` via #1870).** STATUS corrected (was stale): flags seeded `0`, J-CP-2 verified against the shipped UI. **Not closed:** exit criterion 2 (working egress — CP22 proxy not built, `features.egress=false`) and criterion 4 (docs-site page — CS24 `synaplan-docs` + CS25 `synaplan-platform`, both excluded from #1870). Nothing silently dropped: egress rides with B4/sidecar work, docs with their repos. Corrected after Copilot review on #2009. |

## Review log

**2026-09-03 (first pass):** master plan drafted against the verified
codebase state (see roadmap §5).

**2026-09-03 (second pass):** all §0 rows ticked via the product-owner
questionnaire; open questions converted into the master plan's decisions table;
sprint files written. Next: technical plan review (roadmap §7 step 3).

**2026-09-07 (UX contract):** results stay in the thread. See
[`../20260907_ux_user_flows.md`](../20260907_ux_user_flows.md) §5.5.

**2026-09-10 (Wave 4 implementation):** sidecar A0–A2 implemented and merged
to `main` as #1774. Phase B waits on the approval policy (this wave) being
enabled in production.

**2026-09-10 (review):** Egress allow-lists stay Wave 5 (B3); A1/A2 do not
implement network policy. PHP still never mounts `docker.sock`.

**2026-09-10 (review follow-up):** `safepath` now `openat`+`O_NOFOLLOW`s every
component including the root; chown uses sandbox uid + service gid so `/out`
listing stays readable; `ValidateHardened` rejects `Env`, recursive binds, and
symlink mount sources; artefact list I/O errors return `500` instead of `200 []`.
