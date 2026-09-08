# Status — AI Plugs

Track 3 of [`../20260903_roadmap.md`](../20260903_roadmap.md). Plan of record:
[`00_master_plan.md`](./00_master_plan.md). **Decision checklist (§0) ticked 2026-09-03.**

## Steps

| Sprint / step | Branch / repo | State | Notes |
| ------------- | ------------- | ----- | ----- |
| S1 Ports & refactor | `synaplan/` `main` (#1750) | done | `PL1`–`PL8`: ports, registries, `PLUGS` seeder, Brave gateway, extra-extractor hook. FileProcessor built-in strategies unchanged. |
| S2 Docling | `synaplan/` `feat/wave3-ai-plugs-s2-docling` | implemented | `PL9`–`PL15`: Docling extra extractor, quality gate, markdown chunking, opt-in compose profile, Extraction tab |
| S3 Web search providers | — | planned | SearXNG (+ later Tavily/Exa/…) on `WebSearchRegistry` |
| S4 Rerank | — | planned | Port exists; `RERANK.ENABLED=0` |
| S5 Model import | — | planned | |
| S6 Plugin adapters | — | planned | |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-09-03 | Track created from the September 2026 partner feedback; order fixed in the roadmap. |
| 2026-09-03 | All 15 checklist rows accepted: three ports, refactor-first, Docling sidecar, Brave default + SearXNG first, catalog-managed rerank with eval gate, model import UI, one admin page renamed **AI infrastructure**. |
| 2026-09-03 | Open questions resolved: Perplexity = search adapter (answer capability) **and** optional chat provider; extraction chain instance-only; `TIKA_*` env bootstrap-only; `LlmReranker` included, off; capability probe opt-in. |
| 2026-09-03 | S5 registers the `model_preferences` bundle section with the track-2 registry (roadmap §8.1). |
| 2026-09-07 | **UX contract.** J-PL-1…3: each admin tab leads with a sentence, health, and Test that shows a human result. A down sidecar never fails an upload. |
| 2026-09-07 | **S1 implementation.** FileProcessor is **not** rewritten as a full chain runner in S1 — existing `FileProcessor*Test` constructors stay valid. Built-in strategies remain inside FileProcessor. `PlugConfigService::extraExtractorKeys()` is the S2 unlock (empty on the seeded chains). The six Brave callers go through `WebSearchGateway`. `MessagePreProcessor` still talks to `TikaClient` (allow-listed in `PlugBoundaryTest`). No UI, no new env var, no migration. |
| 2026-09-08 | **S2 implementation.** Docling is a tagged extra extractor; FileProcessor is still not a full chain runner. Quality gate generalizes Tika's PDF `isLowQuality()` and is applied to extras. Markdown chunking is opt-in via `meta.markdown`. Compose profile `docling` uses the CPU image `docling-serve-cpu:v1.32.0` (no `mem_limit` in dev). `DOCLING_BASE_URL` empty = off. |
| 2026-09-08 | **S2 admin parity.** Docling has the same System configuration → Processing fields and connection test as Tika (`DOCLING_BASE_URL`, timeout, max bytes). Feature status lists Docling like Collabora (disabled when the URL is empty). |

## Review log

**2026-09-03 (first pass):** master plan drafted against the verified
codebase state (see roadmap §5).

**2026-09-03 (second pass):** all §0 rows ticked via the product-owner
questionnaire; open questions converted into the master plan's decisions table;
sprint files written. Next: technical plan review (roadmap §7 step 3).

**2026-09-07 (UX contract):** Operate is still a user-flow. See
[`../202609_ux_user_flows.md`](../202609_ux_user_flows.md) §5.3.

**2026-09-07 (S1 implementation):** Wave 3 starts here. Ports live under
`backend/src/Plug/`. Seeded `PLUGS` defaults match sprint §2.3. Golden corpus
covers native text (md/csv/html); recorded Tika/vision/STT responses wait for
S2. Brave fixtures lock the legacy array + AI text. Snapshots are not touched.
