# Multitask UX & Streaming Protocol Spec

**Decided 2026-06-07.** Build now (before Sprint 4). Full per-node token streaming;
flattened history; visible failed cards.

## Goal

When a prompt expands into a multi-node DAG (2+ tasks), the web chat shows one
**task-plan bubble** containing a card per task. Each card animates by type:
text streams token-by-token, media cards show a skeleton/shimmer until the file
arrives, and a failed task shows a visible failed card. Single-task turns are
**unchanged** (normal single bubble — no cards, no protocol change).

## Channel behaviour

| Channel | During processing | Result |
|---|---|---|
| Web | "Analyzing…" then task cards animating live | cards resolve; persisted as a flattened bubble (text + media) |
| WhatsApp | typing indicator (existing) | text message, then one message per media file |
| Email | nothing | one email: text + N attachments |

## SSE protocol (additive — only emitted for MULTI-node plans)

All events keep the existing one-JSON-object-per-line `status` shape. A turn that
emits a `plan` event puts the web client into "multitask mode" for that turn.

| `status` | Payload | Meaning |
|---|---|---|
| `plan` | `{ tasks: [{ node_id, capability, kind }], reply_node }` | plan recognized → render cards (pending). `kind` ∈ `text\|image\|video\|audio\|document\|search\|extract`. |
| `task_update` | `{ node_id, state }` | `state` ∈ `running\|done\|failed\|skipped` → update that card. |
| `task_chunk` | `{ node_id, chunk }` | append streamed text to that card. |
| `task_file` | `{ node_id, type, url }` | media for that card is ready. |

- Existing `data` (main text) chunks are **ignored by the client while in
  multitask mode** — they still flow so the OUT message text is persisted, but
  the cards are the live surface.
- `complete` is unchanged; the OUT message persists flattened (text + N files via
  the file junction). On reload, history renders the **flattened bubble** (no
  live cards) — cards are a streaming-time affordance only.

## Backend wiring

- `TaskPlanExecutor` knows the plan before the DAG runs → emits `plan` via the
  progress/status callback when the plan is multi-node.
- `DagExecutor` emits `task_update` around each node and passes each text runner a
  **node-scoped stream callback** that emits `task_chunk` (node-tagged). Media
  runners emit `task_file` when their file is produced.
- At the end the executor still calls the main `streamCallback(finalText)` so the
  OUT message text is captured (client ignores it in multitask mode), and returns
  `metadata['files']` for the multi-file persistence branch (Sprint 3b).
- Single-node / fallback path: **no `plan` event** → client behaves exactly as today.

## Streaming runners

- `ChatRunner` (chat/summarize/translate/rag) streams tokens via a streaming chat
  call, forwarding chunks through the node-scoped callback, and still returns the
  full text in its `NodeResult` (so dependents + assembly work).
- Media/extract/compose runners are not token-streamed; they emit `task_update`
  (running→done) and, for media, `task_file`.

## Frontend

- New `TaskPlanBubble.vue` (+ `TaskCard.vue`) rendered when a turn has a plan.
  Card states: pending (skeleton), running (shimmer / streaming text), done
  (final content), failed (error card). Type-specific bodies: text, image
  (skeleton→thumbnail), audio (player), document (download chip).
- `chatStream.ts` types extended with the new events.
- Reuse existing media rendering (`pushMediaPart`) for resolved files.
- i18n strings in BOTH `en.json` and `de.json` (card titles, states, errors).
- Single-task turns must look byte-identical to today (verified by E2E).

## Non-goals (now)

- Reconstructing live cards from history (we flatten).
- Per-node parallel visual (Sprint 4 makes cards update concurrently for free).
- WhatsApp/email rich cards (Sprint 5 cross-channel delivery).
