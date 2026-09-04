# Status — AI Plugs

Track 3 of [`../20260903_roadmap.md`](../20260903_roadmap.md). Plan of record:
[`00_master_plan.md`](./00_master_plan.md). **Decision checklist (§0) ticked 2026-09-03; awaiting technical plan review
before the first sprint starts.**

## Steps

| Sprint / step | Branch / repo | State | Notes |
| ------------- | ------------- | ----- | ----- |
| S1 Ports & refactor | — | planned | |
| S2 Docling | — | planned | |
| S3 Web search providers | — | planned | |
| S4 Rerank | — | planned | |
| S5 Model import | — | planned | |
| S6 Plugin adapters | — | planned | |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-09-03 | Track created from the September 2026 partner feedback; order fixed in the roadmap. |
| 2026-09-03 | All 15 checklist rows accepted: three ports, refactor-first, Docling sidecar, Brave default + SearXNG first, catalog-managed rerank with eval gate, model import UI, one admin page renamed **AI infrastructure**. |
| 2026-09-03 | Open questions resolved: Perplexity = search adapter (answer capability) **and** optional chat provider; extraction chain instance-only; `TIKA_*` env bootstrap-only; `LlmReranker` included, off; capability probe opt-in. |
| 2026-09-03 | S5 registers the `model_preferences` bundle section with the track-2 registry (roadmap §8.1). |

## Review log

**2026-09-03 (first pass):** master plan drafted against the verified
codebase state (see roadmap §5).

**2026-09-03 (second pass):** all §0 rows ticked via the product-owner
questionnaire; open questions converted into the master plan's decisions table;
sprint files written. Next: technical plan review (roadmap §7 step 3).
