# Status — Platform Self-Awareness

Plan of record: `00_master_plan.md`. Implemented on `feat/self-awareness`
in one pass (SA1–SA12) so the CI gate can run before the PR.

## Sprint 1 — inventory and routing

| Step | Branch | State | Notes |
| ---- | ------ | ----- | ----- |
| SA1 — `PlatformCapabilityInventory` + renderer | `feat/self-awareness` | implemented | Dev command `app:selfaware:inventory` |
| SA2 — `synaplan` topic, sorter/planner rules, `general` rewrite, `SelfAwareConfigSeeder` | `feat/self-awareness` | implemented | Seed step 18; planner snapshot re-recorded |
| SA3 — Inventory injection, fast-path guard, `/help`, widget exclusion | `feat/self-awareness` | implemented | `/help` characterization case `cmd_help` |

## Sprint 2 — docs corpus

| Step | Branch | State | Notes |
| ---- | ------ | ----- | ----- |
| SA4 — `synaplan-docs`: `/docs-manifest.json`, `/raw/{slug}.md`, `/llms.txt` | `feat/self-awareness` (docs repo) | implemented | Intercept in `index.php` via `lib/docs_export.php`; `site_url` from `SYNAPLAN_DOCS_SITE_URL` |
| SA5 — Sync service, `app:selfaware:sync-docs`, `SyncPlatformDocsMessage` | `feat/self-awareness` | implemented | Owner 0: no User row ⇒ `VectorizationService` skips rate-limit recording (verified). Embedding model is the system VECTORIZE default. |
| SA6 — Scheduler daily slot + release trigger | `feat/self-awareness` | implemented | Daily `app:selfaware:sync-docs`; `app:updates:check` dispatches the message when the published version changes |

## Sprint 3 — grounded answers and chat UX

| Step | Branch | State | Notes |
| ---- | ------ | ----- | ----- |
| SA7 — `PlatformDocsRetriever`, `[Doc:slug]` context, `docs_loaded` | `feat/self-awareness` | implemented | Owner 0 at query time; ChatHandler + ChatRunner |
| SA8 — Doc pills in `MessageText.vue` | `feat/self-awareness` | implemented | `DocRefPill.ts`; tokens only |
| SA9 — Hint line, `/help` in composer, `features.selfAware` | `feat/self-awareness` | implemented | Five locales; runtime-config flag |

## Sprint 4 — eval and rollout

| Step | Branch | State | Notes |
| ---- | ------ | ----- | ----- |
| SA10 — Eval corpus + `app:selfaware:eval` | `feat/self-awareness` | implemented | Live-model spot check, not in `make test` |
| SA11 — Docs, release checklist, mobile-impact classification | `feat/self-awareness` | implemented | README + `docs/ADMIN.md`; existing `frontend/src/**` ota-candidate / `backend/**` backend-only allow-lists cover new files. `stores/config.ts` remains store-required (pre-existing). |
| SA12 — Rollout (fresh + upgraded install, platform notes) | `feat/self-awareness` | implemented | `app:seed` inserts four `SELF_AWARE` rows (step 18). Local CI gate green: lint, PHPStan, PHPUnit 4652, frontend lint/vitest/vue-tsc. Scheduler daily slot + `app:updates:check` dispatch cover the first corpus fill. |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-09-02 | Two truths, one answer: live per-install inventory is authoritative for "can you X here"; `synaplan-docs` corpus is authoritative for "what/how"; inventory wins on conflict. No hand-maintained feature list; only a tiny reviewed `KNOWN_ABSENT` list. (`00_master_plan.md` Decisions 1–2) |
| 2026-09-02 | Routing via a routable system topic `synaplan` plus the inventory block injected into `general`; `/help` command; fast-path guard only defers to the sorter. Tool-calling rejected for now. (Decision 3) |
| 2026-09-02 | Docs corpus = `SYSTEM:synaplan`, owner 0, fed by a machine-readable manifest published by `synaplan-docs`; raw Markdown ingested; URL configurable for mirrors; no vendoring, no HTML crawl, no GitHub API per query. (Decision 4) |
| 2026-09-02 | Freshness via the daily scheduler slot + a message dispatched when `app:updates:check` sees a new version; never on the boot path. (Decision 5) |
| 2026-09-02 | Corpus stays English; retrieval is cross-lingual; answers follow the language directive. Replaces 4.0 decision 3 (author in four languages). UI strings in all five locales. (Decision 6) |
| 2026-09-02 | Widget conversations excluded by default; pricing never quoted (link only when billing is on); admin hints only for admins. (Decision 8) |
| 2026-09-02 | Flags `SELF_AWARE.{ENABLED, INVENTORY_IN_GENERAL, DOCS_RAG_ENABLED, DOCS_MANIFEST_URL}` default ON / public docs URL. (Decision 9) |

## Investigation baseline (2026-09-02)

- PDF export is `available` iff `OFFICE_CONVERT_URL` is set; otherwise
  `needs_setup` with admin hint `office engine (OFFICE_CONVERT_URL)`.
- Owner 0 has no `User` row, so vectorization does not call
  `RateLimitService::recordUsage` (the `$user` lookup is null). No extra
  whitelist was required.
