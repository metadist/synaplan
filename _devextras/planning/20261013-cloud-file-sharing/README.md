# Cloud file sharing — Nextcloud, ownCloud.online and OpenCloud on one level

**Status:** Plan, 2026-10-13, reviewed twice the same day (second review:
agent briefs, functional tests, local environments), then updated for the
six Copilot findings on [#2467](https://github.com/metadist/synaplan/pull/2467), and reviewed a third time on 2026-10-14 for the Nextcloud-first order.
No product code. All 46 decision rows of master-plan §3 agreed 2026-10-14
(row 41 with lower caps). Delivery order (row 42): **P1** Synaplan core +
Nextcloud, ending with the core API frozen as contract v1; **P2** OpenCloud;
**P3** ownCloud.online. **2026-10-19:** P1 finalized in
[`11`](./11_nextcloud_release.md) — four milestone releases, the Nextcloud
release at the end, then a **break** until a dated "P2 go" (rows 47–49).
**Plan of record:** [`00_master_plan.md`](./00_master_plan.md).

Asked for on 2026-10-13:

1. Send files Synaplan generated into the cloud space.
2. One login story and one way of handling people across the three apps.
3. Synchronized file handling for AI use: see which cloud files the AI can
   search (bird), watch files and folders (bird with eyes), and run
   automations on what arrives — make searchable, translate, summarize and
   mail me, run my instruction **on the files**. A run always receives files
   or file lists (one file, files that arrived together, the whole folder),
   can pick up more from the cloud, and processes them on Synaplan — also as
   file work in the sandbox. A path alone is never the input
   ([`07`](./07_files_into_runs.md)).

| File | Content |
| ---- | ------- |
| [`00_master_plan.md`](./00_master_plan.md) | Pitch, what "one level" means, decisions to tick, architecture, parity target, waves, invariants, rollout, words |
| [`01_baseline_and_gaps.md`](./01_baseline_and_gaps.md) | Verified state of the three apps and the core, 20 defects, gap matrix, platform constraints |
| [`02_identity.md`](./02_identity.md) | Goal 2 — four connection modes, one identity row, credentials, lifecycle |
| [`03_send_to_cloud.md`](./03_send_to_cloud.md) | Goal 1 — copy Synaplan files into a linked cloud; add from Synaplan inside the cloud |
| [`04_file_state_and_watches.md`](./04_file_state_and_watches.md) | Goal 3 — the bird, the sidebar, watched folders, automations |
| [`05_journeys_and_wireframes.md`](./05_journeys_and_wireframes.md) | Journeys J-CF-1…15, wireframes, copy in five locales |
| [`06_sprints.md`](./06_sprints.md) | Phases P0–P3, spikes CF-S1…S5, sprints CF0–CF6, gates per repo |
| [`07_files_into_runs.md`](./07_files_into_runs.md) | Files, not paths: what a run receives, fetching more files, what Synaplan may read, file work in the sandbox, try it on a file |
| [`08_agent_briefs.md`](./08_agent_briefs.md) | Work orders for coding agents: rules, definition of done, PR template, one brief per spike and step |
| [`09_functional_tests.md`](./09_functional_tests.md) | Per sprint: unit and contract tests, integration scripts, browser walks with expected results; fixtures, accounts, regression set |
| [`10_local_environments.md`](./10_local_environments.md) | Local stacks (Synaplan + Keycloak, Nextcloud, OpenCloud, ownCloud.online): pins, requirements, inventory, cleanup, update briefs E-1…E-9, `env-check.sh` |
| [`11_nextcloud_release.md`](./11_nextcloud_release.md) | **P1 entry point**: four milestone releases, the engineering standard (`synaplan-opencloud`, `synaplan-charts`), test stacks, regression guards, release checklist, the break; briefs CF1.3a–d |
| [`STATUS.md`](./STATUS.md) | Step ledger, decision log, review log |

Reading order for a reviewer: `00` §0–§3 → `01` §4 (defects) → `05` §1
(journeys) → the goal files. For a coding agent in P1: `11` → `08` §0 → the
brief → the sections it cites → `09` for the sprint → `10` for the stack.
