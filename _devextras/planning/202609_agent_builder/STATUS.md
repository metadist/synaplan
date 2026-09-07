# Status — Agent Builder

Track 2 of [`../20260903_roadmap.md`](../20260903_roadmap.md). Plan of record:
[`00_master_plan.md`](./00_master_plan.md). **Decision checklist (§0) ticked 2026-09-03; awaiting technical plan review
before the first sprint starts.**

## Steps

| Sprint / step | Branch / repo | State | Notes |
| ------------- | ------------- | ----- | ----- |
| S1 Entity & pinned runtime | — | planned | |
| S2 Builder & gallery | — | planned | J-AB-1; empty state is the first Vue |
| S3 Publish & versions | — | planned | J-AB-2…4; Share waits on IAM-UX |
| S4 Knowledge, tools, skills | — | planned | |
| S5 Tasks & channels | — | planned | |
| S6 Portability & packs | — | planned | |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-09-03 | Track created from the September 2026 partner feedback; order fixed in the roadmap. |
| 2026-09-03 | All 14 checklist rows accepted. Row 9 widened: this track owns `synaplan-bundle.v1` (section registry, `agents` + `prompts` sections, Settings → Export & import, admin variant) in S6; S6 is no longer the first cut line. |
| 2026-09-03 | Open questions resolved: user memory only; `BROUTABLE` allowed, off by default (snapshot re-record in a dedicated PR); archived assistant → existing chats continue on the last version, no new chats. |
| 2026-09-03 | UI: word **Assistant**; `/channels/agents` label → **Coding clients**; `/ai/assistants` replaces `/ai/instructions` (redirect); form-first builder with the AI Setup Assistant as optional helper. |
| 2026-09-07 | **UX contract.** Journeys J-AB-1…6 in [`../202609_ux_user_flows.md`](../202609_ux_user_flows.md) are binding. S3 Share waits on IAM-UX. A published assistant a group member cannot find in ten seconds is not done. |

## Review log

**2026-09-03 (first pass):** master plan drafted against the verified
codebase state (see roadmap §5).

**2026-09-03 (second pass):** all §0 rows ticked via the product-owner
questionnaire; open questions converted into the master plan's decisions table;
sprint files written. Next: technical plan review (roadmap §7 step 3).

**2026-09-07 (UX contract):** remaining UI sprints name journeys and
walk them before merge (U1–U12). Publish/share is not a second
permission model and not the S2 stacked dialog.
