# Sprint 4 — Evaluation, documentation, rollout

Status: planned
Date: 2026-09-02
Depends on: sprints 1–3

Three steps. The plan is finished when every row of
`05_eval_question_set.md` passes in `app:selfaware:eval` on a dev install
with a single chat provider key, and a fresh install plus an upgraded
install both come up with the flags present and the corpus filled by the
first scheduler tick.

---

## SA10 — Eval corpus and `app:selfaware:eval`

Branch: `feat/self-aware-eval`

### Backend

- `backend/tests/Eval/self_aware_eval_corpus.json` — the machine-readable
  form of `05_eval_question_set.md`. One object per row:

  ```json
  {
    "id": "Q1",
    "lang": "en",
    "text": "Can you create PDFs?",
    "install": "no_engine",
    "expect": {
      "topic": "synaplan",
      "must_contain_any": ["not", "nicht"],
      "must_mention_any": ["DOCX", "Word"],
      "must_not_contain": ["http", "download", "attached", "€", "$"],
      "docs_cited_any": ["dag-routing", "using-synaplan"],
      "docs_cited_optional": true
    }
  }
  ```

  `install` names one of the fixture profiles the eval sets up before the
  turn (`no_keys`, `no_engine` — one chat key, no TTS, no office engine —,
  `full`). Profiles are applied by toggling the same BCONFIG/env facts the
  inventory reads, never by mocking the inventory.

- `backend/src/Command/SelfAwareEvalCommand.php` — `app:selfaware:eval
  [--corpus=…] [--only=Q1,Q7] [--install=no_engine] [--report=json|table]`,
  modelled on `PlanEvalCommand`. For each row: run classification through
  `MessageClassifier` (assert `expect.topic` — exact for `synaplan` rows,
  "not synaplan" for `N*` rows), then a full `ChatHandler::handle()` turn
  for the eval user, then the string assertions. Prints a table with
  pass/fail per assertion and a summary line
  `passed=NN failed=N skipped=N`; exit code 1 on any failure. Requires a
  live chat model — **not** part of `make test`; documented as the release
  spot-check.
- Deterministic parts move into the normal suite: the routing expectations
  of every row become cases in `RoutingCharacterizationTest` (the sorter is
  snapshotted there already), and the inventory-only rows (`no_keys`
  profile) are asserted against `CapabilityReportRenderer` output in a unit
  test without a model.

### Tests

`tests/Command/SelfAwareEvalCommandTest.php` — corpus loads, `--only`
filters, a failing fixture row yields exit 1 (with the chat provider mocked
to a canned answer). Characterization snapshots re-recorded for the new
routing cases and reviewed.

### Acceptance

```bash
docker compose exec -T backend php bin/console app:selfaware:eval --install=no_engine
docker compose exec -T backend php bin/console app:selfaware:eval --install=full
```

Both end with `failed=0`. Failures are triaged into prompt wording
(`PromptCatalog`), inventory facts, or sorter rules — never into loosening
the corpus assertions.

### Gate

```bash
make -C backend lint && make -C backend phpstan && make -C backend test
```

---

## SA11 — Documentation, release checklist, path classification

Branch: `docs/self-aware`

### `synaplan-docs`

- `docs/using-synaplan.md` — new section "Ask the assistant what it can
  do": the hint line, `/help`, examples ("Can you create PDFs?", "What's
  new?"), the documentation pills, and that answers reflect *this*
  installation.
- `docs/administration.md` — "Self-awareness settings": the four
  `SELF_AWARE.*` settings, what turning each off does, the manifest URL
  override for mirrors and air-gapped installs, `app:selfaware:sync-docs`,
  and that the corpus is refreshed daily and after each release.
- `docs/faq.md` — "Why does the assistant say it cannot do X when the docs
  say it can?" → the inventory/docs precedence in one paragraph.
- Register nothing new in `$docsMap` unless a page is added; the three
  sections above live in existing pages so they are synced automatically.

### This repository

- `README.md` — one feature line under the existing feature table.
- `docs/ADMIN.md` — already extended in SA6; add the eval command.
- **Release checklist** (`docs/RELEASE.md` or wherever the release steps
  live — verify the path): "Review `PlatformCapabilityInventory::KNOWN_ABSENT`
  — remove any entry a shipped feature now provides; add an `alternative`
  for anything newly and deliberately unsupported." This is the only
  hand-maintained list in the feature and this line is what keeps it honest.
- `.github/mobile-impact-policy.json` — classify:
  `backend/src/Service/SelfAware/**`, `backend/src/Command/SelfAware*.php`,
  `backend/src/Message*/SyncPlatformDocs*.php`, `backend/src/Seed/SelfAwareConfigSeeder.php`,
  `backend/tests/Eval/self_aware_eval_corpus.json`, `backend/tests/Fixtures/selfaware/**`
  → backend-only; `frontend/src/components/chat/refs/**` → ota-candidate
  (already covered by `frontend/src/**` if the allow-list is prefix-based —
  confirm); `_docker/**` → no-app-impact. Extend `tests/mobile-impact.test.mjs`
  and run `node scripts/mobile-impact.mjs --base main --head HEAD`.

### Acceptance

`node scripts/mobile-impact.mjs` reports `backend-only` for a backend-only
diff of this plan and `ota-candidate` for SA8/SA9; `docs.synaplan.com`
manifest shows new `sha256` values for the three edited pages and the next
sync picks them up (visible in `app:selfaware:sync-docs` output).

### Gate

Markdown only in this repo, plus the mobile-impact test:

```bash
node --test tests/mobile-impact.test.mjs
```

---

## SA12 — Rollout

Branch: none (operations + `STATUS.md` update)

### Fresh install

`docker compose down -v && docker compose up -d` on the dev stack:
`app:seed` inserts the four `SELF_AWARE` rows and the `synaplan` prompt;
no network call happens during boot (check `docker compose logs backend`
for the absence of `sync-docs`); the first request to "What can you do
here?" answers from the inventory alone; after
`app:selfaware:sync-docs` (or the first scheduler tick) the same question
cites documentation pages.

### Existing install

On a copy of a pre-plan database: container start runs `app:seed`, which
inserts the missing rows and the new prompt without touching existing
`BCONFIG` values or user prompts (`BConfigSeeder::insertIfMissing`,
`PromptSeeder` create-if-absent). A user's custom topic named `synaplan`
(owner > 0) would shadow nothing — system topics and user topics are
distinct rows; verify `[DYNAMICLIST]` lists both and the sorter rule text
refers to the system one.

### Production (`synaplan-platform`)

- The `scheduler` role picks up the daily sync automatically once the image
  contains SA6; no compose change is needed (`SYNAPLAN_ROLE=scheduler`
  already exists).
- Galera: no migration in this plan (C3). BCONFIG writes from the sync are
  single-row `UPDATE`s on owner 0 — no conflict potential across nodes since
  only the scheduler role writes them.
- If the platform egress policy blocks `docs.synaplan.com`, set
  `SELF_AWARE.DOCS_MANIFEST_URL` to an internal mirror of the three
  endpoints from SA4 or to an empty string.

### Definition of done (whole plan)

- `app:selfaware:eval` passes on `no_engine` and `full` profiles.
- All five locales complete; `localeParity.spec.ts` green with no ledger
  additions.
- Characterization snapshots re-recorded exactly twice (SA2, SA3/SA10) and
  each diff explained in its PR.
- Flags off ⇒ byte-identical snapshots to `main` before this plan (C1
  verified by running the characterization suite with `SELF_AWARE.ENABLED=false`
  in the test config once — a one-off check, not a permanent second
  snapshot set).
- `STATUS.md` rows all `merged`, decisions table dated.
