# Sprint 2 — Durable Summary + Channel Parity

Branch: `feat/durable-summary-channel-parity`
Answers: *"The core UX for users writing via WhatsApp, MCP, API, eMail or WebChat
should be streamlined and the same"* — for the rolling summary.

## Problem

1. The summary store is Redis-only with a 3600 s TTL. Slow channels (email,
   WhatsApp) almost always come back to a cold store — for exactly the users who
   most need continuity.
2. Only the streaming path injects the summary and only web/enqueue paths refresh
   it (see matrix in `00_master_plan.md`). Email, MCP, and the generic webhook never
   see a summary; WhatsApp and the public widget never update one.

## Design

### A. Durable store: `BCHATSUMMARIES`

New entity `ChatSummary` → table `BCHATSUMMARIES`:

| Column | Type | Notes |
| ------ | ---- | ----- |
| `BID` | bigint PK auto | |
| `BCHATID` | bigint, UNIQUE | FK-style reference to `BCHATS.BID` (no cascade — clean up in delete path, Galera rule) |
| `BUSERID` | bigint, indexed | |
| `BSUMMARY` | TEXT | the rolling summary |
| `BUPTOMESSAGEID` | bigint | high-water mark (same semantics as today's Redis field) |
| `BSUMMARIZEDCOUNT` | int | |
| `BUPDATED` | bigint unix | |

Migration: raw idempotent `addSql('CREATE TABLE IF NOT EXISTS ...')`, no
`Schema $schema` access, `serverVersion` caveats per `docs/MIGRATIONS.md`.

`ConversationSummaryService::readStored()/writeStored()` become read-through:

- read: Redis hit → return; miss → load row from `ChatSummaryRepository`, warm
  Redis (respecting the existing config-fingerprint key), return.
- write: upsert DB row, then set Redis.
- The config fingerprint moves into the row (or invalidates by comparing knobs) so
  a settings change still forces a re-bootstrap.
- Incognito chats: unchanged (no chat persisted → no summary row).
- Chat deletion (`ChatService`/repository delete path): delete the summary row.

### B. Parity wiring

1. `MessageProcessor::process()` gets the same Step 2.9 as `processStream()`
   (extract the block into a private method used by both — it is pure read).
2. `ChatHandler::handle()` injects `options['conversation_summary']` into the
   system prompt exactly like `handleStream()` (reuse
   `formatConversationSummaryForPrompt()`).
3. Refresh dispatch (`ConversationSummaryRefreshDispatcher`) after the OUT message
   is persisted in:
   - `WhatsAppService` (after OUT persist, ~line 1822 area)
   - `WidgetPublicController` (after OUT persist, ~962–980) — **only** when the
     widget session maps to an identified user chat; anonymous widget visitors are
     excluded (memories are already disabled there; keep summaries off too unless a
     persistent chat exists)
   - `WebhookController::email()` (after OUT persist)
   - MCP `synaplan_chat` path (`McpServerFactory` chat handler, after `process()`
     returns and the OUT message exists)
4. Skip rules stay identical everywhere: no chatId, incognito, feature disabled.

### C. Config

- `CACHE_TTL` keeps its meaning for the Redis layer only (freshness of the hot
  copy); durability now comes from the DB. No new BCONFIG keys needed; document the
  changed meaning in `SystemConfigService` help text (i18n: all four locales if the
  admin UI string changes).

## Steps

1. Entity + repository + migration + delete-path cleanup.
2. Read-through refactor in `ConversationSummaryService` (keep public API stable).
3. Step 2.9 into `process()`; injection into `handle()`.
4. Dispatch in the four missing channel paths.
5. Manual cross-channel smoke: long web chat → continue the same conversation via
   email and MCP → assistant retains topic (verify via injected system prompt log).

## Tests (sprint gate)

- `ChatSummaryRepositoryTest` — upsert, load, delete-with-chat.
- `ConversationSummaryServiceTest` — extend: DB fallback on Redis miss; Redis warm
  after DB read; fingerprint change forces re-bootstrap; TTL expiry no longer loses
  the summary (simulate by clearing the cache pool between calls).
- `MessageProcessorTest` — `process()` applies rolling context when store is warm
  (mirrors existing stream assertions).
- `ChatHandlerTest` — `handle()` injects the summary into the system prompt
  (mirror of `testHandleStreamInjectsConversationSummaryIntoSystemPrompt`).
- Per-channel dispatch tests: WhatsApp service, widget public controller, email
  webhook, MCP chat handler — each asserts the dispatcher was called with the right
  chat/user, and NOT called for incognito/anonymous cases.
- Snapshot check: routing characterization tests must be untouched (step 2.9 runs
  after classification; verify no drift).
- Full unfiltered gate incl. `make -C backend phpstan` (whole project).

## Risks

- `process()` is shared by email/MCP/webhook/enqueue — the new step must be
  strictly read-only and fail-open (`notApplied`) exactly like the stream path.
- Galera: migration must be re-runnable and use `IF NOT EXISTS` everywhere.
