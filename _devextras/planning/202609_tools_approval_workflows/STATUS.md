# Status — Tools, Approval & Workflows

Track 4 of [`../20260903_roadmap.md`](../20260903_roadmap.md). Plan of record:
[`00_master_plan.md`](./00_master_plan.md). **Decision checklist (§0) ticked 2026-09-03; awaiting technical plan review
before the first sprint starts.**

## Steps

| Sprint / step | Branch / repo | State | Notes |
| ------------- | ------------- | ----- | ----- |
| S1 Registry refactor | — | planned | |
| S2 Policy & interactive approval | — | planned | |
| S3 Unattended approval | — | planned | |
| S4 Custom tools | — | planned | |
| S5 Workflow builder v1 + webhook trigger | — | planned | |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-09-03 | Track created from the September 2026 partner feedback; order fixed in the roadmap. |
| 2026-09-03 | All 14 checklist rows accepted: one registry, read/write/destructive, auto/approve/block defaults, pause-and-resume for unattended runs, 72 h expiry, HTTP + OpenAPI custom tools, tools as IAM kind, step-list builder, n8n stays an interface. |
| 2026-09-03 | Open questions resolved: document tools `write → auto` (own-artefact exception); "always allow" = per-user override that never loosens `block`; notifications in-app + email with instant/daily-digest user setting; inbox under Manage → Automations → Approvals. |
| 2026-09-03 | Bundle sections (roadmap §8.1): `mcp_servers` in S1, `custom_tools` in S4, `saved_tasks` in S5 — never credentials or tokens. |

## Review log

**2026-09-03 (first pass):** master plan drafted against the verified
codebase state (see roadmap §5).

**2026-09-03 (second pass):** all §0 rows ticked via the product-owner
questionnaire; open questions converted into the master plan's decisions table;
sprint files written. Next: technical plan review (roadmap §7 step 3).
