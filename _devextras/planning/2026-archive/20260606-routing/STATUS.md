# Multi-Task Routing — Status & Handoff (updated 2026-06-08)

Branch: **`feat/mult-routing-update`** (all work committed, tree clean, nothing pushed).

## 2026-06-08 update
- **Sprint 4 (hybrid parallel execution) DONE & committed.** Media nodes
  (image/video/audio) offloaded to concurrent subprocesses; text stays inline +
  streaming. Gated by `MULTITASK_PARALLEL_ENABLED` (default OFF). New BCONFIG keys
  `MULTITASK.MAX_PARALLEL` (3) and `MULTITASK.NODE_TIMEOUT` (120). Live: dog+mp3
  **19s parallel vs 34s sequential**.
- **Dev DB image/audio un-stubbed**: `DEFAULTMODEL.TEXT2PIC=190` (Gemini Nano
  Banana) and `TEXT2SOUND=41` (OpenAI tts-1) — both verified producing real files.
  (Other caps — CHAT/SORT/ANALYZE/etc. — are still test-provider in dev.)
- Bug fixed: media synthetic message now carries a non-null id (was breaking
  image gen in the in-process runner too).
- Next: **Sprint 5 (cross-channel)** — WhatsApp/email/API multi-file delivery.

## Sprint 5 — READY TO IMPLEMENT (mapped, not started)

Goal: deliver ALL `metadata['files']` (text + N files from a multi-node plan) on
WhatsApp, email, and the generic API. Today only web SSE (Sprint 3b) consumes
`metadata['files']`; other channels read the singular `metadata['file']` (=files[0]).
`TaskPlanExecutor` already sets `metadata['file']=files[0]` AND `metadata['files']`,
so single-file behaviour stays unchanged everywhere.

Exact edit points (verified via recon):

1. **WhatsApp** — `backend/src/Service/WhatsAppService.php`, after the PRIORITY-1
   single-file send block (around line ~957, inside `if ($fileData)`): loop
   `metadata['files']`, skip index 0, build `$url = rtrim($appUrl,'/').'/'.ltrim($path,'/')`,
   `sendMedia($dto->from, $type, $url, $dto->phoneNumberId, null)` for each extra
   (type ∈ image/video/audio). (Edit was designed but blocked by a tooling outage.)

2. **Email** — `backend/src/Controller/WebhookController.php::email` (~line 377/498)
   + `backend/src/Service/InternalEmailService.php::sendAiResponseEmail` (~line 144).
   Add a multi-path resolver (reuse `resolveAttachmentPathFromAiMetadata` logic at
   ~854–897: `local_path` or strip `/api/v1/files/uploads/` → `var/uploads/<rel>`)
   that maps `metadata['files']`; extend `sendAiResponseEmail` to accept N
   attachments (loop `attachFromPath`; images can still inline-embed the first).
   Keep the single `$attachmentPath` from `metadata['file']` working.

3. **Generic API** — `backend/src/Controller/WebhookController.php::generic` (~834):
   add explicit `'files' => $meta['files'] ?? (isset($meta['file']) ? [$meta['file']] : [])`
   to the JSON. (metadata already passes through, this is the clean contract.)

4. **Async/enqueue** path is poll-only (no outbound) → out of scope.

Tests: per-channel multi-file assembly (mock transports), widget invariant intact.
Then live smoke + commit + show interface changes (WhatsApp media msgs, email
attachments, API `files[]`).

Resume command after reboot: re-probe shell, then implement 1→3, gate, commit.

## TL;DR (Sprints 0–4)

Sprints 0–4 + the multitask chat UX are **done and committed**. Everything is
behind feature flags. Existing users are grandfathered OFF; new installs/dev/new
signups default ON. Backend suite **1893 green**, phpstan + lint clean, frontend
**560 vitest green** + vue-tsc clean. The only thing not verified live is the new
E2E (needs the properly-configured test stack / CI — local test-stack login 400s,
a pre-existing env issue unrelated to our code).

## Commits (oldest → newest)

| Commit | What |
|---|---|
| `59aedd5b7` | Feature flags (`MULTITASK_ROUTING_ENABLED`/`SHADOW_MODE`/`PARALLEL_ENABLED`) + grandfather migration |
| `f13c1f483` | Golden-corpus characterization harness (routing contract baseline) |
| `92d54c374` | TaskPlan schema + validator, `BMESSAGE_TASKS` table, `DEFAULTMODEL.PLAN`, `tools:plan` prompt |
| `16c87a0f7` | `TaskPlanner` + shadow-mode wiring (plans generated, not executed) |
| `82e2cbdfb` | Single-task executor seam (Sprint 2) |
| `9aa21340c` | DAG execution core (Sprint 3a) |
| `ae768353c` | Multi-task DAG wired + runners + StreamController multi-file (Sprint 3b) |
| `3c8e25927` | Multitask streaming SSE protocol (plan/task_update/task_chunk/task_file) |
| `71c5c0d64` | Frontend task-plan cards with live per-task streaming |
| `e04beafb0` | E2E spec + deterministic TestProvider planner |

## How it works (mental model)

1. Message arrives → preprocess → classify (unchanged).
2. If `MULTITASK_ROUTING_ENABLED` for the (effective) user AND classification
   `source === 'ai_sorting'` → run `TaskPlanner` (model `DEFAULTMODEL.PLAN` =
   gpt-oss-120b/Groq, falls back to `SORT`). Otherwise → legacy single-node path.
3. Planner returns single-node/fallback → behaves exactly like legacy (router).
   Multi-node → `DagExecutor` runs nodes in topological order via per-capability
   **runners** (thin adapters over existing handlers/services — no new gen code):
   extract_text, chat/summarize/translate/rag (ChatRunner, streams tokens),
   text2sound (AiFacade::synthesize), image/video (MediaGenerationHandler),
   compose_reply (assembler). Failure isolation: a failed node skips dependents;
   whole-plan failure → legacy router fallback.
4. SSE: emits `plan` (cards), `task_update` (per-node state), `task_chunk`
   (streamed text per card), `task_file` (media per card) — all inside event
   `metadata`. Frontend `TaskPlanBubble`/`TaskCard` render the cards; in multitask
   mode the normal single-bubble events are suppressed; history flattens on reload.
5. Single-node turns NEVER emit `plan` → normal chat is byte-identical.

## Key files

- Backend: `backend/src/Service/Multitask/**` (Plan/, Execution/, Execution/Runner/,
  TaskPlanner, TaskPlanExecutor, TaskPlanStore, ClassificationPlanMapper,
  MultitaskRoutingConfig), `MessageProcessor.php` (maybeShadowPlan +
  isMultitaskRoutingEnabled gates), `StreamController.php` (additive
  `metadata['files']` branch + `registerExistingGeneratedFile`),
  `Prompt/PromptCatalog.php` (`tools:plan`), `Seed/*` + migrations
  `Version20260607000000` (grandfather), `Version20260607010000` (BMESSAGE_TASKS),
  `AI/Provider/TestProvider.php` (mockTaskPlan for E2E determinism).
- Frontend: `components/multitask/TaskPlanBubble.vue` + `TaskCard.vue`,
  `views/ChatView.vue` (onUpdate plan/task_* handlers), `stores/history.ts`
  (`TaskPlanState`/`TaskCard`), `types/chatStream.ts`, `i18n/en.json`+`de.json`
  (`taskPlan.*`).
- Planning/specs: `_devextras/planning/20260606-routing/` (master plan, sprint
  docs, `09_multitask_ux_spec.md`, this STATUS).

## Flags (BCONFIG group `MULTITASK`, ownerId 0 = global, >0 = per-user)

- `ROUTING_ENABLED` — global default `1` (ON). Existing users grandfathered to `0`.
- `SHADOW_MODE` — default `0`. When `1`: plan generated + persisted, legacy answers.
- `PARALLEL_ENABLED` — default `0` (Sprint 4, not built).

Toggle for local testing (dev stack, admin = user 1):
```bash
docker compose exec backend php bin/console dbal:run-sql \
  "UPDATE BCONFIG SET BVALUE='1' WHERE BOWNERID=1 AND BGROUP='MULTITASK' AND BSETTING='ROUTING_ENABLED'"
# inspect executed/shadow plans:
docker compose exec backend php bin/console dbal:run-sql \
  "SELECT BMESSAGEID,BNODEID,BCAPABILITY,BSTATUS FROM BMESSAGE_TASKS ORDER BY BID DESC LIMIT 20"
# revert:
docker compose exec backend php bin/console dbal:run-sql \
  "UPDATE BCONFIG SET BVALUE='0' WHERE BOWNERID=1 AND BGROUP='MULTITASK' AND BSETTING='ROUTING_ENABLED'"
```
Current dev DB state: global ON, users 1/2/3/9 grandfathered OFF, `BMESSAGE_TASKS` empty.

## Gate commands

```bash
# backend
docker compose exec -T backend composer lint
docker compose exec -T backend composer phpstan
docker compose exec -T -e APP_ENV=test backend php bin/console cache:pool:clear --all   # avoids rate-limit flake
docker compose exec -T backend php bin/phpunit
# frontend
docker compose exec -T frontend npm run check:types
docker compose exec -T frontend npm run lint
docker compose exec -T frontend npm run test
```

## Known issues / decisions to revisit tomorrow

1. **Local test-stack E2E login 400** — pre-existing env issue (missing test-stack
   secret/recaptcha config locally; CI is fine). New `multitask.spec.ts` runs in CI;
   couldn't validate locally. Optional: debug local test-stack auth.
2. **Image generation stubbed in dev** — dev DB `DEFAULTMODEL.TEXT2PIC = -4` (test
   provider), so image nodes fail locally. Point it at a real model (e.g. BID 190
   Gemini) per-user to see the full dog+mp3. Also: image gen is slow/synchronous and
   can hang a sequential DAG — consider a **per-node timeout** (Sprint 4-ish).
3. **Fast-path bypasses the planner** — short multi-task prompts (e.g. "summarize
   this and read aloud") get fast-pathed → no planner → no cards. Decide whether the
   fast-path should defer to the planner on multitask cues. (E2E uses a >280-char
   prompt to force AI sorting.)
4. **Intermediate `chat` nodes use a generic prompt** — custom-topic (params.topic_id
   → PromptMeta.aiModel) binding for INTERMEDIATE nodes is deferred; single-node
   custom topics already work via the Sprint-2 path.
5. **TTS in test** — `TestProvider::synthesize` returns `/tmp/test_audio.mp3` (no real
   file); E2E avoids TTS by using a 2-text plan.

## What's NOT built yet (next sprints)

- **Sprint 4 — Parallel execution**: run independent nodes concurrently
  (`MULTITASK_PARALLEL_ENABLED`), concurrency cap for provider rate limits,
  deterministic assembly. Makes the cards advance concurrently (image ∥ summary).
- **Sprint 5 — Cross-channel**: WhatsApp (`sendMedia` per file) + email (N
  attachments) + API multi-file. Confirm async/enqueue path.
- **Sprint 6 — UX/observability/admin**: admin task-plan view (read BMESSAGE_TASKS),
  models-config copy clarifying capability == task, metrics.
- **Sprint 7 — Rollout/GA**: per-user → canary → global; rollback verification.

## Suggested first move tomorrow

Either: (a) wire a real `TEXT2PIC` model for user 1 and watch the full dog+mp3
multitask turn in the browser; or (b) start Sprint 4 (parallel execution) — the
`DagExecutor` interface already supports swapping in a parallel scheduler.
