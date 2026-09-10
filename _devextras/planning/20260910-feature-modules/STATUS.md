# Status — Feature modules

Cross-cutting refactor initiative, recommended before Wave 5 of
[`../20260903_roadmap.md`](../20260903_roadmap.md). Plan of record:
[`00_master_plan.md`](./00_master_plan.md). **Decision checklist (§0) drafted
2026-09-10 — awaiting the product owner's ticks; no code started.**

## Steps

| Sprint / step | Branch / repo | State | Notes |
| ------------- | ------------- | ----- | ----- |
| S1 Inventory & dead weight (FM1–FM5) | — | planned | FM2/FM3 are Ask-first dependency changes (§0 row 10) |
| S2 Registry & descriptors (FM6–FM11) | — | planned | Output-identical refactor; snapshot-tested |
| S3 Gates & lazy registries (FM12–FM17) | — | planned | All 12 `MODULES.GATE_*` flags seeded off |
| S4 CI matrix & rollout (FM18–FM22) | — | planned | Ask-first CI change (§0 row 11); gates flipped one module per PR — record E2E path + run URLs here |
| S5 Compile-time & plugin extraction (FM23–FM26) | — | planned, optional | Cut first if capacity runs out (§0 row 13) |

## Gate rollout log (S4 FM21)

| Module | Gate on for new installs | E2E path | `minimal` run | `full` run |
| ------ | ------------------------ | -------- | ------------- | ---------- |
| tika | — | | | |
| docling | — | | | |
| office_convert | — | | | |
| searxng | — | | | |
| piper_tts | — | | | |
| local_ai | — | | | |
| higgsfield | — | | | |
| google_ai | — | | | |
| thehive | — | | | |
| stripe_billing | — | | | |
| mobile_iap | — | | | |
| whatsapp | — | | | |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-09-10 | Initiative proposed by the research in `../20260910-wave5-architecture-research/02_conditional_module_loading.md`: runtime-gated declared modules instead of compile-time exclusion; vendor slimming first; CI `minimal`/`full` matrix as the proof. |

## Review log

**2026-09-10 (first pass):** master plan and five sprint files drafted against
the verified codebase state (container 2 255 definitions, 470 routes, 18
providers eagerly indexed, `vendor/` 500 MB with 269 MB dead/unused,
`featuresStatus()` 430 lines). Next: product-owner ticks on §0, then technical
plan review (roadmap §7 step 3).
