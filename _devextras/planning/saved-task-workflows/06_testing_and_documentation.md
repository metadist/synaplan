# Testing and documentation (all sprints)

This file is the quality contract for [`00_master_plan.md`](./00_master_plan.md). A sprint is **not done** when the feature works on a laptop. It is done when this file’s gate is green and the sprint’s Documentation table is updated.

---

## 1. Principles

1. **Unfiltered gate = CI.** `phpunit --filter` and `phpstan analyse <path>` are diagnostic only. Finish with the commands in §2.
2. **Deterministic and offline.** No live LLM, IMAP, Graph, or n8n. TestProvider, fake HTTP, in-memory Messenger, fixed clock.
3. **Characterization is a contract.** Sorter / classifier / planner / fast-path changes require snapshot re-record **and a reviewed diff**. Silent re-record is a defect.
4. **Widget invariant.** Every sprint that touches routing or frontend chat must keep ChatWidget E2E green and must not run Saved Tasks inside the widget.
5. **Four locales.** Any user-visible string: `frontend/src/i18n/{en,de,es,tr}.json` in the **same** change.
6. **OpenAPI → Zod.** New/changed HTTP: annotations complete, then `make -C frontend generate-schemas`, then `vue-tsc`. No hand-written API interfaces.
7. **Mobile impact.** New paths go into `.github/mobile-impact-policy.json` and `tests/mobile-impact.test.mjs`. Default: PHP/scheduler = `backend-only`; AI Instructions / graph UI = `ota-candidate`. Never `store-required` for this epic.
8. **Galera.** Prod-reachable migrations: raw idempotent `addSql` only. See `docs/MIGRATIONS.md`.
9. **No secrets in tests or fixtures.** HMAC secrets = `test-secret`; URLs = `https://hooks.example.test/...`.

---

## 2. Mandatory local gate (every sprint)

From `synaplan/` repo root, Docker stack up:

```bash
make lint \
  && make -C backend phpstan \
  && make test \
  && docker compose exec -T frontend npm run check:types \
  && make -C frontend test
```

If only docs under `_devextras/planning/` changed: no PHP/Vue gate required (planning is not CI-gated). **User-facing `docs/**` changes that land with code** ride with the sprint PR and must still pass the gate if code changed.

PHPStan must analyse `src/` **and** `tests/` (the make target already does).

If OpenAPI annotations changed:

```bash
make -C frontend generate-schemas
docker compose exec -T frontend npm run check:types
```

If routing changed:

```bash
docker compose exec -T -e UPDATE_ROUTING_SNAPSHOTS=1 backend \
  ./vendor/bin/phpunit tests/Characterization/RoutingCharacterizationTest.php
git diff backend/tests/Characterization/__snapshots__/
```

Review every snapshot line. Commit snapshots only with an explanation in the PR body.

Cache permission trap after `docker compose down`:

```bash
docker compose exec -T backend sh -c \
  'rm -rf var/cache/test && mkdir -p var/cache/test && chmod -R 777 var/cache/test'
```

---

## 3. Test matrix by sprint

### 3.0 Compatibility regression suite (every sprint)

These map 1:1 to the master plan's [named invariants §7.0](./00_master_plan.md#70-named-compatibility-invariants--synaplan-must-stay-compatible-with-its-earlier-self). They run in **every** sprint PR that touches backend routing, security config, or the chat pipeline — not only when someone remembers:

| Inv. | Test | Where |
| ---- | ---- | ----- |
| C1 | OIDC / session login E2E stays green (existing auth spec — do not rewrite it, just keep it green). New route diff review: `security.yaml` changes limited to the stateless webhook-ingress path | Existing E2E + PR review checklist |
| C1 | Saved Task runner has **no** `Security`/token dependency (constructor-level unit test; PHPStan will also flag an unused injection) | `backend/tests/Unit/.../SavedTaskRunnerTest.php` |
| C2 | Per-conversation model switch E2E stays green; new: a Saved Task run resolves the owner's *current* default model (change default → next run uses it) | Existing model-switch spec + new unit on the runner |
| C3 | Routing characterization snapshots: plain chat fast-path, single-node, combo multi-node — **byte-identical with zero Saved Task graphs in fixtures**. New snapshot cases cover the short-circuit *only* with a graph present | `backend/tests/Characterization/` |
| C3 | E2E: plain chat and one combo request (TestProvider) unchanged with flag ON but no Saved Tasks defined | Existing chat E2E |
| C4 | `cron-gmail.sh`, `cron-media-reaper.sh` byte-identical (platform-repo PR review); `app:process-mail-handlers` / `app:process-emails` test suites untouched and green | Platform PR review + existing PHPUnit |
| C5 | MCP `tools/list` and `/v1` contract tests: additive only (snapshot of tool names must be a superset, never a mutation) | Existing MCP/OpenAI-compat tests |
| C6 | Widget E2E (chat + flow editor) green in every sprint; no Saved Task UI or execution inside the widget bundle | Existing widget specs |

A failing row here **blocks the sprint** regardless of how green the new-feature tests are.

### 3.0.1 CI steps (what actually gates the PR)

No new CI workflow is required — all new tests slot into the existing `CI` jobs, which is the point (the gate stays one workflow):

| CI job (existing) | Covers in this epic |
| ----------------- | ------------------- |
| PHP Code Formatting | New backend classes |
| Backend — PHPStan | Entities, repos, runner, tick command, `tests/` |
| Backend — PHPUnit | All unit/integration/characterization rows above |
| Frontend — lint / vue-tsc / Vitest | Editor, runs list, generated Zod schemas |
| Mobile impact (`mobile-impact.mjs`) | New paths classified (`backend-only` / `ota-candidate`) — unlisted paths fail closed |

Additions **inside** existing jobs (small, explicit):

1. **Guard test — no n8n in the stack:** a PHPUnit (or `.mjs`) test asserting no service named `n8n` exists in `docker-compose*.yml`. Cheap insurance for checklist row 1.
2. **Characterization job discipline:** any PR labeled `saved-tasks` that touches `Service/Message/` or `Service/Multitask/` must include a snapshot diff (empty diff is fine, but the run must be shown). Enforced by review checklist, not new tooling.
3. **Platform repo:** `synaplan-platform` has no CI gate for cron scripts — the Sprint 3 platform PR is reviewed by hand against §1.1 of the sprint file (web1-only, Redis-locked command, logrotate-covered log name).

### Sprint 0 — Observe

| ID | Layer | File (suggested) | Assert |
| -- | ----- | ---------------- | ------ |
| 0.1 | Vitest | `ExecutedPlanGraph.spec.ts` | Fixture plan → N nodes, M edges; empty → no graph |
| 0.2 | Vitest | Task Prompts config | Save-as-task visible; **no** HTTP persist |
| 0.3 | E2E | chat or task-prompts | TestProvider multi-node turn shows plan disclosure |
| 0.4 | E2E | widget | Unchanged |
| 0.5 | i18n | CI / grep | keys in all four locale files |

### Sprint 1 — Model + Run now

| ID | Layer | File (suggested) | Assert |
| -- | ----- | ---------------- | ------ |
| 1.0 | Unit | `ChatRunnerTest` | `params.topic_id` loads custom prompt + meta |
| 1.1 | Unit | `SavedTaskConfigTest` | user → global → false |
| 1.2 | Unit | `SavedTaskServiceTest` | cross-user 404/403 |
| 1.3 | Feature | `SavedTaskRunTest` | create → run → run row + message_id in the task's dedicated `chat_id` conversation |
| 1.3a | Unit | runner identity | no `Security`/session dependency (C1); user resolved by owner id |
| 1.3b | Unit | failure accounting | readable `error`, `consecutive_failures` inc/reset; over-budget run fails cleanly |
| 1.4 | Feature | flag off | endpoints 403/hidden |
| 1.5 | Vitest | Run now dialog | empty message blocked; success toast |
| 1.6 | E2E | `task-prompts.spec.ts` | save + run now with TestProvider |
| 1.7 | OpenAPI | generated schemas | `SavedTask*` schemas used, not manual interfaces |
| 1.8 | phpstan | full | entities, repos, tests |

### Sprint 2 — Graph + triggers

| ID | Layer | File (suggested) | Assert |
| -- | ----- | ---------------- | ------ |
| 2.1 | Unit | `SavedTaskGraphValidatorTest` | cycle, unknown capability, flag-gated nodes |
| 2.2 | Unit | `SavedTaskPlanFactoryTest` | JSON → TaskPlan; DagExecutor with mock runners |
| 2.3 | Unit | processor hook | graph present skips TaskPlanner; else plans |
| 2.4 | Unit | webhook ingress | bad secret 401; good secret creates run |
| 2.5 | Characterization | **new** cases | graph short-circuit; default fixtures unchanged |
| 2.6 | Feature | mail checkbox off | existing InboundEmailHandler tests still pass |
| 2.7 | Vitest | graph editor | connect two steps; cycle error |
| 2.8 | E2E | widget flow editor | **mandatory regression** |
| 2.9 | E2E | saved task graph | Run now compiles; no planner (assert via task cards / snapshot) |

### Sprint 3 — Scheduler

| ID | Layer | File (suggested) | Assert |
| -- | ----- | ---------------- | ------ |
| 3.1 | Unit | `ScheduleCalculatorTest` | Berlin DST fixtures; injected clock |
| 3.2 | Unit | claim | second UPDATE 0 rows |
| 3.2a | Unit | Redis lock | tick exits 0 with no work when `saved-tasks-tick` lock held (host cron + scheduler role coexistence) |
| 3.3 | Unit | failure | `next_run_at` advances; unique idempotency; 3× failure auto-pauses + notifies |
| 3.4 | Unit | `allow_unattended` | `email_me` + schedule rejected |
| 3.5 | Integration | tick | enqueues Messenger; handler uses TestProvider |
| 3.6 | Regression | container-runtime + platform crons | media reap still invoked; `cron-gmail.sh` byte-identical (C4, platform PR review) |
| 3.7 | Vitest | schedule form | tz + next run label |
| 3.8 | E2E | save schedule | no real wait; persist + pause |

### Sprint 4 — Webhook, plugins, n8n docs

| ID | Layer | File (suggested) | Assert |
| -- | ----- | ---------------- | ------ |
| 4.1 | Unit | `OutboundWebhookRunnerTest` | SSRF, HMAC, timeout, no secret in logs |
| 4.2 | Unit | SkillCatalog | capability absent from planner list |
| 4.3 | Unit | `PluginManifestTest` | `graphNodes` parse; fixture plugin in registry |
| 4.4 | Feature | mock HTTP client | signed POST body |
| 4.5 | MCP | tools/list | new tools only if flag on |
| 4.6 | Characterization | planner | **must not** emit `outbound_webhook` |
| 4.7 | Vitest | webhook node | secret write-only |
| 4.8 | Docs | `docs/N8N.md` | exists; no compose service named n8n in repo grep |

---

## 4. Frontend test notes (house rules)

- Pinia + i18n + `useMarkdown` setup as in existing config/chat tests.
- Stub heavy children (`stubs: { MessageText: { template: '...', props: [...] } }`).
- Unique `data-testid` on new controls (`btn-save-as-task`, `btn-run-saved-task`, `section-saved-task-graph`, …).
- Graph editor: do not require Playwright to drag SVG in the first E2E; prefer clicking “add step” buttons and asserting JSON, plus one visual E2E later if Maestro/Playwright drag is reliable.

---

## 5. Backend test notes

- DB: existing PHPUnit kernel tests / SQLite patterns. Portable types only on new entities (no `JSONB`; use `TEXT` + JSON encode, consistent with plugin_data).
- Messenger: assert message dispatched; run handler in unit test with fake runner.
- IMAP: never open a socket; reuse `EmailSearchRunner` fakes.
- Clock: `ClockInterface` / explicit `DateTimeImmutable $now` argument.

---

## 6. Documentation inventory

Ship docs **in the same PR as the behaviour**. English. No German in docs unless quoting UI that is translated elsewhere.

| Path | Sprint | Content |
| ---- | ------ | ------- |
| `_devextras/planning/saved-task-workflows/*` | 0 | This plan (already) |
| `docs/FEATURES.md` | 0–4 | Incremental, honest copy |
| `docs/CONFIGURATION.md` | 1, 3 | `SAVEDTASKS.*` flags |
| `docs/DEVELOPMENT.md` | 2, 3 | Pipeline + tick command |
| `docs/EMAIL.md` / smart email cron doc | 2, 3 | Precedence vs Saved Tasks |
| `docs/MULTITASK_DATA_NODES.md` | 2 | Authored graphs may place data nodes; still read-only |
| `docs/API` via OpenAPI | 1–4 | Controllers |
| `docs/N8N.md` | 4 | **New.** Interface, not embed |
| `README.md` | 4 | Distinguish n8n-as-MCP-peer vs outbound webhook |
| `docs/MIGRATIONS.md` | 1 | If new seeders/tables need a mention |
| Frontend i18n | every | four locales |
| Plugin repos (Synamail, Synasort, …) | later | `graphNodes` contract pointer — **do not** implement in those repos until Sprint 4 seam exists |

**Do not** document Office 365 as shipped.

Operator/platform (`synaplan-platform` `_devextras/SYSADMIN-help.md`): scheduler role must run `app:saved-tasks:tick`. That change lives in the **platform** repo; add a reminder in `docs/DEVELOPMENT.md` so it is not forgotten.

---

## 7. PR template (copy into each sprint PR)

```markdown
## Sprint
Saved Task Workflows — Sprint N

## Decision checklist
Master plan §0 rows still agreed: yes / deviations:

## Compatibility invariants touched (master plan §7.0)
C1 OIDC: … / C2 model change: … / C3 simple DAG: … / C4 platform crons: … / C5 API contracts: … / C6 widget+mobile: …
(state "not touched" or point to the green test)

## Test plan
- [ ] Compatibility regression suite (§3.0) green
- [ ] Unfiltered `make lint && make -C backend phpstan && make test`
- [ ] Frontend lint + `check:types` + `make -C frontend test`
- [ ] Characterization diff reviewed (or N/A)
- [ ] Widget E2E or justification
- [ ] i18n en/de/es/tr
- [ ] generate-schemas (or N/A)
- [ ] mobile-impact allow-list
- [ ] Docs table in sprint file updated

## Non-goals in this PR
(n8n embed, Graph calendar, mutating MCP, …)
```

---

## 8. Stop conditions

Stop and update the sprint file with `WIP: blocked because …` if:

- A Saved Task would need `doctrine:schema:update --force`.
- Widget persistence is the only way to store the graph.
- Planner prompt text is being parsed into boxes.
- n8n is added as a Compose service.
- Tests need the network.
- Two implementation attempts failed (AGENTS.md).
