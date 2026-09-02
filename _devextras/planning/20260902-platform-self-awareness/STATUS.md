# Status — Platform Self-Awareness

Plan of record: `00_master_plan.md`. Work one step at a time; update this
table when a branch is opened, merged, or a decision is taken. The
predecessor `../20260623-release4.0/06_self-aware-routing.md` is superseded
by this plan and is not updated any more.

## Sprint 1 — inventory and routing

| Step | Branch | State | Notes |
| ---- | ------ | ----- | ----- |
| SA1 — `PlatformCapabilityInventory` + renderer | `feat/self-aware-inventory` | planned | Pure library code; ships the dev command `app:selfaware:inventory` |
| SA2 — `synaplan` topic, sorter/planner rules, `general` rewrite, `SelfAwareConfigSeeder` | `feat/self-aware-topic` | planned | Snapshot re-record #1 |
| SA3 — Inventory injection, fast-path guard, `/help`, widget exclusion | `feat/self-aware-injection` | planned | Snapshot re-record #2 (`/help` case) |

## Sprint 2 — docs corpus

| Step | Branch | State | Notes |
| ---- | ------ | ----- | ----- |
| SA4 — `synaplan-docs`: `/docs-manifest.json`, `/raw/{slug}.md`, `/llms.txt` | `feat/docs-manifest` (docs repo) | planned | Separate public repo; schema copied both ways |
| SA5 — Sync service, `app:selfaware:sync-docs`, `SyncPlatformDocsMessage` | `feat/self-aware-docs-sync` | planned | Owner-0 checks (embedding model, rate limit, stale-hit filter) to be recorded here |
| SA6 — Scheduler daily slot + release trigger | `feat/self-aware-docs-schedule` | planned | **Ask-first** (edits `_docker/backend/lib/container-runtime.sh`) |

## Sprint 3 — grounded answers and chat UX

| Step | Branch | State | Notes |
| ---- | ------ | ----- | ----- |
| SA7 — `PlatformDocsRetriever`, `[Doc:slug]` context, `docs_loaded` | `feat/self-aware-docs-retrieval` | planned | Owner 0 at query time |
| SA8 — Doc pills in `MessageText.vue` | `feat/self-aware-doc-pills` | planned | Tokens only; light/dark/V2 check |
| SA9 — Hint line, `/help` in composer, `features.selfAware` | `feat/self-aware-discoverability` | planned | Schema regen; five locales |

## Sprint 4 — eval and rollout

| Step | Branch | State | Notes |
| ---- | ------ | ----- | ----- |
| SA10 — Eval corpus + `app:selfaware:eval` | `feat/self-aware-eval` | planned | Live-model spot check, not in `make test` |
| SA11 — Docs, release checklist, mobile-impact classification | `docs/self-aware` | planned | Edits three `synaplan-docs` pages |
| SA12 — Rollout (fresh + upgraded install, platform notes) | — | planned | |

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

- Nothing from the Release 4.0 self-aware plan exists in code: no `synaplan`
  topic, no system-owned RAG group, no `SELF_AWARE` flags.
- `general` prompt redirects every file request and forbids meta-commentary
  about limitations; capability questions therefore get model guesses.
- Fast path is default-OFF (`CLASSIFIER.FAST_PATH_ENABLED`); the sorter sees
  every message; `[DYNAMICLIST]` excludes `tools:*` only.
- Live capability knowledge is spread over `SkillCatalog`, `ModelConfigService`,
  `ChatReadinessService`, `ConfigController::getRuntimeConfig()`,
  `MultitaskRoutingConfig`, `PluginManager`, `CapabilityService`,
  `UpdateStatusService`; nothing aggregates it.
- RAG is user-scoped at storage level; `CrawlWidgetUrlMessageHandler` is
  the exact ingestion shape needed (deterministic file id, `deleteByFile`,
  `vectorizeAndStore`, no `BFILES` row).
- Scheduler daily slot in `_docker/backend/lib/container-runtime.sh` runs
  `app:updates:check` and `app:digest:run`.
- `synaplan-docs`: custom PHP + CommonMark, 29 flat Markdown pages
  (~209 KB), English only, metadata in `index.php` `$docsMap`, no export.
- PDF output is explicitly unsupported today and becomes install-dependent
  with `../20260902-office-docs/` A0/A4; no music generation exists anywhere.
- Five UI locales: `de`, `en`, `es`, `fr`, `tr`.
