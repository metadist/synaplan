---
name: Synaplan
description: AI-powered knowledge management system with RAG, chat widgets, and multi-channel integration
---

# Synaplan Development Guide

Full-stack AI knowledge management platform: RAG with MariaDB VECTOR + Qdrant, embeddable chat widgets, WhatsApp/Email integration, multiple AI providers (Ollama, OpenAI, Anthropic, Groq, Gemini).

**Stack:** PHP 8.3/Symfony 7, Vue 3/TypeScript/Vite, Docker Compose, frankenphp/caddy.

## Repository Architecture

| Repo | Purpose |
| ---- | ------- |
| `synaplan/` | Main app source code, local development environment (includes Qdrant) |
| `synaplan-base-php/` | Base Docker image (FrankenPHP + gRPC + whisper.cpp) |
| `synaplan-platform/` | Production deployment configs, pulls pre-built images |

**Local dev** (`synaplan/`): Builds from source, includes all dev tools (phpMyAdmin, MailHog, Vite dev server). Qdrant runs as a Docker service for AI memories and RAG vector search.

**Production** (`synaplan-platform/`): Uses `ghcr.io/metadist/synaplan:latest`, multi-server with external Galera cluster. See `_devextras/SYSADMIN-help.md`.

## Critical Rules

### Language

- **Code, comments, commit messages: ALWAYS English.** Never write German (or any other language) in code or comments.
- Chat responses: in the language the user chooses.

### Docker Environment

All backend/frontend tooling runs inside containers:

```bash
docker compose exec backend php bin/console cache:clear
docker compose exec -T frontend npm run check:types
```

Prefer the `make` targets (they wrap `docker compose exec` correctly).

### Git — allowed, but NEVER on main

- Git operations (branch, add, commit, push) are allowed.
- **NEVER** commit or push directly to `main`; never force-push `main` or `master`. All changes go through feature branches + PRs.
- Use [Conventional Commits](https://www.conventionalcommits.org/): `feat:`, `fix:`, `refactor:`, `docs:`, `chore:`, `test:` — e.g. `feat(frontend): add runtime config API support`.
- **NEVER** add attribution ("Generated with Claude Code", "Co-Authored-By: …", or similar).

### Merge Conflicts — NEVER Accept One Side Blindly

1. **Manually merge both sides** — understand what each adds/changes.
2. **NEVER** use `git checkout --ours` / `--theirs` for code files.
3. **Preserve ALL functionality** from both branches unless explicitly instructed.
4. **If unsure, ASK** — throwing away code is worse than asking.

### MANDATORY Pre-Commit Gate — Run Tests BEFORE Every Commit

This is the ENFORCED local mirror of the GitHub `CI` workflow — each step maps 1:1 to a CI job, and the `All Checks Passed` gate goes red if you skip one. If any step fails, fix it before committing. No exceptions.

| Local step | CI job it mirrors |
| ---------- | ----------------- |
| `make -C backend lint` | PHP Code Formatting |
| `make -C backend phpstan` | Backend (PHP/Symfony) — PHPStan stage |
| `make -C backend test` | Backend (PHP/Symfony) — PHPUnit stage |
| `make -C frontend lint` | Frontend (Vue/TypeScript) — lint |
| `docker compose exec -T frontend npm run check:types` | Frontend (Vue/TypeScript) — vue-tsc |
| `make -C frontend test` | Frontend (Vue/TypeScript) — Vitest |

**One-shot (this IS the gate — green here ⇒ green CI):**

```bash
make lint && make -C backend phpstan && make test && docker compose exec -T frontend npm run check:types
```

**Rules:**

- A green FILTERED run (`phpunit --filter ...`, `phpstan analyse <path>`, `vitest <file>`) is NOT the gate — always finish with the unfiltered `make` targets.
- `make -C backend phpstan` analyses `src/` **and** `tests/` — never scope it to a single path.
- If you only changed backend PHP, you may skip frontend checks (and vice versa).
- If you changed backend OpenAPI annotations: `make -C frontend generate-schemas`, then re-run `vue-tsc`.
- **NEVER** commit with failing tests.

### Common pre-commit traps

Real failure modes that have caused red CI more than once:

- **`--filter` ≠ `make test`.** Characterization, integration, and feature tests live OUTSIDE the namespace you usually filter on. Always re-run unfiltered before committing.
- **`phpstan analyse <path>` ≠ `make -C backend phpstan`.** CI analyses the WHOLE project including `tests/`. Example miss: a test closure typed `: ?string` that never returns null trips `Anonymous function never returns null` only in the full run.
- **Snapshot / characterization tests.** `backend/tests/Characterization/` locks the routing/classifier contract via JSON snapshots. Any change to `MessageClassifier`, `MessageSorter`, the fast-path heuristic, or the AI sorter response shape WILL drift them. Re-record and review every changed line — never silently re-record:

  ```bash
  docker compose exec -T -e UPDATE_ROUTING_SNAPSHOTS=1 backend ./vendor/bin/phpunit tests/Characterization/RoutingCharacterizationTest.php
  git diff backend/tests/Characterization/__snapshots__/
  ```

- **Frontend tests need Pinia + i18n + `useMarkdown` set up.** Stub heavy dependencies (`stubs: { MessageText: { template: '...', props: [...] } }`) instead of pulling the full app context into a unit test.
- **Docker-restart cache permissions.** After `docker compose down`, `backend/var/cache/test` can lose write perms and every kernel-booting test fails with `Unable to write in the "cache" directory`. Fix, then re-run:

  ```bash
  docker compose exec -T backend sh -c 'rm -rf var/cache/test && mkdir -p var/cache/test && chmod -R 777 var/cache/test'
  ```

- **Heuristic changes ≠ production effect.** If a config flag (e.g. `CLASSIFIER.FAST_PATH_ENABLED`) defaults a code path OFF, new logic there passes tests and is still a no-op in prod. Check the `BCONFIG` default and confirm the path is reachable before claiming a fix.

### Mobile App Compatibility

The private `synaplan-apps` repository consumes this public repository as a pinned submodule. Keep
mobile support a narrow, reviewable compatibility layer:

- Mark every shared hook with `MOBILE-APP SEAM`; keep the implementation in new, focused files
  whenever possible.
- Mobile behavior is **default-off** and web/self-host behavior must remain unchanged without a
  mobile client or explicit configuration. API and runtime-config changes are additive, optional,
  and have safe defaults.
- Classify every change before release:
  - **backend-only** — no bundled SPA/native-shell effect; deploy through the normal platform path.
  - **ota-candidate** — web-asset-only fix within already reviewed behavior; release only from
    `synaplan-apps` under its OTA policy.
  - **store-required** — native code, plugins, permissions, privacy declarations, IAP/entitlements,
    authentication transport, or material behavior changes.
- Treat these as mobile-risk paths: runtime config/OpenAPI, auth/OAuth and Bearer handling,
  SSE/WebSocket setup, subscription/IAP and entitlement logic, native guards/bootstrap,
  onboarding/routing, forced updates, and Capacitor-facing services.
- Run the complete backend/frontend gate for every affected area. For OpenAPI changes, regenerate
  schemas, run `vue-tsc`, and verify that the app build consumes the same generated contract.
- This public repository never publishes OTA bundles and must not contain private app-signing,
  store-account, OTA-hosting, endpoint, credential, or infrastructure details.

## Essential Commands

```bash
docker compose up -d / down            # Start/stop services
make lint && make -C backend phpstan && make test   # Quality gate
make build                              # Frontend app + widget
make help / make -C backend help / make -C frontend help

# Dev URLs
# Frontend: http://localhost:5173 — Backend: http://localhost:8000 — API docs: http://localhost:8000/api/doc
```

## Frontend Rules

### Type Safety & Validation (Zod)

- **ALWAYS use Zod schemas for API responses** — generated from the backend OpenAPI spec (`make -C frontend generate-schemas` → `frontend/src/generated/api-schemas.ts`).
- **NEVER write manual TypeScript interfaces for API responses** — they break silently when the API changes.

```typescript
import { GetRuntimeConfigResponseSchema } from '@/generated/api-schemas'
type RuntimeConfig = z.infer<typeof GetRuntimeConfigResponseSchema>

const config = await httpClient('/api/v1/config/runtime', {
  schema: GetRuntimeConfigResponseSchema
})
```

- Use existing API clients in `services/api/` (based on `httpClient`).

### Runtime Config

- **NO** `VITE_*` env vars for runtime config — we're open source, runtime location unknown at build time. Dev-only flags (e.g. `VITE_AUTO_LOGIN_DEV`) are OK.
- App: `useConfigStore().apiBaseUrl` (loaded from `/api/v1/config/runtime`). Widget: `detectApiUrl()` from `widget-utils.ts`.
- **NEVER** hardcode `http://localhost:8000`.

### Styling

- Use CSS variables and utility classes from `frontend/src/style.css` (`var(--bg-card)`, `var(--txt-primary)`, `surface-card`, `btn-primary`, …) — **never Tailwind colors directly**, never one-off custom CSS classes.
- Tailwind utilities for layout/spacing are fine; dark mode must work (tokens handle it).
- See `docs/FRONTEND_CONVENTIONS.md` for the token/utility reference.

### Vue 3 / Composition API

- **ALWAYS `<script setup lang="ts">`** — no Options API.
- Prefer `ref()` over `reactive()` (including in Pinia stores); `computed()` for derived state (never a method).
- Clean up listeners/timers in `onUnmounted()`.
- Unique `:key` in every `v-for`; **never** combine `v-if` and `v-for` on the same element (filter via `computed` instead).
- **NEVER mutate props** — emit events to the parent.
- Modern JS: top-level `await` (not `.then()`), `const`/`let`, arrow functions, no semicolons (ESLint enforces).
- Lazy load only heavy components (charts, editors, modals) via `defineAsyncComponent` / dynamic route imports — not small, frequently used ones.
- Pinia stores: setup style with `ref()` + `computed()`, actions as plain functions, return only the public API.

### Dialogs & Notifications

- **NEVER** use native `alert()` / `confirm()` / `prompt()`.
- Confirmation/input: `useDialog()` (`confirm({ title, message, danger: true })`, `prompt({...})`).
- Toasts: `useNotification()` (`success(t('...'))`, `error(t('...'))`).

### i18n

- All UI text through `vue-i18n` — never hardcode user-facing strings.
- **Always update ALL four locales**: `en.json`, `de.json`, `es.json`, `tr.json` (`frontend/src/i18n/`, registered as `supportedLanguages = ['de', 'en', 'es', 'tr']`). A missing key silently falls back to English.

### UI copy & wording (UX clarity)

User-facing text must be **clean, consistent, and crystal clear for a non-technical user**. Chaotic or contradictory wording is a bug.

- **Write for the average user, not the developer.** No implementation jargon ("interview", "prompt topic", "node") in primary copy.
- **ONE canonical term per concept**, applied in all four locales:
  - **chat widget** (short: **widget**) — the embeddable product. (de: *Chat-Widget*, es: *widget de chat*, tr: *sohbet widget'ı*)
  - **AI assistant** — the AI that answers inside a widget. (de: *KI-Assistent*, es: *asistente de IA*, tr: *AI asistanı*)
  - **AI Setup Assistant** — the guided chat that configures a widget. (de: *KI-Setup-Assistent*, es: *Asistente de Configuración IA*, tr: *AI Kurulum Asistanı*)
- **Copy must be CORRECT.** When renaming a tab/button/route, grep for every string referencing the old name (breadcrumbs, "the 'X' tab") and update them in the same change.

### Widget Development

- Entry: `frontend/src/widget.ts` → `vite.config.widget.ts` → `dist-widget/` (`make -C frontend build-widget`).
- Must work cross-origin (CORS-ready); API URL detected from script source at runtime via `detectApiUrl()`.

## Backend Rules

### API Development

- **ALWAYS write complete OpenAPI annotations** on all endpoints (`@OA\Response`, `@OA\Property`, required fields, examples) — the frontend Zod schemas are generated from them.
- After changing annotations: `make -C frontend generate-schemas`, then re-run `vue-tsc`.
- Test endpoints via Swagger UI at `http://localhost:8000/api/doc` (not curl/Postman). Before creating a new endpoint, check if a similar one exists.

### Code Style (PHP)

- PSR-12, strict types, type hints everywhere: `public function foo(string $bar): int`.
- `final` classes and `readonly` properties by default.
- Specific error messages with context — never `throw new \Exception('Error occurred')`.
- Named constants instead of magic numbers.
- No comments about deleted code — git history covers that.
- Modern Symfony/Doctrine:
  - DBAL: `bindValue()` first, then `executeQuery()` with no arguments (param-passing is removed in DBAL 4); `ArrayParameterType::INTEGER` for `IN (:ids)`.
  - Explicit request access: `$request->query->get()` / `$request->request->get()` / `$request->attributes->get()` — never ambiguous `$request->get()`.
  - Empty `eraseCredentials()` gets `#[\Deprecated]` (Symfony 7.3+).

### Database Usage

- **NEVER hardcode AI model names** — query via `ModelRepository`; users configure models in the UI.
- All queries in Repositories — no DQL/EntityManager work in controllers.

### Database Schema & Seed Data

Three separated areas — see `docs/MIGRATIONS.md`:

| What | Where | Runs in |
| ---- | ----- | ------- |
| **Schema** (CREATE/ALTER/DROP) | Doctrine Migrations in `backend/migrations/` (`make -C backend migrate-diff` → review → `migrate`) | dev + prod |
| **Catalog data** (models, prompts, default config, rate limits) | Idempotent seeders in `backend/src/Seed/` + `app:seed` (`make -C backend seed`) | dev + prod |
| **Demo data** (users, example widget) | `backend/src/DataFixtures/` + `App\Seed\DemoWidgetConfigSeeder` | dev/test only |

**CRITICAL:**

- ❌ **NEVER** `doctrine:schema:update --force` against prod or any shared DB.
- ❌ **NEVER** `doctrine:fixtures:load` in prod (purges all entity tables); never put production data in `DataFixtures/`.
- ✅ Seeders must be INSERT-IF-NOT-EXISTS (`BConfigSeeder::insertIfMissing`) or `INSERT … ON DUPLICATE KEY UPDATE`. For `BMODELS`, operator-owned toggles (`BSELECTABLE`, `BACTIVE`, `BISDEFAULT`) are seeded once and never overwritten.
- ⚠️ **`BCONFIG` defaults are bootstrap-only** — changing a seeder value does NOT propagate to existing installs. To roll out a new default, ship a migration that explicitly UPDATEs the affected rows.
- Model bindings reference catalog entries by `service:providerId:tag` keys (via `ModelCatalog::findBidByKey`), never raw BIDs.

### Production Platform Specifics (Galera)

Production is `synaplan-platform/` + a **MariaDB Galera cluster outside Docker** (one shared schema across `web1`/`web2`/`web3`; migrations run on backend container start). These cause prod-only failures that never reproduce locally:

1. **`DATABASE_*_URL` MUST use `serverVersion=mariadb-<x.y.z>`** — a bare version number selects the MySQL platform and breaks introspection (phantom diffs, `TableDoesNotExist`).
2. **Prod-reachable migrations MUST NOT touch `Schema $schema`** (`hasTable()`, `getTable()`, …) — the DBAL comparator throws `There is no table with name "<x>"` on this cluster, with a varying table name per run. Use raw, idempotent `addSql()` (`CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`); for conditional DML, query `information_schema` via `$this->connection`, not the Schema API.
3. **Several FKs have no `ON DELETE CASCADE`** (e.g. `BPROMPTMETA.BPROMPTID`) — delete child rows before parent rows in migrations.
4. Manual cluster heal scripts live in `synaplan-platform/` (private repo — never commit node IPs here; this repo is public).

### Project-Specific Patterns

- **Internal prompts** (not selectable by AI classification) MUST use the `tools:` prefix in `topic` (e.g. `tools:memory_extraction`). `MessageSorter` excludes them via `excludeTools: true`. A user-facing prompt without the prefix WILL be selected by the AI.
- **Memory badges**: AI responses reference memories as `[Memory:ID]`. Only use IDs from the current memory list in the system prompt — never copy from earlier chat messages, never invent IDs. `MessageText.vue` renders the badges.
- **Feedback categories** `feedback_negative` / `feedback_positive` / `feedback_false_positive` are hidden from the user memory list, used internally.
- **Qdrant**: PHP talks directly to Qdrant's REST API via `QdrantClientDirect` (`QDRANT_URL=http://qdrant:6333`); `QdrantClientMock` for dev/test. Collections (`user_memories`, `user_documents`) are auto-created. Memory point IDs are deterministic UUIDv5 from `mem_{userId}_{memoryId}`.
- **SSE streaming**: backend sends `token`, `memories_loaded`, `feedback_loaded`, `complete`, `error` events via `sendSseEvent()`; frontend handles them in `ChatView.vue`.

## Architecture Patterns

- **Controllers**: HTTP handling only, thin, under 50 lines per method — validation + delegate to a Service.
- **Services**: All business logic, `final readonly`, constructor DI.
- **Repositories**: All database queries.
- **Components**: Modular, under 300 lines — split larger ones.
- Extract when: controller method > 50 lines → Service; component > 300 lines → split; code repeated 3+ times → composable/service. Don't extract 1-2 trivial methods used once.

## Red Flags — STOP and Rethink

- Native `alert()` / `confirm()` / `prompt()` instead of `useDialog()` / `useNotification()`
- Manual TS interface for an API response (use generated Zod schemas)
- Business logic or EntityManager work in a controller
- Hardcoded user-facing strings (use `$t()`) or hardcoded AI model names (use `ModelRepository`)
- Tailwind colors / custom CSS instead of `style.css` tokens
- `setTimeout()` to "fix" race conditions
- German (or non-English) code comments
- Committing to `main`, or committing with AI attribution
- `doctrine:schema:update --force` on a shared DB (generate a migration instead)
- Internal prompt without `tools:` prefix; invented Memory IDs
- Hardcoded API URL in the widget (use `detectApiUrl()`)
- `console.log` debugging left in; `any` types

## Boundaries

### Ask First Before

- Changing database schema (always via Doctrine Migrations — see `docs/MIGRATIONS.md`)
- Adding dependencies (npm/composer)
- Modifying Docker/CI/build configs
- Adding new AI provider integrations

### Never Do

- Commit secrets or `.env` files with credentials
- Edit `vendor/` or `node_modules/`
- Commit `dist/` directories
- Commit or push directly to `main`; force-push `main`/`master`
- Skip the pre-commit gate

## Detailed Documentation

- `docs/PHP_CONVENTIONS.md` — PHP code style, examples, patterns
- `docs/FRONTEND_CONVENTIONS.md` — TypeScript, Vue, styling tokens, i18n
- `docs/API_PATTERNS.md` — Zod schemas, OpenAPI, httpClient usage
- `docs/MIGRATIONS.md` — Doctrine migrations + idempotent seed workflow
- `docs/E2E_TESTING.md` — Playwright E2E test guidelines
- `docs/DEVELOPMENT.md` — Development setup
- `backend/.env.example` — Environment variables
