# Saved Task Workflows — Status

**Plan draft:** 2026-08-15 (third pass)
**Implementation:** not started
**Code freeze:** none until **both** gates are signed off — the [decision checklist](./00_master_plan.md#0-decision-checklist-check-before-any-code) and the [connector sign-off](./07_connectors.md#7-sign-off-gate-tick-before-any-connector-code)

## Documents

| # | File | Role | Status |
| - | ---- | ---- | ------ |
| 00 | [`00_master_plan.md`](./00_master_plan.md) | Decisions, architecture, invariants | Draft, 16 checklist rows |
| 01 | [`01_sprint_0_observe.md`](./01_sprint_0_observe.md) | Visualize executed DAGs | Planned |
| 02 | [`02_sprint_1_saved_task_model.md`](./02_sprint_1_saved_task_model.md) | Persist Saved Tasks; Run now | Planned |
| 03 | [`03_sprint_2_graph_and_triggers.md`](./03_sprint_2_graph_and_triggers.md) | Authored graph + triggers | Planned |
| 04 | [`04_sprint_3_scheduler.md`](./04_sprint_3_scheduler.md) | User-facing schedules | Planned |
| 05 | [`05_sprint_4_connectors_plugins_n8n.md`](./05_sprint_4_connectors_plugins_n8n.md) | Plugin nodes, n8n interface | Planned |
| 06 | [`06_testing_and_documentation.md`](./06_testing_and_documentation.md) | Gate, regression suite, docs | Draft |
| 07 | [`07_connectors.md`](./07_connectors.md) | **Connector inventory + sign-off gate** | Draft — **blocks implementation** |
| 08 | [`08_ux_and_i18n.md`](./08_ux_and_i18n.md) | UX contract + four-language comprehension | Draft |
| 09 | [`09_work_breakdown.md`](./09_work_breakdown.md) | PR-sized steps, dependencies, merge order | Draft |

## Locked product calls (draft — confirm in the checklists)

- Evolve Task Prompts; do not replace `BPROMPTS`.
- **Interface with n8n; do not embed it.**
- v1 calendar output is `.ics` / `email_me`, not Office 365.
- Plugin tools join later via `graphNodes`, not `chatCommands`.
- **Production schedules run via the existing `synaplan-platform` host-cron family** (new `cron-saved-tasks.sh`, Redis-self-locking tick) — the Docker scheduler role is dev/self-host only.
- **Build the five connector foundations before any individual connector**; generic WebDAV is the first connector, with Nextcloud as a preset.
- **Jira/Confluence via MCP**, not bespoke clients.
- **Sequencing is foundations-first, not parallel** (decided 2026-08-15, row S1) — the engine waits on the seams rather than building against a destination that does not exist.
- **Microsoft 365 uses a Synaplan Cloud multi-tenant app registration; self-hosters register their own** (decided 2026-08-15, row S3) — the self-host path must be documented and fully supported, not a fallback.

## Open decisions blocking implementation

| Ref | Question |
| --- | -------- |
| 07 S3 | Named owner for the Cloud multi-tenant app registration — admin-consent conversation and credential rotation (the model itself is decided) |
| 07 S5 | Who provides the live Nextcloud, OpenCloud, M365 and Atlassian test accounts? |
| 07 S11 | OpenCloud write mechanism — WebDAV app token, CS3 upload, or reversed token exchange? Needs a spike, then a **separate security approval** if it implies Synaplan holding a long-lived credential that acts as the user |
| 08 L1 | Named native-speaker reviewers for DE, ES, TR |
| 00 row 9 | Schema ask before the first migration lands |

## Review log

**2026-08-14 (second pass):** fixed the production-scheduling assumption (host cron on web1, not the scheduler container); added named compatibility invariants C1–C6 (OIDC login, model change, simple DAG turns, existing crons, API contracts, widget/mobile) with a per-sprint regression suite and CI mapping; defined execution identity for unattended runs (owner-id resolution, no session/OIDC); made trigger columns the single source of truth over graph JSON; pinned run placement (one conversation per task), retention (50 runs / 90 days), rate-limit accounting, and failure behaviour (readable reason, auto-pause after 3 consecutive failures); locked UI progressive disclosure (one card, three controls).

**2026-08-15 (third pass — connectors, UX, step size):** verified the actual connector surface in code and found three gaps the plan had assumed away.

1. **No write path into Nextcloud or OpenCloud exists.** Both integrations are inbound *pull* — the NC app downloads with an admin API key and writes via `IRootFolder`; the OpenCloud extension reads over the CS3/reva gateway using RFC 8693 token exchange. Neither can receive a file from an unattended run. Added the destination seam (F4) and a generic WebDAV client (C10) as prerequisites, plus a timeboxed spike for the OpenCloud mechanism (C11).
2. **Office 365 mail is blocked on an OAuth2 framework we do not have.** Exchange Online rejects Basic auth, and `InboundEmailHandler` is password-only. Reclassified from "a connector" to "F3 first, then C3".
3. **Jira/Confluence writes are blocked by the read-only MCP policy**, not by effort — routed through MCP with an explicit mutating-action decision (S6).

Added [`07_connectors.md`](./07_connectors.md) (inventory, five foundations, per-connector detail sheets, 12 strategic sign-off rows, per-connector readiness checklist, connector testing rules), [`08_ux_and_i18n.md`](./08_ux_and_i18n.md) (five questions every screen answers, canonical terminology in four locales, banned-jargon list, task-card states, shared failure vocabulary, locale-parity CI gate, six comprehension checks) and [`09_work_breakdown.md`](./09_work_breakdown.md) (step-size rules, ~50 PR-sized steps with dependencies and acceptance criteria, merge order, three release checkpoints). Master plan gained checklist rows 13–16; the testing doc gained the i18n parity gate (§3.0.2) and the connector test matrix (§3.0.3).
