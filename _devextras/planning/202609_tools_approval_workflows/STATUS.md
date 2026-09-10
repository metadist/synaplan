# Status — Tools, Approval & Workflows

Track 4 of [`../20260903_roadmap.md`](../20260903_roadmap.md). Plan of record:
[`00_master_plan.md`](./00_master_plan.md). **Decision checklist (§0) ticked 2026-09-03.**

## Steps

| Sprint / step | Branch / repo | State | Notes |
| ------------- | ------------- | ----- | ----- |
| S1 Registry refactor | `cursor/wave4-tools-approval-compute-469d` | implemented | One `ToolRegistry` + tagged sources. `TOOLS.REGISTRY_ENABLED` code default ON (kill switch). Characterization snapshots untouched. |
| S2 Policy & interactive approval | same | implemented | `ApprovalPolicy` truth table, `ApprovalCard`, inbox at Manage → Automations → Approvals, SSE/realtime, instant/digest notify. Flag off. |
| S3 Unattended approval | same | implemented | Pause/resume Saved Task runs, 72 h expiry, waiting pill on the task card. |
| S4 Custom tools | same | implemented | HTTP + OpenAPI import, SSRF, Connections UI, `custom_tools` + `mcp_servers` bundle sections (never tokens). Flag off. |
| S5 Workflow builder v1 + webhook trigger | — | planned | Wave 5. |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-09-03 | Track created from the September 2026 partner feedback; order fixed in the roadmap. |
| 2026-09-03 | All 14 checklist rows accepted: one registry, read/write/destructive, auto/approve/block defaults, pause-and-resume for unattended runs, 72 h expiry, HTTP + OpenAPI custom tools, tools as IAM kind, step-list builder, n8n stays an interface. |
| 2026-09-03 | Open questions resolved: document tools `write → auto` (own-artefact exception); "always allow" = per-user override that never loosens `block`; notifications in-app + email with instant/daily-digest user setting; inbox under Manage → Automations → Approvals. |
| 2026-09-03 | Bundle sections (roadmap §8.1): `mcp_servers` in S1, `custom_tools` in S4, `saved_tasks` in S5 — never credentials or tokens. |
| 2026-09-07 | **UX contract.** J-TL-1…5 binding. Card + inbox findability is the sharing lesson applied to approvals. Tool/template Share waits on IAM-UX. |
| 2026-09-10 | Wave 4 S1–S4 implemented behind flags. Registry kill-switch defaults on; approvals and custom HTTP stay off. |

## Review log

**2026-09-03 (first pass):** master plan drafted against the verified
codebase state (see roadmap §5).

**2026-09-03 (second pass):** all §0 rows ticked via the product-owner
questionnaire; open questions converted into the master plan's decisions table;
sprint files written. Next: technical plan review (roadmap §7 step 3).

**2026-09-07 (UX contract):** an approval the owner cannot find after
closing the tab is not governance. See
[`../202609_ux_user_flows.md`](../202609_ux_user_flows.md) §5.4.

**2026-09-10 (Wave 4 implementation):** S1–S4 on `cursor/wave4-tools-approval-compute-469d`.
S5 stays Wave 5.
