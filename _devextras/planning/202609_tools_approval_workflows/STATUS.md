# Status — Tools, Approval & Workflows

Track 4 of [`../20260903_roadmap.md`](../20260903_roadmap.md). Plan of record:
[`00_master_plan.md`](./00_master_plan.md). **Decision checklist (§0) ticked 2026-09-03.**

## Steps

| Sprint / step | Branch / repo | State | Notes |
| ------------- | ------------- | ----- | ----- |
| S1 Registry refactor | `main` (#1774) | implemented | One `ToolRegistry` + tagged sources. `TOOLS.REGISTRY_ENABLED` code default ON (kill switch). Characterization snapshots untouched. |
| S2 Policy & interactive approval | same | implemented | `ApprovalPolicy` truth table, `ApprovalCard`, inbox at Manage → Automations → Approvals, SSE/realtime, instant/digest notify. On for new installs (#1827). |
| S3 Unattended approval | same | implemented | Pause/resume Saved Task runs, 72 h expiry, waiting pill on the task card. |
| S4 Custom tools | same | implemented | HTTP + OpenAPI import, SSRF, Connections UI, `custom_tools` + `mcp_servers` bundle sections (never tokens). On for new installs (#1827). |
| S5 Workflow builder v1 + webhook trigger | `main` (#1859) | implemented | Steps + webhook (#1821). TL41 templates/copy checklist, TL45 `saved_tasks` bundle, TL46 C7 fixtures + five-step proof, TL47 docs. Builder flag seeded on for new installs (#1827). Q1 (approve continues the chat turn) stays open. |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-09-03 | Track created from the September 2026 partner feedback; order fixed in the roadmap. |
| 2026-09-03 | All 14 checklist rows accepted: one registry, read/write/destructive, auto/approve/block defaults, pause-and-resume for unattended runs, 72 h expiry, HTTP + OpenAPI custom tools, tools as IAM kind, step-list builder, n8n stays an interface. |
| 2026-09-03 | Open questions resolved: document tools `write → auto` (own-artefact exception); "always allow" = per-user override that never loosens `block`; notifications in-app + email with instant/daily-digest user setting; inbox under Manage → Automations → Approvals. |
| 2026-09-03 | Bundle sections (roadmap §8.1): `mcp_servers` in S1, `custom_tools` in S4, `saved_tasks` in S5 — never credentials or tokens. |
| 2026-09-07 | **UX contract.** J-TL-1…5 binding. Card + inbox findability is the sharing lesson applied to approvals. Tool/template Share waits on IAM-UX. |
| 2026-09-10 | Wave 4 S1–S4 implemented behind flags. Registry kill-switch defaults on; approvals and custom HTTP stay off. |
| 2026-09-10 | Wave 4 S1–S4 merged to `main` as [#1774](https://github.com/metadist/synaplan/pull/1774). |
| 2026-09-10 | **Wave 5 decisions ticked (roadmap §7.2, first PR):** (1) Recoverable jobs — this strain ships honest per-outcome copy and keeps existing pause/resume; checkpoints and per-run spend limits stay scheduled. (2) Publish ≠ activated — stays on Agent Builder STATUS; not this PR. (3) Approvals on resume — re-check current permissions and hard blocks immediately before execute; bind the approval to tool + arguments (a change voids it). (4) Sovereignty as a job-wide setting — scheduled, not this PR. (5) Complete-workflow packs / For you landing — scheduled. Form-first assistant builder and step-list editor stay the product shape. |
| 2026-09-13 | **S5 remainder is the next Tools work.** TL38–TL40, TL42–TL44 are on `main`. Do TL41, TL45–TL47 and walk J-TL-5 before Compute B1. Q1 (approve continues the chat turn) stays a Wave 5 row; it is not a substitute for closing S5. |
| 2026-09-14 | S5 closed on `main` as [#1859](https://github.com/metadist/synaplan/pull/1859). This branch no longer carries that work. Q1 stays open. |

## Review log

**2026-09-03 (first pass):** master plan drafted against the verified
codebase state (see roadmap §5).

**2026-09-03 (second pass):** all §0 rows ticked via the product-owner
questionnaire; open questions converted into the master plan's decisions table;
sprint files written. Next: technical plan review (roadmap §7 step 3).

**2026-09-07 (UX contract):** an approval the owner cannot find after
closing the tab is not governance. See
[`../202609_ux_user_flows.md`](../202609_ux_user_flows.md) §5.4.

**2026-09-10 (Wave 4 implementation):** S1–S4 on `cursor/wave4-tools-approval-compute-469d`,
merged to `main` as #1774. S5 stays Wave 5.

**2026-09-10 (review):** Approve-to-auto loosening (`allow_unattended`, always-allow)
now applies; resumed DAG nodes are marked approved so the gate is not consulted
again; custom HTTP pins DNS, caps streamed bodies, and rejects a templated
origin. The scheduler expires pending approvals hourly and sends the daily
digest. Interactive chat approvals still execute the tool on approve but do
**not** continue the turn as a new assistant message — deferred to Wave 5.

**2026-09-13 (S5 remainder):** Templates are IAM shares (`saved_task` + `use`).
Copy/import stay paused, strip secrets, regenerate webhook tokens, and return a
checklist instead of 409 when the assistant or a tool is missing. The
`saved_tasks` bundle section depends on `prompts` and `mcp_servers`. Five-step fixture
`builder_five_step.json` plus the shipped graphs load through the validator and
plan factory. Track directory stays here until this branch is on `main`.

**2026-09-13 (S5 Copilot follow-up):** Copy/import strip inbound-email `accountId`
(remap only when the destination owns that mailbox or has exactly one), refuse
disabled assistants/MCP servers, keep remappable `mcp:ServerName:tool` names,
never export a dangling numeric MCP id, recompute `nextRunAt` on copy/import
and when a schedule is enabled, and always use template wording for Use template.
