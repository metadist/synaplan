# Sprint 3 — Message Digest Foundation

Branch: `feat/message-digest-foundation`
Answers: *"a digest of key messages in the Qdrant vector database, updated every
day, using the memory extraction model out of band, as structured lists
('office rent letter to realtor about the increase of payments', messageid: 1234)"*

## What a digest entry is

One searchable line per **key message** (not per turn, not per chat):

```json
{
  "title": "office rent letter to realtor about the increase of payments",
  "message_id": 1234
}
```

- `title`: one dense sentence, written by the MEM model, in the language of the
  message, naming WHAT the message is/does and its key entities (topic, people,
  documents, amounts, dates).
- Smalltalk, acknowledgements, follow-up fragments → no entry. The model is
  explicitly allowed to return an empty list.
- Messages with attached files (`BFILETEXT`) are prime candidates — the file text
  excerpt is part of the extraction input.

## Storage

### MariaDB (authoritative): `BMESSAGEDIGESTS`

| Column | Type | Notes |
| ------ | ---- | ----- |
| `BID` | bigint PK | ms-timestamp scheme like `BUSERMEMORIES` |
| `BUSERID` | bigint, indexed | |
| `BCHATID` | bigint | |
| `BMESSAGEID` | bigint, indexed, UNIQUE with BUSERID | one digest per message |
| `BTITLE` | VARCHAR(500) | the searchable one-liner |
| `BCHANNEL` | VARCHAR(20) | web / whatsapp / email / mcp / api |
| `BSOURCEDATE` | bigint unix | timestamp of the source message (recency ranking) |
| `BACTIVE` | tinyint | soft delete |
| `BCREATED` | bigint unix | |

Migration: raw idempotent `addSql`, Galera-safe.

### Qdrant: collection `user_message_digests`

- Point ID: UUIDv5 of `dig_{userId}_{digestId}` via `QdrantPointId` (extend the
  helper's documented prefixes).
- Vector: embedding of `title` using the same resolver chain as memories
  (`MemoryEmbeddingModelResolver` → pinned/VECTORIZE, seeded bge-m3 1024-dim).
- Payload: `user_id`, `message_id`, `chat_id`, `channel`, `source_date`, `title`,
  `active`, embedding metadata (`embedding_model_id`, `vector_dim`, `indexed_at`) —
  same discipline as `UserMemoryService::storeInQdrant()`.
- Auto-create collection like the existing ones.

## Extraction pipeline (out of band — never on the hot path)

### Prompt: `tools:message_digest`

Seeded via `PromptCatalog` (EN, ownerId 0) — `tools:` prefix is mandatory so
classification can never select it. Content outline:

- Input: a batch of messages (`[#id, direction, date, channel] text (+ clipped file
  text)`), plus the list of digest titles that already exist for those messages'
  chats (dedup context).
- Output: strict JSON array `[{"title": "...", "message_id": 123}, ...]`;
  empty array allowed; titles in the message's language; only IN(user) messages and
  substantive OUT deliverables (created documents, final answers with lasting value).

### Service: `MessageDigestService` (`backend/src/Service/Digest/`)

- `digestBatch(User $user, array $messages): int` — renders the prompt, calls
  `AiFacade::chat()` with `ModelConfigService::getMemoryModelConfig()` (the memory
  extraction model, per requirement), validates JSON (message_id must be in the
  input batch — never trust invented IDs), upserts `BMESSAGEDIGESTS` + Qdrant.
- Records usage via `RateLimitService` (source `DIGEST`).
- `final readonly`, constructor DI, no Symfony coupling beyond the usual services.

### Daily job: `app:digest:run`

Console command wired into the scheduler-role daily block in
`_docker/backend/lib/container-runtime.sh` (next to `app:updates:check`):

- Per-user **watermark** = max `BMESSAGEID` already digested (query
  `BMESSAGEDIGESTS`) plus a `DIGEST.LAST_RUN_*` BCONFIG row for bookkeeping;
  processes messages with `BID > watermark` and `BUNIXTIMES < now - quiet period`
  (e.g. 1 h, so active conversations settle first).
- Batching: N messages per AI call (default 25), M calls per user per run
  (cost cap), ordered oldest first — idempotent and resumable by design.
- Skip: widget-owner messages from widget sessions, incognito, guest-session
  processing user, users with memories disabled.
- Options: `--user=ID`, `--dry-run`, `--limit`.

### Backfill: `app:digest:backfill`

Same service, explicit invocation for history: `--user=ID --since=YYYY-MM-DD
[--all-users] [--limit=N]`. This is how the "3 months ago" use case gets covered for
existing installs. Document expected cost per 1000 messages in the command help.

## Local dev note

Local `docker-compose.yml` has no scheduler service — document
`docker compose exec backend php bin/console app:digest:run` as the manual tick
(same situation as `app:saved-tasks:tick`).

## Steps

1. Entity + repository + migration; `QdrantPointId` prefix + collection bootstrap.
2. `PromptCatalog` seed `tools:message_digest`.
3. `MessageDigestService` with strict JSON validation.
4. `app:digest:run` (watermark, batching, skips) + `app:digest:backfill`.
5. Scheduler wiring + `synaplan-platform` cron note (follow-up PR in that repo —
   private, references only the command name).

## Tests (sprint gate)

- `MessageDigestServiceTest` — fake AI + `QdrantClientMock`: happy path, empty
  result, invalid JSON, invented message_id rejected, dedup (existing digest for a
  message → no duplicate), file-text messages included, language preserved
  (assert prompt content).
- `DigestRunCommandTest` — watermark respected across two runs (idempotency),
  quiet-period filter, per-run caps, skip rules (widget/guest/incognito).
- `BMESSAGEDIGESTS` repository test — upsert-by-message, unique constraint.
- Seeder test: prompt exists, `tools:` prefixed, excluded by `MessageSorter`
  (`excludeTools`) — mirror existing internal-prompt tests.
- Full unfiltered gate.

## Explicitly out of scope (Sprint 4)

Retrieval at chat time, message pulling, badges, frontend.
