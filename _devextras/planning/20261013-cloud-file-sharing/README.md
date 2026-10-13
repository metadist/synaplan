# Cloud file sharing — Nextcloud, ownCloud.online and OpenCloud on one level

**Status:** Plan, 2026-10-13. No product code. Master-plan §3 is unticked.
**Plan of record:** [`00_master_plan.md`](./00_master_plan.md).

Asked for on 2026-10-13:

1. Send files Synaplan generated into the cloud space.
2. One login story and one way of handling people across the three apps.
3. Synchronized file handling for AI use: see which cloud files the AI can
   search (bird), watch files and folders (bird with eyes), and run
   automations on what arrives — make searchable, translate, summarize and
   mail me, run my instruction with the path.

| File | Content |
| ---- | ------- |
| [`00_master_plan.md`](./00_master_plan.md) | Pitch, what "one level" means, decisions to tick, architecture, parity target, waves, invariants, rollout, words |
| [`01_baseline_and_gaps.md`](./01_baseline_and_gaps.md) | Verified state of the three apps and the core, 14 defects, gap matrix, platform constraints |
| [`02_identity.md`](./02_identity.md) | Goal 2 — four connection modes, one identity row, credentials, lifecycle |
| [`03_send_to_cloud.md`](./03_send_to_cloud.md) | Goal 1 — copy Synaplan files into a linked cloud; add from Synaplan inside the cloud |
| [`04_file_state_and_watches.md`](./04_file_state_and_watches.md) | Goal 3 — the bird, the sidebar, watched folders, automations |
| [`05_journeys_and_wireframes.md`](./05_journeys_and_wireframes.md) | Journeys J-CF-1…12, wireframes, copy in five locales |
| [`06_sprints.md`](./06_sprints.md) | Spikes CF-S1…S5, sprints CF0–CF6, gates per repo |
| [`STATUS.md`](./STATUS.md) | Step ledger, decision log, review log |

Reading order for a reviewer: `00` §0–§3 → `01` §4 (defects) → `05` §1
(journeys) → the goal files.
