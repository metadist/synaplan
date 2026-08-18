# Saved Task Workflows — Status

**Plan:** 2026-08-18 (fifth pass — Phase M)
**Implementation:** foundations, Sprints 0–3, M365 mail-read and WebDAV/CalDAV delivery **merged to `main`** (PR #1497 checkpoint 3 through E19, PR #1502 Nextcloud task flow). E17 (platform cron) still pending in `synaplan-platform`. Current work: **Phase M** on `feat/m365-flow` — [`10_m365_actions_and_destinations.md`](./10_m365_actions_and_destinations.md).
**Gates:** decision checklist fully signed off; connector sign-off rows S5 (test-account owners) and S11 (OpenCloud mechanism) remain open and block the corresponding Phase M steps (M5/M8 live verification).

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
| 09 | [`09_work_breakdown.md`](./09_work_breakdown.md) | PR-sized steps, dependencies, merge order | §0 status current as of 2026-08-16 merge |
| 10 | [`10_m365_actions_and_destinations.md`](./10_m365_actions_and_destinations.md) | **Phase M: Outlook calendar write, M365 mail search, multi-destination documents** | **Active — steps M0–M9** |

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
- **Core output channels are shipped connectors, not n8n workflows** (decided 2026-08-15, [`07_connectors.md` §3.1](./07_connectors.md#31-why-the-core-output-channels-are-ours-not-n8ns-decided-2026-08-15)) — n8n stays the long-tail escape hatch via the outbound webhook, plus a documented interim recipe for O365 mail until C3 exists.
- **The required connector set is locked** (2026-08-15, Tier 1 in [`07_connectors.md` §3](./07_connectors.md#3-connector-inventory)): calendar **read + write** (CalDAV for Nextcloud/ownCloud, Graph for O365 — read is mandatory for duplicate-safe scheduled runs, S13); Jira + Confluence via MCP; files out to Nextcloud/ownCloud/OpenCloud (WebDAV / spike) **plus Dropbox** (own OAuth API, S15) and **SharePoint Online scoped to document-library file drop only** (S14, tenant-admin setup, no on-prem); mail in via IMAP + POP (shipped) + O365 Graph. Consequence: **F3 (OAuth2 framework) is on the critical path** — the no-OAuth half ships first and completes the sovereign story on its own.
- **The four Phase M acceptance utterances are the current bar** (2026-08-18, [`10_m365_actions_and_destinations.md` §1](./10_m365_actions_and_destinations.md#1-the-four-acceptance-utterances)): Outlook calendar write, mail-me-the-invite (regression), mail search + summarize over IMAP *and* M365, document generation pushed to a spoken target. Mutating external actions (S6) decided with this scope: confirmation on interactive runs, `allow_unattended` for schedules, per-call audit.
- **Incremental consent via scope tiers** (mail / calendar / files) — operator-enabled, default off, "Upgrade access" reuses the `reauth_required` UX. New Azure scopes alone do nothing until the tier is enabled and the user re-consents.

## Open decisions blocking implementation

| Ref | Question |
| --- | -------- |
| 07 S3 | Named owner for the Cloud multi-tenant app registration — admin-consent conversation and credential rotation (the model itself is decided) |
| 07 S5 | Who provides the live Nextcloud (files + calendar), OpenCloud, M365, Atlassian and Dropbox test accounts? **Blocks Phase M live verification (M5, M8)** |
| 07 S11 | OpenCloud write mechanism — WebDAV app token, CS3 upload, or reversed token exchange? Spike = Phase M step M8; a token-exchange result needs a **separate security approval** before code |
| 08 L1 | Named native-speaker reviewers for DE, ES, TR |
| 10 D1 | "Put a document into Outlook" semantics — mail-to-inbox now (recommended) vs OneDrive; decide at the M7 review |
| 10 D2 | Calendar channel word `outlook` (recommended) vs `m365`; decide in the M6 PR |

Resolved since the last pass: 00 row 9 (schema shipped: `BSAVEDTASKS`, `BSAVEDTASK_RUNS`, `BCONNECTIONS`, `BCREDENTIALS`), 07 S4 (Graph, decided by implementation), 07 S6 (mutating actions, decided 2026-08-18 with Phase M).

## Review log

**2026-08-14 (second pass):** fixed the production-scheduling assumption (host cron on web1, not the scheduler container); added named compatibility invariants C1–C6 (OIDC login, model change, simple DAG turns, existing crons, API contracts, widget/mobile) with a per-sprint regression suite and CI mapping; defined execution identity for unattended runs (owner-id resolution, no session/OIDC); made trigger columns the single source of truth over graph JSON; pinned run placement (one conversation per task), retention (50 runs / 90 days), rate-limit accounting, and failure behaviour (readable reason, auto-pause after 3 consecutive failures); locked UI progressive disclosure (one card, three controls).

**2026-08-15 (third pass — connectors, UX, step size):** verified the actual connector surface in code and found three gaps the plan had assumed away.

1. **No write path into Nextcloud or OpenCloud exists.** Both integrations are inbound *pull* — the NC app downloads with an admin API key and writes via `IRootFolder`; the OpenCloud extension reads over the CS3/reva gateway using RFC 8693 token exchange. Neither can receive a file from an unattended run. Added the destination seam (F4) and a generic WebDAV client (C10) as prerequisites, plus a timeboxed spike for the OpenCloud mechanism (C11).
2. **Office 365 mail is blocked on an OAuth2 framework we do not have.** Exchange Online rejects Basic auth, and `InboundEmailHandler` is password-only. Reclassified from "a connector" to "F3 first, then C3".
3. **Jira/Confluence writes are blocked by the read-only MCP policy**, not by effort — routed through MCP with an explicit mutating-action decision (S6).

Added [`07_connectors.md`](./07_connectors.md) (inventory, five foundations, per-connector detail sheets, 12 strategic sign-off rows, per-connector readiness checklist, connector testing rules), [`08_ux_and_i18n.md`](./08_ux_and_i18n.md) (five questions every screen answers, canonical terminology in four locales, banned-jargon list, task-card states, shared failure vocabulary, locale-parity CI gate, six comprehension checks) and [`09_work_breakdown.md`](./09_work_breakdown.md) (step-size rules, ~50 PR-sized steps with dependencies and acceptance criteria, merge order, three release checkpoints). Master plan gained checklist rows 13–16; the testing doc gained the i18n parity gate (§3.0.2) and the connector test matrix (§3.0.3).

**2026-08-18 (fifth pass — Phase M, chat actions):** foundations, Sprints 0–3, M365 mail-read consent and WebDAV/CalDAV/Nextcloud delivery merged to `main` (PRs #1497, #1502); master plan §2 table, checklist rows 5/10 and §9/§10 updated to the shipped reality. Product owner raised the acceptance bar to four verbatim chat utterances (Outlook calendar write with `webLink` proof; mail-me-the-invite as a named regression; mail search + summarize across IMAP *and* M365; document with a real DOCX TOC pushed to a spoken target incl. openCloud). New plan file [`10_m365_actions_and_destinations.md`](./10_m365_actions_and_destinations.md): steps M0–M9 (TOC, scope tiers/incremental consent, Graph mail search + body fetch + runner merge + flag seeding, Graph calendar read/write sharing the S13 dedup contract with CalDAV, planner channel naming, "into Outlook" semantics, OpenCloud spike, optional OneDrive), UX contract (one confirm card for every external write, reply must carry proof, upgrade-access state, channel pills), test plan (utterance characterization + E2E, idempotency per backend, scope-gap path, live matrix), and documentation deliverables in both `synaplan/docs/` and the public `synaplan-docs/` site. Sign-offs S4 and S6 recorded as decided; S5 remains the blocker for live verification.

**2026-08-15 (fourth pass — required set locked):** product owner fixed the final connector list. New connectors: **C12 CalDAV calendar read+write** (calendar *read* is now a correctness requirement — deterministic event `UID` + time-range query make scheduled calendar tasks duplicate-safe, sign-off S13; OpenCloud likely has no CalDAV target, to be verified in the C11 spike), **C13 Dropbox** (own OAuth API, not WebDAV, S15). **C4 (M365 calendar) extended to read+write.** **C5 SharePoint scope fixed** to Online document-library file drop with tenant-admin setup; lists/pages/on-prem permanently out (S14). Google Workspace stays deferred; Tier 2 updated accordingly. Work breakdown gained K12a–d, K13a–b, K4a/K4b split, K5a, and release checkpoints 4 (sovereign files + calendar, no OAuth) and 5 (the OAuth family as its own epic — F3 is now on the critical path of the required set).
