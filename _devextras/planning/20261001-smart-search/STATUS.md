# Smart Search — status

| Step | State | Note |
| ---- | ----- | ---- |
| S0 | done | Master plan and sprint file |
| S1 | done | Palette, local MiniSearch index across all five locales, commands, recents; walked light/dark/320 px |
| S2 | done | `BSEARCHINDEX` + listener/queue + `app:search:reindex` + lazy per-user backfill; `POST /api/v1/search` (index + admin settings, RRF, 120/min). `BEMBED` nullable without `VECTOR INDEX` (MariaDB indexes only NOT NULL vectors; every query filters by user first). |
| S3 | in progress | |
| S4 | open | |
| S5 | open | |
| S5b | open | |
| S6 | open | |
