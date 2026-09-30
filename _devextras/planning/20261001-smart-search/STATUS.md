# Smart Search — status

| Step | State | Note |
| ---- | ----- | ---- |
| S0 | done | Master plan and sprint file |
| S1 | done | Palette, local MiniSearch index across all five locales, commands, recents; walked light/dark/320 px |
| S2 | done | `BSEARCHINDEX` + listener/queue + `app:search:reindex` + lazy per-user backfill; `POST /api/v1/search` (index + admin settings, RRF, 120/min). `BEMBED` nullable without `VECTOR INDEX` (MariaDB indexes only NOT NULL vectors; every query filters by user first). |
| S3 | done | One query embedding shared by index, RAG chunks, memories and digests when they sit in the same model space. Cut-off is adaptive (catalog mean + 0.17), so a model with constant vectors (dev test provider) yields no semantic hits instead of noise. Stopwords + switching verbs in five locales; up to three terms are required, a relaxed OR pass fills the rest. Settings catalog is indexed at user 0 and re-embedded per schema fingerprint. Palette merges remote groups with frecency and keeps the keyboard selection on late results. Walked: chat created → indexed → found → opened, light/dark/320 px. |
| S4 | done | Setting hits carry an `action` only for database-backed boolean/select fields that are neither sensitive nor managed elsewhere; env-sourced fields link only, an `envOverride` shows "pinned". Scope is `system` only — no user-scope endpoint exists yet, so none is promised. Every change asks with a consequence sentence, writes through the existing `PUT /api/v1/admin/config/{key}`, then offers Undo for 10 s. Deep link `/admin/config?tab&section&highlight` opens the section and rings the field. Walked J-SR-3: search → toggle → confirm → Undo → restore → open row → ringed field; light/dark/320 px. Ranking of strong setting hits under weak page/command hits is noted for S6 eval. |
| S5 | open | |
| S5b | open | |
| S6 | open | |
