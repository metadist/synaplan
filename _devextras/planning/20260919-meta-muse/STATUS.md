# Status — Meta Model API (Muse Spark) + thinking levels

Plan of record: [`00_master_plan.md`](./00_master_plan.md).
**Decision checklist (§0) NOT ticked — research only, no product code.**

## Steps

| Sprint / step | Branch | State | Notes |
| ------------- | ------ | ----- | ----- |
| S1.0 Docs spike | — | planned | Effort param, levels, vision payload, support matrix; fills `01_*` appendix |
| S1.1 Meta provider + catalog | — | planned | `MetaProvider`, `META_API_KEY`, `meta:muse-spark-1.3` rows |
| S1.2 Keys, health, pricing | — | planned | Keystore, health, pricing, docs row, J-MM-1 walk |
| S2.1 Effort levels + resolution | — | planned | `reasoning_efforts` catalog JSON, shared rule, per-user defaults |
| S2.2 Dynamic model config UI | — | planned | Effort picker on the model page, J-MM-2/J-MM-3 |
| S2.3 Matrix + journey specs | — | planned | Provider-request matrix, J-MM-1…3 specs |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-09-19 | Track created from the request to offer Muse Spark + user-set thinking effort. Ten §0 rows proposed, all open. |

## Review log

**2026-09-19 (baseline):** verified against `main`: TrustedTokens provider
pattern, `reasoning` bool plumbing (`MessageController` → `ChatHandler` →
providers), xAI effort resolver + catalog `reasoning_effort_default`,
Anthropic thinking mapping, streamed thinking parts in `ChatView`, model
page + admin models API. No effort levels and no per-model config UI exist.
Meta facts are Docs-Excerpt level (base URL, OpenAI compatibility, 1M
context, model names) — the S1.0 spike re-reads the live docs before code.

**2026-09-19 (Copilot review on #2014):** fixed a typo in `01_*` and pinned
the `off` contract in `02_*`: `reasoning_efforts` is the single
backend/UI contract and carries `off` explicitly iff the model supports
disabling; bool-false maps to `off` or, for always-reasoning models, the
lowest listed level.
