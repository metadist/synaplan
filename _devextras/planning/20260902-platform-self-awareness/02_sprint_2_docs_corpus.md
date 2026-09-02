# Sprint 2 — The docs corpus (`SYSTEM:synaplan`)

Status: implemented
Date: 2026-09-02
Depends on: `00_master_plan.md` (Decisions 4, 5), sprint 1 (`SelfAwareConfig`)

Three steps. SA4 is a small PR in the separate public repo `synaplan-docs`;
SA5 and SA6 are in this repo. After this sprint the vector store holds the
29 documentation pages under owner 0 / `SYSTEM:synaplan`, refreshed daily
and after each release, and nothing in the chat uses them yet (sprint 3).

Invariants in play: C2 (no network on boot), C3 (no schema), C4 (corpus
invisible to users).

---

## SA4 — `synaplan-docs`: machine-readable export

Repo: `synaplan-docs` (branch `feat/docs-manifest`)

The docs site is a custom PHP front controller (`index.php`) rendering
`docs/*.md` with League CommonMark; page metadata is `$docsMap`
(`file`, `nav`, `short`, `title`, `desc`, `keywords`) and section order is
`$sections`. There is no export today. Add three endpoints, all generated
from `$docsMap` so a new page registered there is exported automatically:

### `GET /docs-manifest.json`

```json
{
  "schema": "synaplan-docs-manifest/1",
  "generated_at": "2026-09-02T09:00:00Z",
  "site_url": "https://docs.synaplan.com",
  "version": "2026.09",
  "pages": [
    {
      "slug": "dag-routing",
      "title": "Multi-Task (DAG) Routing",
      "section": "Developers",
      "description": "AI task planning, the capability set, live task cards …",
      "url": "https://docs.synaplan.com/dag-routing",
      "raw_url": "https://docs.synaplan.com/raw/dag-routing.md",
      "sha256": "…hex…",
      "bytes": 9123,
      "updated_at": "2026-08-30T14:11:02Z"
    }
  ]
}
```

- `sha256` and `bytes` are computed from the Markdown file on disk;
  `updated_at` from `filemtime()`. Cache the manifest per request only
  (the site has no build step); `Cache-Control: public, max-age=300`.
- `version` = the year-month of the newest `updated_at` (there is no docs
  versioning; this is a human-readable hint only).
- Exclude `docs.bak/` and any slug whose `file` does not exist. Include the
  `intro` page once (it is aliased to `/`).
- `site_url` from the existing config (`config.local.php` may override it
  for a mirror) — never hardcoded.

### `GET /raw/{slug}.md`

Serves the Markdown file for a registered slug as `text/markdown; charset=utf-8`
with the same `Cache-Control`. Unknown slug → 404. Only slugs present in
`$docsMap` are served (no path traversal — the slug is a key lookup, not a
file path).

### `GET /llms.txt`

The conventional plain-text index for agents: one line per page,
`- [title](url): description`, grouped by section, with a two-line preamble
naming the product and the manifest URL. Generated from the same array.

### Docs-repo housekeeping

- `README.md`: a short "Machine-readable export" section (three URLs, the
  schema id, how a mirror sets `site_url`).
- `docs/contributing.md`: a sentence that every page must be registered in
  `$docsMap` **also** because it is what the Synaplan chat learns from.
- `sitemap.php`: list `/llms.txt` (not the manifest, not raw files).
- Router (`router.php` / `.htaccess` / Caddy snippet in the README): the
  three new paths must reach `index.php`; document the Caddy rule change.

### Acceptance

`curl -s https://<dev-host>/docs-manifest.json | jq '.pages | length'`
prints 29; `sha256sum docs/dag-routing.md` equals the manifest value;
`curl -I /raw/nope.md` is 404; `/llms.txt` lists every section. The
manifest validates against `backend/tests/Fixtures/selfaware/docs-manifest.schema.json`
(added in SA5 — copy it into the docs repo's `tests/` too so both sides
gate the same contract).

### Gate

The docs repo has no CI gate; `php -l index.php`, a manual smoke of the
three URLs, and the schema validation above.

---

## SA5 — Sync service and command

Branch: `feat/self-aware-docs-sync`

### Backend

`backend/src/Service/SelfAware/Docs/`:

- `DocsManifest.php`, `DocsPage.php` — `final readonly` DTOs mirroring the
  manifest schema; `DocsManifest::fromJson(string): self` validates
  `schema === 'synaplan-docs-manifest/1'` and rejects pages without
  `slug|url|raw_url|sha256`. Slugs are validated `^[a-z0-9-]{1,64}$`.
- `PlatformDocsManifestClient.php` — `fetchManifest(string $url): DocsManifest`,
  `fetchPage(DocsPage): string` using `HttpClientInterface` (timeout 15 s,
  max 2 MB per page, `Accept: text/markdown`), one retry on transport
  error, none on 4xx. Only `https://` URLs; `raw_url` must share the
  manifest's host (a manifest cannot point the sync at arbitrary hosts).
- `PlatformDocsSyncState.php` — reads/writes BCONFIG
  `SELF_AWARE.DOCS_SYNC_STATE` (owner 0) as JSON
  `{ "manifest_url", "manifest_version", "synced_at", "pages": { slug: { sha256, file_id, title, url, section, synced_at } } }`
  through `ConfigRepository`; exposes `pageByFileId(int): ?array` for
  sprint 3.
- `PlatformDocsSyncService.php` — `sync(bool $force = false, bool $dryRun = false): DocsSyncResult`:
  1. `manifestUrl = SelfAwareConfig::docsManifestUrl()`; empty ⇒ return
     `skipped`.
  2. Fetch manifest; on failure return `failed` with the reason, **leave
     the state and the vectors untouched**.
  3. Diff: for each manifest page, `changed = force || state.sha256 !== page.sha256`;
     `removed = state.pages − manifest.pages`.
  4. For each changed page: fetch Markdown; `fileId = abs(crc32("docs:{slug}")) % 2_000_000_000`
     (the `CrawlWidgetUrlMessageHandler::buildFileId` formula, own prefix);
     `vectorStorage->deleteByFile(0, $fileId)`; prefix
     `"Source: {url}\nTitle: {title}\nSection: {section}\n\n"`; strip the
     `<!--SYNAPLAN_MODELS_TABLE-->` placeholder and HTML comments;
     `vectorizationService->vectorizeAndStore($text, 0, $fileId, 'SYSTEM:synaplan', 0)`.
  5. For each removed page: `deleteByFile(0, fileId)`.
  6. Write the new state (only after the loop; a failed page keeps its
     previous entry and is reported).
  `DocsSyncResult` carries counts (`changed`, `unchanged`, `removed`,
  `failed`) and per-slug messages for the command output.

  Owner 0 consequences to verify while implementing (record the outcome in
  `STATUS.md`): `ModelConfigService::getDefaultModel('VECTORIZE', 0)` must
  resolve the system default; `RateLimitService` inside
  `VectorizationService` must not throttle or reject user 0 (bypass or
  whitelist explicitly if it does); `EmbeddingMetadata::filterStaleHits(userId: 0)`
  must see the same model id at query time. If any of these needs a change,
  it is a one-line, flag-independent fix in that service, not a fork of the
  pipeline.

- `SyncPlatformDocsMessage.php` (`backend/src/Message/`) +
  `SyncPlatformDocsMessageHandler.php` (`backend/src/MessageHandler/`),
  routed to `async_index` in `config/packages/messenger.yaml` next to
  `ReVectorizeMessage`. The handler just calls `sync()` and logs the result.
- `backend/src/Command/SelfAwareSyncDocsCommand.php` —
  `app:selfaware:sync-docs [--force] [--dry-run] [--async]`; prints a
  `SymfonyStyle` table (slug, action, chunks) and exits non-zero only on a
  manifest fetch failure (page-level failures are warnings, like
  `app:updates:check` treats an unreachable manifest as an expected
  outcome).
- `SelfAwareConfig::docsManifestUrl()` (sprint 1) is the single place the
  URL is read.

### Tests

- `tests/Unit/Service/SelfAware/Docs/DocsManifestTest.php` — parses the
  fixture `tests/Fixtures/selfaware/docs-manifest.json` (a trimmed 5-page
  copy of the real one); rejects wrong schema id, bad slug, cross-host
  `raw_url`.
- `PlatformDocsSyncServiceTest.php` — mocked client + in-memory
  `VectorStorageInterface` + mocked `VectorizationService`:
  first run vectorizes 5 pages; second run with identical manifest does
  nothing; one changed hash ⇒ one `deleteByFile` + one `vectorizeAndStore`;
  one removed slug ⇒ one `deleteByFile`; manifest failure ⇒ state untouched,
  result `failed`; `--dry-run` calls neither.
- `tests/Command/SelfAwareSyncDocsCommandTest.php` — table output, exit
  codes.
- `tests/Fixtures/selfaware/docs-manifest.schema.json` — JSON schema used by
  the manifest test and copied to `synaplan-docs` (SA4).

### Must NOT touch

`ChatHandler`, `VectorSearchService`, `KnowledgeContextFormatter`, any
frontend file, `container-runtime.sh` (that is SA6).

### Acceptance

Dev stack with Qdrant (or MariaDB VECTOR) and an embedding model:

```bash
docker compose exec -T backend php bin/console app:selfaware:sync-docs
docker compose exec -T backend php bin/console app:selfaware:sync-docs        # all "unchanged"
docker compose exec -T backend php bin/console app:selfaware:sync-docs --dry-run
```

First run reports 29 changed pages and a chunk count; second run reports 0.
`GET /api/v1/rag/...` group listings for the demo user do **not** show
`SYSTEM:synaplan`; a direct `VectorSearchService::semanticSearch('how do I
embed the widget', 0, 'SYSTEM:synaplan')` from a throwaway script returns
`widget` chunks. `docker compose restart backend` performs **no** sync (C2).

### Gate

```bash
make -C backend lint && make -C backend phpstan && make -C backend test
```

---

## SA6 — Scheduler wiring and release trigger

Branch: `feat/self-aware-docs-schedule`
**Ask-first step:** edits `_docker/backend/lib/container-runtime.sh`.
Open the PR only after explicit go-ahead; change nothing but the daily slot.

### Docker runtime

In the daily block of `container-runtime.sh` (the one that runs
`app:updates:check` and `app:digest:run`), add:

```sh
if ! run_scheduler_command bin/console --env="$env" app:selfaware:sync-docs --no-interaction; then
    runtime_log "Documentation sync failed; it will be retried on the next daily interval." >&2
fi
```

after `app:updates:check` (so a version bump detected in the same tick is
already recorded). Extend `_docker/backend/tests/test-container-runtime.sh`
with the corresponding assertion, following the existing update-check case.

### Backend

- `CheckUpdatesCommand` / `UpdateStatusService`: when the recorded published
  version **changes**, dispatch `SyncPlatformDocsMessage` (through
  `MessageBusInterface`; no-op when the bus has no transport in tests). The
  daily sync stays as the safety net; the message makes a release reach the
  corpus within minutes.
- `docs/ADMIN.md` (this repo): a short "Documentation knowledge for the
  assistant" section — what is synced, from where, the `SELF_AWARE.DOCS_MANIFEST_URL`
  override for mirrors/air-gapped installs, the manual command, and that the
  corpus is owner 0 and never shown to users.

### Tests

- `tests/Command/CheckUpdatesCommandTest.php` — new version ⇒ one message
  dispatched; same version ⇒ none.
- Runtime script test as above.

### Acceptance

- Start the dev stack with `SYNAPLAN_ROLE=scheduler` for the backend (or run
  the daily block by hand as the existing runtime test does): the log shows
  one `app:selfaware:sync-docs` line after the update check.
- Point `SELF_AWARE.DOCS_MANIFEST_URL` at an unreachable host: the command
  logs the failure, exits non-zero, the previous corpus still answers.
- Set it to an empty string: the command prints `skipped` and exits 0.

### Gate

```bash
make -C backend lint && make -C backend phpstan && make -C backend test
bash _docker/backend/tests/test-container-runtime.sh
```
