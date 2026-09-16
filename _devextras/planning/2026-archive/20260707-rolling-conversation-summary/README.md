# Rolling Conversation Summary (Condensing Memory Window)

Status: in progress (feature branch `cursor/rolling-conversation-summary-3556`)

## Problem

Today the chat only replays a hard window of recent turns to the model
(`MessageRepository::findChatHistory(userId, chatId, 30, 15000)` — max 30
messages / ~15 000 chars). There is **no summarization** of older turns. Once a
message scrolls out of that window the model can no longer see it, so "7-8
answers later" the assistant only remembers the topic / the user's stance if it
happens to have been captured as a Qdrant *memory* or is still inside the raw
window.

The exploration report for this codebase confirmed: "there is no rolling
conversation summarization in the live chat path".

## Goal

Introduce a **rolling, tiered, condensing summary** of the conversation that is
injected into the chat **system prompt**, so long threads keep their topic,
the user's position, decisions, and any external (web) results that were
discussed — while the total conversational context (summary + verbatim recent
turns) always stays inside a **10 000–15 000 character memory window**.

Key requirements from the request:

1. Rolling / condensing summary injected as a system prompt.
2. **Tiered condensing**: older parts condensed *more*, the most recent turns
   condensed *less* (or kept verbatim). Gradient compression.
3. Always stay inside a **10–15k character** conversational memory window.
4. Summarization model must be **configurable** (an operator could point it at a
   GPT-OSS-120B model). **Default: reuse the sorting (`SORT`) model** to condense.
5. Everything togglable via config; safe fallback to current behaviour.

## Design

### Where it plugs in

```
StreamController → MessageProcessor.processStream()
    ├─ load recent window (unchanged, used by classifier)
    ├─ classify
    ├─ [NEW] ConversationSummaryService.buildRollingContext(fullHistory)
    │        → { applied, summary, recentMessages }
    │        → if applied: options['conversation_summary'] = summary
    │                      conversationHistory = recentMessages   (trim thread)
    ├─ web search
    └─ InferenceRouter.routeStream(..., conversationHistory, options)
             → ChatHandler.handleStream()
                   └─ [NEW] append options['conversation_summary'] to system prompt
```

- The summary is built from the **full** chat history
  (`MessageRepository::findAllByChatId`), not the 30/15k window, so it can carry
  information that has already scrolled out.
- The **recent** turns returned by the service are kept verbatim and become the
  `$thread` replayed to the model; the older turns are represented only by the
  condensed summary. Together they stay inside the target window.
- Classification input is left untouched (keeps routing/characterization
  snapshots stable).
- Other handlers (image/video/file) simply ignore the extra option.

### Tiered condensing algorithm (`ConversationSummaryService`)

Given the full chronological history and a target window:

1. If disabled or no `chatId` → return `applied = false` (no AI call, no change).
2. Walk newest → oldest, accumulating characters until the **recent verbatim
   budget** is filled. Those newest messages are kept verbatim (the current
   in-flight user message is always newest, so it is never summarized).
3. The remaining **older** messages are the summarization source (capped at
   `MAX_SOURCE_MESSAGES` to bound cost on very long chats).
4. If there are no older messages → `applied = false` (short chat behaves exactly
   like today, no AI call).
5. Split the older span into **N recency tiers** (default 3). Assign a
   *decreasing* compression instruction per tier: oldest tier → 1–2 sentences
   (durable facts / overall topic only); middle tiers → short paragraph; the
   tier nearest the verbatim window → keep specifics, the user's current
   position and open questions.
6. Call the configured summary model once. Enforce `SUMMARY_MAX_CHARS` on the
   result so `recent_verbatim + summary ≤ target window`.
7. Cache the result keyed on `chatId + last-older-message-id + older-count +
   config fingerprint`, so consecutive turns that share the same older span do
   not re-summarize (roughly one summary call every few turns).
8. Any failure (provider error, empty result) → `applied = false`; the caller
   keeps the standard windowed history. The feature can never break a turn.

### Model resolution

`ModelConfigService::getSummaryModelConfig(?userId)` resolves in order:

1. `DEFAULTMODEL.SUMMARY` (user then global) — operator override, e.g. GPT-OSS-120B.
2. `DEFAULTMODEL.SORT` — **default**: reuse the sorting model (per the request).
3. `DEFAULTMODEL.CHAT` — last resort.

No new seed rows required; falls back through existing config.

### Configuration (`CONVERSATION_SUMMARY` BCONFIG group, owner 0)

Backed by `ConversationSummaryConfigService`, defaults in
`ConversationSummaryConstants` (works with zero DB rows, like
`FeedbackConfigService::getMaxChatMemories`):

| Setting                 | Default | Meaning                                              |
| ----------------------- | ------- | ---------------------------------------------------- |
| `ENABLED`               | `1`     | Master toggle                                        |
| `TARGET_WINDOW_CHARS`   | `12000` | Combined window (clamped 10 000–15 000)              |
| `RECENT_VERBATIM_CHARS` | `8000`  | Budget for verbatim recent turns                     |
| `SUMMARY_MAX_CHARS`     | `4000`  | Hard cap on the injected summary                     |
| `MAX_SOURCE_MESSAGES`   | `200`   | Max older messages fed to the summarizer             |
| `TIERS`                 | `3`     | Number of recency tiers for gradient compression     |
| `CACHE_TTL`             | `3600`  | Seconds to cache a summary for a stable older span   |

Invariant enforced in the service: `RECENT_VERBATIM_CHARS + SUMMARY_MAX_CHARS`
is kept within `TARGET_WINDOW_CHARS` (clamped to the 10–15k band).

## Files

New:
- `backend/src/Service/Message/ConversationSummaryConstants.php`
- `backend/src/Service/Message/ConversationSummaryConfigService.php`
- `backend/src/Service/Message/RollingSummaryResult.php`
- `backend/src/Service/Message/ConversationSummaryService.php`

Modified:
- `backend/src/Service/ModelConfigService.php` — `getSummaryModelConfig()`
- `backend/src/Service/Message/MessageProcessor.php` — wire it into `processStream()`
- `backend/src/Service/Message/Handler/ChatHandler.php` — inject summary into system prompt

Tests:
- `backend/tests/Unit/Service/Message/ConversationSummaryServiceTest.php`
- `backend/tests/Unit/Service/Message/ConversationSummaryConfigServiceTest.php`
- `backend/tests/Unit/Service/ModelConfigServiceSummaryModelTest.php`
- `ChatHandlerTest` — assert the summary lands in the system prompt
- `MessageProcessorTest` — constructor update

## Notes / follow-ups

- Wired into the **streaming** path (the main chat flow). The non-streaming
  `process()` / email path can be wired later using the same option; ChatHandler
  already reads `conversation_summary` from options in both `handle()` and
  `handleStream()` prompt builders.
- BCONFIG defaults are bootstrap-only; because the service falls back to
  constants, no migration is needed to ship defaults. To roll out a changed
  default to existing installs later, ship an UPDATE migration.
- Could later become truly incremental (persist the last summary and only fold
  in new turns) — deferred; the cache keeps per-turn cost low for now.
