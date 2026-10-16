# S4 — Channel turns as named stages

| | |
| - | - |
| **V3 §7** | #4 |
| **Pull requests** | Planned as three on the chain (master plan §0 #6): **S4a** `refactor(widget): the widget turn as named stages` on `refactor/cleanup-v4-s4a-widget-turn` (from S3); **S4b** `refactor(email): the inbound email turn as named stages` on `refactor/cleanup-v4-s4b-email-turn` (from S4a); **S4c** `refactor(whatsapp): the WhatsApp turn as named stages` on `refactor/cleanup-v4-s4c-whatsapp-turn` (from S4b) |
| **Class** | backend-only |
| **Owner decision** | — |
| **Chore** | C4, run once per pull request (loop §7 steps 1–6) |

## 1. Goal

The three channel entry points stop being 600–735-line methods. Each turn
reads as its stages: intake and limits, the conversation, the reply.
Nothing a visitor, a mail sender or a WhatsApp user sees changes, and a
reply snapshot per channel proves it.

## 2. Measured (2026-10-16)

| Method | Lines | Class | Phases inside (from the code's own comments) |
| ------ | ----- | ----- | --------------------------------------------- |
| `WidgetPublicController::message()` (line 450) | 735 | 2,234 lines, 27 deps; private `sendSse()` (line 2,014), a copy of `Realtime\SseWriter` | widget active → session (test / internal mode, country) → human takeover → session and owner limits → chat + welcome message → incoming message, realtime event, auto-handoff, files, counters (one flush) → human-mode return + title → model from widget config → `processStream()` with detach-on-navigation → chunk buffering, outgoing message, SSE |
| `WebhookController::email()` (line 112) | 606 | 1,085 lines, 19 deps | validation → raw-MIME rescue (#1077) → keyword address → idempotency → user find / create → rate limit → chat, incoming message, metadata, voice-reply flag → `process()` → provider / model → TTS reply → generated media onto the outgoing message (#626) → rolling summary → send |
| `WhatsAppService::handleIncomingMessage()` (line 715) | 601 | 2,563 lines, 25 deps | duplicate guard → unsupported types (#633) → input type → verification code → rate limit → content and media → chat → message record, metadata, activity bump, realtime → media errors → read receipt + typing → `processStream()` (forced image description without a caption) → `[Doc:]` links, citations (#652) → reply |

No reply snapshot exists for any of the three. `WhatsAppServiceTest`,
`EmailVoiceReplyLanguageTest`, `EmailWebhookDocReferenceTest` and
`WidgetSessionServiceTest` cover pieces.

## 3. Target shape

```
Service/Widget/Turn/                         Service/Email/Turn/                       Service/WhatsApp/Turn/
  WidgetTurnGate      active, session,         InboundEmailIntake   validation, MIME,     WhatsAppIntake      duplicates, types,
                      takeover, limits                              keyword, idempotency,                     verification, limits
  WidgetConversation  chat, welcome, message,                       user, rate limit       WhatsAppConversation chat, record, media,
                      handoff, files, counters  EmailConversation   chat, message, voice                      activity, realtime
  WidgetReply         model, processStream,     EmailReply           process, TTS, media,  WhatsAppReply       processStream, links,
                      detach, chunks, SSE                            summary, send                             citations, send
```

- Each entry point keeps the order of its stages and becomes ≤ 50 lines
  (controllers, AGENTS.md) / ≤ 60 lines (`WhatsAppService`).
- The stages pass a typed turn context (`final readonly`), not the request
  or a loose array.
- The widget reply uses `Realtime\SseWriter` **only if the frames are
  byte-identical** in the snapshot. Otherwise the widget's frame format
  moves into `WidgetReply` unchanged, and the difference is recorded in
  STATUS for V5. The widget client parses those frames.

## 4. Steps

| Step | Pull request | Commit | What |
| ---- | ------------ | ------ | ---- |
| S4.1 | S4a | `test(widget): reply snapshot for the widget turn` | `WidgetTurnCharacterizationTest`: what reaches the processor (options keys), the stored messages, the SSE frames, the realtime events. Cases: answer; inactive widget; session limit; owner limit; human takeover (no AI); auto-handoff phrase; attached file; first message with welcome; internal owner session; processor error; client gone mid-stream (detach). |
| S4.2 | S4a | `refactor(widget): gate, conversation and reply stages` | The three stages; `message()` ≤ 50 lines; `sendSse()` gone or moved (§3). |
| S4.3 | S4b | `test(email): reply snapshot for the inbound email turn` | `EmailTurnCharacterizationTest`: plain mail; raw MIME body; keyword address; duplicate delivery; new sender; rate limited; voice attachment → audio reply; generated image; processor failure. |
| S4.4 | S4b | `refactor(email): intake, conversation and reply stages` | `email()` ≤ 50 lines; `WebhookController` < 800 lines. |
| S4.5 | S4c | `test(whatsapp): reply snapshot for the WhatsApp turn` | `WhatsAppTurnCharacterizationTest`: text; duplicate webhook; unsupported type; verification code; rate limited; image without / with caption; voice message; media download failure; processor failure; web search citations. |
| S4.6 | S4c | `refactor(whatsapp): intake, conversation and reply stages` | `handleIncomingMessage()` ≤ 60 lines. |
| C4 | each | `chore(planning): close V4 S4<a/b/c> — sprint test, status, ledgers` | Loop §7, per pull request |

## 5. Sprint test (per pull request)

- **Proof:** the channel's reply snapshot, recorded on the parent and
  unchanged after the split. A unit test per stage. Mutation check: swap
  two stages (limits after the conversation) and see the snapshot fail on
  the limit case.
- **Regression:** every other snapshot unchanged; the existing channel unit
  tests green without edits to their assertions. Unfiltered gates.
- **Journey:** S4a: `widget.spec.ts` (and `make -C frontend build-widget`
  before it). S4b: `email.spec.ts`, `inbound-email-handler.spec.ts`, run
  locally with `MAILHOG_URL=http://localhost:8025`. S4c: `whatsapp.spec.ts`
  runs only against the WhatsApp stub of the test stack (known-local list),
  so CI is its journey; say so in the pull request.

## 6. Reviewer focus

The widget's flush batching (one round-trip on the hot path) and its
detach-on-navigation. Email idempotency: a redelivery must never send a
second reply. WhatsApp duplicate detection under concurrency (lock +
cache).

## 7. Exit

- The three methods meet their line limits; `WebhookController` leaves the
  file ledger; the ledger entries of `WidgetPublicController` and
  `WhatsAppService` shrink by at least the moved lines.
- Three reply snapshots on `main`.
- `docs/ARCHITECTURE.md` gains "Channel turns": the three stage chains on
  one page.

## 8. Not in S4

Telegram (it already runs through `TelegramConversation`). The rest of
`WidgetPublicController` (`history()`, `upload()`, …) and `WhatsAppService`
(media download, markdown conversion): V5. The MIME map: S8.
