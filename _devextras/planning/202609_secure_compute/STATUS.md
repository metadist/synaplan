# Status — Secure Compute

Track 5 of [`../20260903_roadmap.md`](../20260903_roadmap.md). Plan of record:
[`00_master_plan.md`](./00_master_plan.md). **Decision checklist (§0) ticked 2026-09-03; awaiting technical plan review
before the first sprint starts.**

## Steps

| Sprint / step | Branch / repo | State | Notes |
| ------------- | ------------- | ----- | ----- |
| A0 Spike & threat model | — | planned | |
| A1 Runner MVP | — | planned | |
| A2 Workspaces & tiers | — | planned | |
| A3 Freeze | — | planned | |
| B1 Client & capability | — | planned | |
| B2 Tools & policy | — | planned | |
| B3 Workspaces & egress | — | planned | |
| B4 Hardening & GA | — | planned | |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-09-03 | Track created from the September 2026 partner feedback; order fixed in the roadmap. |
| 2026-09-03 | All 16 checklist rows accepted; two tightened: row 6 ships **Python + Node** images in v1 (LibreOffice v2); row 14 makes **T2 (gVisor) on a separate compute node mandatory** before enabling on Synaplan Cloud. |
| 2026-09-03 | Open questions resolved: Go; adopt an OSS runner only on A0-proven parity (isolation ≥ T1, push/pull, egress policy, multi-arch, permissive license, no mandatory extra infra); `sh` allowed inside the sandbox; interactive default `auto`, unattended `approve`. |
| 2026-09-03 | Persistent user workspaces and per-run egress allow-lists stay in B3 (default off). |

## Review log

**2026-09-03 (first pass):** master plan drafted against the verified
codebase state (see roadmap §5).

**2026-09-03 (second pass):** all §0 rows ticked via the product-owner
questionnaire; open questions converted into the master plan's decisions table;
sprint files written. Next: technical plan review (roadmap §7 step 3).
