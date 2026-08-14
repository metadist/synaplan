# Sprint 1 — Saved Task model on top of Task Prompts

**Goal:** Persist a **Saved Task** that references a Task Prompt, with **Run now**, run history, and a feature flag. No authored multi-node graph yet (implicit single `chat` node using that prompt). No user cron yet.

**Depends on:** Sprint 0 (IA + executed-plan shape). **Unlocks:** Sprint 2 (graph JSON) and Sprint 3 (scheduler claims the same rows).

**Flag:** `SAVEDTASKS.ENABLED` (`BCONFIG` group `SAVEDTASKS`, key `ENABLED`).

---

## 0. Scope in one sentence

A user can save “run **this** Task Prompt as a task”, click **Run now**, and get a normal chat turn that is classified/forced onto that topic — recorded as a `saved_task_run`.

The acceptance story *“look into my mail and create calendar entries”* is **not** fully automated here. After this sprint the user can **Run now** in chat with that Task Prompt (and, if the planner + `email_search` + `calendar_event` work, the runtime DAG may already do it). Standing mail pickup and schedule wait for Sprints 2–3.

---

## 1. Prerequisite bugfix (same PR or a stacked PR first)

**`ChatRunner` must honour `params.topic_id`.** Intermediate multitask `chat` nodes currently drop the custom Task Prompt. Saved Tasks will be a lie until this is fixed.

1. Read `backend/src/Service/Multitask/Execution/Runner/ChatRunner.php` and the test that documents the gap.
2. Bind the topic the same way `ChatHandler` does (prompt body + `PromptMeta` tools/model).
3. Unit tests: custom topic system prompt appears in the model request; missing/unknown topic_id falls back safely.
4. Re-run **unfiltered** backend tests. If planner/sorter output changes, characterization snapshots — **review the diff, do not silently re-record**.

Do not ship Saved Tasks without this fix.

---

## 2. Schema (ask before landing — AGENTS.md)

Additive Doctrine migration, Galera-safe:

- `CREATE TABLE IF NOT EXISTS` for `saved_tasks` and `saved_task_runs` (column list: [master plan §3.3](./00_master_plan.md#33-proposed-data-model-sprint-1--review-with-schema-ask)).
- **Include the Sprint 3 columns now, nullable and unused:** `next_run_at`, `last_run_at`, `consecutive_failures`, `chat_id`. One reviewed migration instead of two; the scheduler sprint then touches no schema.
- Indexes: `(owner_id, enabled)`, `(next_run_at)`, `(saved_task_id, created_at)` on runs.
- **No** Schema API (`hasTable` / `getTable`) in the migration class.
- Delete runs before tasks in any later down migration; do not assume `ON DELETE CASCADE`.
- Seed `SAVEDTASKS.ENABLED`: insert-if-missing. **Recommendation:** global default `0` until Sprint 2; code default `false`. Document the choice in the migration comment.

Entities: `final` classes, `readonly` where possible, types everywhere. Repositories own all queries.

---

## 3. Backend steps

### 3.1 Config

Mirror `MultitaskRoutingConfig`:

- `SavedTaskConfig::isEnabled(int $userId): bool` — per-user row → global row → false.
- Admin description via `SystemConfigService` (English description string).
- Seeder `SavedTaskConfigSeeder` insert-if-missing — do **not** overwrite operator `0`.

### 3.2 Domain services

| Class | Responsibility |
| ----- | -------------- |
| `SavedTaskService` | CRUD, ownership checks, enable/disable |
| `SavedTaskRunner` | Run now: build a synthetic inbound message **or** call existing stream/send path with `promptTopic` / `promptId` forced |
| `SavedTaskRunStore` | Insert/update run rows; attach `message_id` + `plan_snapshot` when the turn finishes |

**Execution identity (master plan §3.4 — enforce here):** the runner receives an **owner id**, never a security token. Resolve the user the way the email/WhatsApp channels do; nothing in the run path touches the session or OIDC stack. This is what makes Sprint 3's cron-driven runs possible without auth changes — and it is why OIDC login stays untouched by this epic (compatibility invariant C1).

**Run placement (checklist row 12):** on first run, create a dedicated conversation for the task (name = task name), store its id in `saved_tasks.chat_id`. Every run appends its turn there. The Runs list links to it. Do not scatter runs across new anonymous chats — the user must have **one** place to look.

**Run now algorithm (keep it boring):**

1. Flag off → 403/404 as appropriate (do not leak existence to other users).
2. Load task, verify `owner_id`.
3. Load Task Prompt; if missing/disabled → fail the run with a specific error.
4. Rate limit: account the run against the owner via `RateLimitService`; over budget → `failed` run with a user-readable reason (no partial execution).
5. Create `saved_task_runs` row `queued` → `running`.
6. Enqueue or synchronously process **one** user message **in the task's dedicated conversation** (create it on first run, store `chat_id`). Preferred: reuse `MessageProcessor` with a system-authored IN message whose classification is **forced** to the prompt topic (same mechanism as stream API `promptTopic` / widget `taskPromptTopic`). Do **not** invent a second pipeline.
7. Message text for manual run: use a stored `run_prompt` on the task (optional, default a short i18n template like “Run saved task: {name}”) **or** require the user to type the instruction in a dialog. **Lock in implementation:** dialog with required user text is clearer and avoids empty-message planner nonsense.
8. On complete/fail: update run; copy plan snapshot from `BMESSAGE_TASKS` if present. Failure sets a user-readable `error` on the run (shown in the Runs list — never a bare exception class) and increments `consecutive_failures`; success resets it to 0. Auto-pause at 3 activates in Sprint 3 with schedules, but the counter is maintained from day one.

**Do not** call IMAP or generate `.ics` from this service directly. If the user’s text is “look into my mail and create calendar entries”, the **existing planner** may emit `email_search` + `calendar_event`. That is enough for Sprint 1.

### 3.3 HTTP API

New controller, thin (<50 lines per method), OpenAPI complete:

| Method | Path | Notes |
| ------ | ---- | ----- |
| `GET` | `/api/v1/saved-tasks` | List for current user |
| `POST` | `/api/v1/saved-tasks` | `{ promptId, name }` |
| `PATCH` | `/api/v1/saved-tasks/{id}` | name, enabled |
| `DELETE` | `/api/v1/saved-tasks/{id}` | delete runs first |
| `POST` | `/api/v1/saved-tasks/{id}/run` | `{ message: string }` |
| `GET` | `/api/v1/saved-tasks/{id}/runs` | newest first, paginate |

Auth: same session / API key as prompts. Ownership on every call.

Frontend: `make -C frontend generate-schemas` — **no** hand-written TS interfaces for these responses.

### 3.4 MCP (optional in this sprint, required by Sprint 4)

If cheap: `list_saved_tasks` (read) behind the same flag. `run_saved_task` can wait until Run now is stable in HTTP.

---

## 4. Frontend steps

1. API client in `frontend/src/services/api/` using generated Zod schemas.
2. AI Instructions: persist “Save as task” → `POST /saved-tasks`. Button becomes **Run now** + enable toggle when a Saved Task exists for this prompt.
3. Run now: `useDialog()` prompt for the instruction text (not `window.prompt`). Toast success/error via `useNotification()`.
4. Runs list: status, time, link to the chat message if `message_id` present. Reuse `ExecutedPlanGraph` with `plan_snapshot`.
5. Flag off: hide all Saved Task chrome; Task Prompt CRUD unchanged.
6. i18n all four locales. Canonical: **Saved Task** / **Run now** / **Runs**. No “workflow”, “DAG”, “cron” in this sprint’s copy.

Pinia: setup store only if state is shared with chat; otherwise keep it in the config component + a small composable `useSavedTasks.ts`.

---

## 5. Testing

| Layer | What |
| ----- | ---- |
| Unit | `SavedTaskConfig` resolution order; disabled flag short-circuits runner |
| Unit | `SavedTaskService` ownership (user A cannot run user B’s task) |
| Unit | Runner resolves the user by owner id with **no** security-token/session dependency (construct it with no `Security` service at all — compile-time guarantee) |
| Unit | Runs append to the same `chat_id`; first run creates the conversation |
| Unit | Over-budget run → `failed` with readable reason; `consecutive_failures` increments and resets on success |
| Unit | `ChatRunner` topic_id binding (prerequisite) |
| Integration / feature | POST create → POST run → GET runs; 403 when flag off; 404 other user |
| PHPUnit | MessageProcessor (or facade) invoked with forced topic — mock AI |
| Characterization | Only if classifier/planner touched |
| Vitest | Save / Run now / flag-off hides UI; dialog required message |
| E2E | `task-prompts.spec.ts`: create custom prompt → save as task → run now (TestProvider) → run appears |
| Gate | Unfiltered `make lint`, `phpstan` (whole `src/` + `tests/`), `make test`, frontend lint/types/test |

Offline: no live IMAP, no live LLM. Use TestProvider / existing message fakes.

---

## 6. Documentation

| Doc | Change |
| --- | ------ |
| `docs/FEATURES.md` | Saved Tasks: pin a Task Prompt and run it on demand. Flag. |
| `docs/CONFIGURATION.md` | `SAVEDTASKS / ENABLED` table row (mirror MULTITASK section). |
| `docs/API_PATTERNS.md` | only if the client pattern needs a note — prefer OpenAPI as source of truth |
| `docs/MIGRATIONS.md` | mention new tables in the “what goes where” list if you add a catalog/seed |
| `backend/.env.example` | only if a new env var is introduced (prefer BCONFIG; avoid env) |
| OpenAPI | complete annotations; regenerate Zod |
| i18n | four locales |

---

## 7. Release gate

- [ ] Schema ask recorded (PR description) and Galera-safe migration reviewed.
- [ ] `ChatRunner` topic_id tests green; custom Task Prompt actually used on Run now.
- [ ] Flag off = product identical to pre-sprint (E2E / smoke).
- [ ] User A cannot list/run/delete user B’s tasks.
- [ ] Run now creates a chat turn + run row; executed graph shows when the planner produced a plan.
- [ ] Widget invariant holds (no Saved Task UI in widget; no scheduler).
- [ ] Unfiltered gate green; schemas generated; four locales.
- [ ] Mobile-impact allow-list updated (`backend-only` for PHP, `ota-candidate` for AI Instructions UI).

---

## 8. Handoff to Sprint 2

- `saved_tasks.graph` column exists (nullable JSON) even if unused — **or** add it in Sprint 2’s migration. Prefer creating the column **now** as `NULL` so Sprint 2 is UI-only on that field.
- `trigger_type` default `manual` (Run now) and `chat` documented for “this prompt still participates in sorter routing”.
- Run store can attach `plan_snapshot` — Sprint 2 will compile authored graphs into the same snapshot shape.
