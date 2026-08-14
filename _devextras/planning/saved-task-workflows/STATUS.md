# Saved Task Workflows — Status

**Plan draft:** 2026-08-14
**Implementation:** not started
**Code freeze:** none until [decision checklist](./00_master_plan.md#0-decision-checklist-check-before-any-code) is signed off

| Sprint | File | Status |
| ------ | ---- | ------ |
| 0 Observe | [`01_sprint_0_observe.md`](./01_sprint_0_observe.md) | Planned |
| 1 Saved Task model | [`02_sprint_1_saved_task_model.md`](./02_sprint_1_saved_task_model.md) | Planned |
| 2 Graph + triggers | [`03_sprint_2_graph_and_triggers.md`](./03_sprint_2_graph_and_triggers.md) | Planned |
| 3 Scheduler | [`04_sprint_3_scheduler.md`](./04_sprint_3_scheduler.md) | Planned |
| 4 Connectors / plugins / n8n | [`05_sprint_4_connectors_plugins_n8n.md`](./05_sprint_4_connectors_plugins_n8n.md) | Planned |

**Locked product calls (draft — confirm in checklist):**

- Evolve Task Prompts; do not replace `BPROMPTS`.
- **Interface with n8n; do not embed it.**
- v1 calendar output is `.ics` / `email_me`, not Office 365.
- Plugin tools join later via `graphNodes`, not `chatCommands`.
- **Production schedules run via the existing `synaplan-platform` host-cron family** (new `cron-saved-tasks.sh`, Redis-self-locking tick) — the Docker scheduler role is dev/self-host only.

**Review 2026-08-14 (second pass):** fixed the production-scheduling assumption (host cron on web1, not the scheduler container); added named compatibility invariants C1–C6 (OIDC login, model change, simple DAG turns, existing crons, API contracts, widget/mobile) with a per-sprint regression suite and CI mapping (`06_testing_and_documentation.md` §3.0); defined execution identity for unattended runs (owner-id resolution, no session/OIDC); made the trigger columns the single source of truth over the graph JSON; pinned run placement (one conversation per task), run retention (50 runs / 90 days), rate-limit accounting, and failure behaviour (readable reason, auto-pause after 3 consecutive failures with notification); locked UI progressive disclosure (one card, three controls; graph behind "Advanced steps").
