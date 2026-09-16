# Conversation Continuity & Deep Memory — Master Plan

Status: planned (see `STATUS.md`)
Date: 2026-08-27

## Goal

Streamline the core communication UX so an identified user gets the **same natural,
context-aware conversation** on every channel — WhatsApp, Email, MCP, API, web chat —
and make the assistant able to **find and pull old messages** (months back), not just
recent turns and extracted memories.

Two workstreams:

1. **Rolling summary**: make it durable, channel-equal, and measurably good across
   our main chat model providers (Anthropic, OpenAI, Google, HuggingFace, optionally
   big Ollama models). Size stays a hard constraint (10–15k char total window).
2. **Message digest**: an out-of-band, daily-updated "digest of key messages" in
   Qdrant — structured one-liners with message IDs (e.g. *"office rent letter to
   realtor about the increase of payments"* → `messageId: 1234`) — searched via
   vector similarity at chat time so the referenced original message can be pulled
   into context.

## Acceptance use case (must pass at the end)

> The user created a document about the office rent 3 months ago and has written
> hundreds of messages since. A new prompt mentioning the office rent MUST surface
> the old message, and the assistant MUST be able to quote/pull its content.

## Current state (investigated 2026-08-27)

### Rolling summary — implemented, but effectively web-only and volatile

Core: `backend/src/Service/Message/ConversationSummaryService.php`

- Read/write split is good: hot path (`buildRollingContext()`) never calls AI; a
  Messenger worker (`RefreshConversationSummaryCommand` → `async_ai_high`) folds
  newly aged-out turns incrementally into the stored summary.
- Model resolution: `ModelConfigService::getSummaryModelConfig()` —
  `DEFAULTMODEL.SUMMARIZE → SORT → CHAT`; seeded default `groq:openai/gpt-oss-120b`.
- Size discipline exists: `SUMMARY_MAX_CHARS = 4000`, `RECENT_VERBATIM_CHARS = 8000`,
  target window 12000 (clamped 10–15k), configurable via BCONFIG group
  `CONVERSATION_SUMMARY` (admin UI wired).

**Gap 1 — volatile store.** The summary lives only in Redis (`cache.app`) with a
**3600 s TTL** (`ConversationSummaryConstants::CACHE_TTL`). Email/WhatsApp threads
that pause for hours always come back to a cold store; the first turn after expiry
runs without a summary. There is no DB table for it.

**Gap 2 — channel asymmetry.**

| Channel | Reads summary | Refreshes summary |
| ------- | ------------- | ----------------- |
| Web/guest SSE (`StreamController`) | yes (`processStream` step 2.9) | yes (dispatch after OUT flush) |
| Enqueue API (`ProcessMessageCommandHandler`) | no (`process()` has no step 2.9) | yes |
| WhatsApp (`WhatsAppService` → `processStream`) | yes | **no** — never dispatches |
| Widget public (`WidgetPublicController` → `processStream`) | yes | **no** |
| Email webhook (`WebhookController::email()` → `process()`) | **no** | **no** |
| MCP `synaplan_chat` (`McpServerFactory` → `process()`) | **no** | **no** |
| Generic webhook (`process()`) | **no** | **no** |

Root causes: `MessageProcessor::process()` lacks step 2.9, and
`ChatHandler::handle()` (non-streaming) never reads `options['conversation_summary']`
(only `handleStream()` does, `ChatHandler.php` ~932–944). Refresh dispatch only
exists in `StreamController` (~1892, ~2713) and `ProcessMessageCommandHandler` (~86).

**Gap 3 — no quality instrument.** Prompts are hardcoded heredocs in the service;
nobody can tell whether the summary quality/size holds across providers. A proven
pattern for live-model evaluation already exists:
`backend/src/Command/PlanEvalCommand.php` + `tests/Eval/plan_eval_corpus.json`
(golden corpus, outside the CI gate, `make -C backend plan-eval`).

### Memories — solid pipeline, but blind to old messages

- Extraction: async via `ExtractMemoriesCommand` (worker), prompt
  `tools:memory_extraction`, model `DEFAULTMODEL.MEM → CHAT`
  (`MemoryExtractionService`).
- Storage: MariaDB `BUSERMEMORIES` (authoritative) + Qdrant `user_memories`
  (point = UUIDv5 of `mem_{userId}_{memoryId}`, `QdrantPointId`). Embeddings via the
  pinned/VECTORIZE model (seeded `ollama:bge-m3`, 1024-dim).
- Retrieval at chat time: top-k 5, min score 0.45 (`FeedbackConstants`), injected as
  `## User Memories` with `[Memory:ID]` badges (`KnowledgeContextFormatter`,
  `MessageText.vue`). Hard cap 500 active memories/user.
- **There is NO search of any kind over `BMESSAGES`** — no vector index, no
  full-text. Chat context is only: last 15 messages / 15k chars
  (`MessageProcessor::HISTORY_MAX_*`) + rolling summary + memories + document RAG
  (`user_documents`, `doc_{userId}_{fileId}_{chunk}`). A message from 3 months ago
  is unreachable unless it happened to become a memory.

### Existing infrastructure we build on

- Worker container consumes `async_ai_high`, `async_extract`, `async_index`
  (Redis Streams, `messenger.yaml`).
- Scheduler role (`_docker/backend/lib/container-runtime.sh`) already runs daily
  commands (`app:updates:check`, `app:models:check-availability`) — the natural home
  for a daily digest job.
- `QdrantClientDirect` / `QdrantClientMock`, `VectorStorageFacade`,
  `MemoryEmbeddingModelResolver` — reusable as-is.
- Live-eval pattern: `PlanEvalCommand`.

## Target architecture

```
WhatsApp / Email / MCP / API / Web+Widget
        │
        ▼
MessageProcessor (process + processStream, SAME steps)
        │
        ▼
ChatHandler (handle + handleStream, SAME context assembly)
        ├─ reads   BCHATSUMMARIES (DB, Redis read-through)   ← rolling summary
        ├─ searches Qdrant user_memories                      ← memories
        ├─ searches Qdrant user_message_digests   [NEW]       ← old-message digest
        │        └─ high-score hits → pull BMESSAGES row (clipped) into context
        └─ searches Qdrant user_documents                     ← document RAG

off the hot path:
  Messenger worker  → ConversationSummaryService.refresh() → BCHATSUMMARIES
  Daily scheduler   → app:digest:run → MessageDigestService (MEM model,
                      tools:message_digest) → BMESSAGEDIGESTS + Qdrant upsert
```

## Sprints

Each sprint is a separate feature branch + PR and ends with the full unfiltered
pre-commit gate (`make lint && make -C backend phpstan && make test`, plus frontend
checks when frontend files change). No sprint is "done" without its tests.

| # | Sprint | Doc |
| - | ------ | --- |
| 1 | Summary quality eval harness (multi-provider) | `01_sprint_1_summary_eval.md` |
| 2 | Durable summary + channel parity | `02_sprint_2_durable_summary_channel_parity.md` |
| 3 | Message digest foundation (entity, prompt, daily job, backfill) | `03_sprint_3_message_digest_foundation.md` |
| 4 | Digest retrieval in chat + message pull + badges | `04_sprint_4_digest_retrieval.md` |
| 5 | Hardening, admin config, docs, E2E | `05_sprint_5_hardening.md` |

Order rationale: the eval harness comes first so Sprint 2's storage/prompt changes
and every later tuning decision can be measured instead of eyeballed. Sprints 3 and 4
are separable so the (risky) retrieval tuning does not block the (mechanical)
extraction pipeline.

## Global constraints

- **Size**: the combined injected context (summary + digest block + pulled message
  excerpts) must stay inside the existing 10–15k char window. New budgets are
  carved out of `TARGET_WINDOW_CHARS`, not added on top.
- **No hardcoded model names** — everything resolves via `ModelConfigService`
  (`SUMMARIZE`, `MEM`, VECTORIZE/pinned embedding).
- **Internal prompts use the `tools:` prefix** (`tools:message_digest`), seeded via
  `PromptCatalog`, so they are never selectable by classification.
- **Migrations**: raw idempotent `addSql()` only (Galera rules in `AGENTS.md`);
  never touch `Schema $schema`.
- **BCONFIG defaults are bootstrap-only**; services fall back to constants so no
  migration is needed for defaults, but any default that must change on existing
  installs ships an explicit UPDATE migration.
- Widget stays the intentional outlier (fixed prompt, no memories, `WIDGET:{id}`
  RAG scope) — digests are for **identified users**, never widget visitors.
- Incognito/guest chats never produce summaries or digests.
