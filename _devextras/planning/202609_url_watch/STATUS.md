# Status — URL watch

Wave 3 companion. Plan: [`00_sprint.md`](./00_sprint.md).

## Steps

| Step | State | Notes |
| ---- | ----- | ----- |
| UW1 Snapshot table + service + line diff | done | `BURLWATCHES`, one row per owner+URL |
| UW2 `url_fetch` compare path + planner 9b2 | done | `inputs.compare: true` + message heuristic |
| UW3 CRUD API + Saved Tasks UI | done | list / get / create / refresh / delete |
| UW4 Gate + docs | done | unfiltered make targets green; `MULTITASK_DATA_NODES.md` |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-09-08 | One server-side version only. Dropbox copy is later, not v1. |
| 2026-09-08 | No new capability or flag. Reuse `url_fetch` + `URL_FETCH_ENABLED`. |
| 2026-09-08 | CRUD lives on Saved Tasks. Deleting a task does not delete the watch. |
