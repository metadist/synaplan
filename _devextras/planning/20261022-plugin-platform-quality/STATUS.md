# Plugin platform quality — status

Step log for [`00_master_plan.md`](./00_master_plan.md). A walk, a count
and a CI run are entries here, not chat messages.

## Steps

| Date | Step | Pull request | State | Notes |
| ---- | ---- | ------------ | ----- | ----- |
| 2026-10-22 | PQ0 | [#2644](https://github.com/metadist/synaplan/pull/2644) | in review | plan; Copilot round 1: four findings, all accepted (§10); All Checks Passed green on the first head |

## Baselines

| Date | What | Value | Source |
| ---- | ---- | ----- | ------ |
| 2026-10-22 | Plugin PHP under `plugins/*/backend` | 4 plugins; php-cs-fixer over `backend/` sees 3,302 files and none of them; over `/plugins/synascriber/backend` it sees 14 files, 1 out of style | `make -C backend lint`; `php-cs-fixer fix --dry-run /plugins/synascriber/backend` |
| 2026-10-22 | Plugin JavaScript | 1,254 lines in four plugins, never linted | `wc -l plugins/*/frontend/*.js plugins/*/frontend/*/*.js` |
| 2026-10-22 | PHPStan findings in plugins | not counted yet (PQ1 commit 1 records it) | — |
| 2026-10-22 | Transcriber image tags today | `latest`, `<version>`, `<major>.<minor>`, short sha; published by a second build under QEMU | `ci.yml` `transcriber-publish` |

## Walks

None yet. J-PQ-1 (PQ3) and J-PQ-3 (PQ1) are browser and GitHub walks;
J-PQ-4 (PQ2) is two run ids; J-PQ-2 is walked in synaplan-charts.
