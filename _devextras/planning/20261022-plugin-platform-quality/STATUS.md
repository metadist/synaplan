# Plugin platform quality — status

Step log for [`00_master_plan.md`](./00_master_plan.md). A walk, a count
and a CI run are entries here, not chat messages.

## Steps

| Date | Step | Pull request | State | Notes |
| ---- | ---- | ------------ | ----- | ----- |
| 2026-10-22 | PQ0 | [#2644](https://github.com/metadist/synaplan/pull/2644) | merged | plan |
| 2026-10-24 | PQ5 | [#2648](https://github.com/metadist/synaplan/pull/2648) | merged | `thinking_seconds` is the server measurement; the length formula is gone |
| 2026-10-24 | PQ6 | [#2649](https://github.com/metadist/synaplan/pull/2649) | merged | one `FilesTable.vue`, `table-fixed` |
| 2026-10-24 | PQ1 | opening | in progress | static checks; synaform is gitignored and its level-5 noise waits for a split |

## Baselines

| Date | What | Value | Source |
| ---- | ---- | ----- | ------ |
| 2026-10-22 | Plugin PHP under `plugins/*/backend` | 4 plugins; php-cs-fixer over `backend/` sees 3,302 files and none of them; over `/plugins/synascriber/backend` it sees 14 files, 1 out of style | `make -C backend lint`; `php-cs-fixer fix --dry-run /plugins/synascriber/backend` |
| 2026-10-22 | Plugin JavaScript | 1,254 lines in four plugins, never linted | `wc -l plugins/*/frontend/*.js plugins/*/frontend/*/*.js` |
| 2026-10-22 | PHPStan findings in plugins | not counted yet (PQ1 commit 1 records it) | — |
| 2026-10-22 | Transcriber image tags today | `latest`, `<version>`, `<major>.<minor>`, short sha; published by a second build under QEMU | `ci.yml` `transcriber-publish` |
| 2026-10-22 | Thinking header after reload | `max(3, floor(length / 100))` seconds; a 4,900–4,999-character block reads "49 seconds" | `frontend/src/utils/messageMapper.ts:215`; #2619 |
| 2026-10-22 | Files desktop table | two copies in `FilesView.vue` (2,989 lines), automatic layout, no bound on the name column | `FilesView.vue` ~955 and ~1329; #2606 |

## Walks

None yet. J-PQ-1 (PQ3), J-PQ-5 (PQ5) and J-PQ-6 (PQ6) are browser walks;
J-PQ-3 (PQ1) is a GitHub walk; J-PQ-4 (PQ2) is two run ids; J-PQ-2 is
walked in synaplan-charts.
