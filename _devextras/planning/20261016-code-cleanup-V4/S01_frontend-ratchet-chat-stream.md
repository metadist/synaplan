# S1 — A frontend size ratchet, then one stream handler for the chat view

| | |
| - | - |
| **V3 §7** | #1 |
| **Pull requests** | Two on the chain, because their release classes differ: **S1a** `test(frontend): size ratchet for the frontend` on `test/cleanup-v4-s1a-frontend-ratchet` (from S0) — `no-app-impact`; **S1b** `refactor(frontend): one stream handler for the chat view` on `refactor/cleanup-v4-s1b-chat-stream` (from S1a) — `ota-candidate` |
| **Owner decision** | — |
| **Chore** | C1, run once per pull request |

## 1. Goal

The frontend gets the gate the backend has had since V2: no file over 800
lines, no function over 100, today's offenders frozen and only allowed to
shrink. Then the largest file in the repository loses its biggest
problem. `ChatView.vue` handles the same stream events twice, once for
guests and once for signed-in users. S1b moves that handling into one
tested dispatch table, so row 8 (prompting flow) builds on it instead of
adding a third copy.

## 2. Measured (2026-10-16)

- 29 files under `frontend/src/` exceed 800 lines (`ChatView.vue` 6,481,
  `AdvancedWidgetConfig.vue` 3,007, `ChatWidget.vue` 3,002,
  `FilesView.vue` 2,998, `ChatInput.vue` 2,614, …). There is no size gate.
- `ChatView.vue`: `streamAIResponse()` runs from line 3,499 to 5,376
  (≈ 1,880 lines). Inside it:
  - the **guest** `onUpdate` (line 3,626) handles 32 statuses, including
    `guest_remaining` and `guest_limit_reached`;
  - the **signed-in** `onUpdate` (line 4,210) handles 41, including the
    memory statuses (`memories_loaded`, `memory_suggested`, …),
    `feedback_loaded`, `digests_loaded` and `perf`;
  - 30 statuses are handled in both, and V3's audit measured 337 of the
    guest handler's 396 code lines repeated in the signed-in one.
- `chatApi.attachStream()` (re-attach after a reload) reuses whichever
  handler the turn had. A third `onUpdate` (line 2,991) continues a
  truncated answer and handles `data`, `reasoning`, `complete` and
  `error` again.
- The payload type is `StreamUpdatePayload` (`frontend/src/types/chatStream`).
  SSE events are not in the OpenAPI contract.
- Tests: no unit test mounts `ChatView`. The E2E specs on the path are
  `chat.spec.ts` (`@crossbrowser`), `chat-again.spec.ts`,
  `chat-bubble-monotonic.spec.ts`, `guest-chat.spec.ts`,
  `incognito.spec.ts`, `multitask.spec.ts`, `long-term-memory.spec.ts`,
  `memories.spec.ts`, `ux-journeys.spec.ts`.
- `TypeScript` and `@vue/compiler-sfc` are already in
  `frontend/node_modules`; the ratchet needs no new dependency.

## 3. S1a — the ratchet

`frontend/tests/unit/architecture/sizeRatchet.spec.ts` with
`sizeRatchetBaseline.json`, the frontend twin of `SizeRatchetTest`:

| Limit | Measured on | Key |
| ----- | ----------- | --- |
| A file has at most 800 lines | every `.ts` and `.vue` under `src/` except `src/generated/` | path |
| A function has at most 100 lines | function declarations, function expressions and arrow functions, and object or class methods — in `.ts` files and the `<script>` blocks of `.vue` files (parsed with `@vue/compiler-sfc`, measured with the TypeScript compiler API) | `path::outer.inner`; an anonymous callback is named after its property (`streamAIResponse.onUpdate`), and a repeat gets `#2`, `#3` in source order |

Every offender today is frozen at its value. An unlisted offender fails,
and a listed one may only shrink. `docker compose exec -T -e
UPDATE_SIZE_RATCHET=1 frontend npx vitest run
tests/unit/architecture/sizeRatchet.spec.ts` regenerates the ledger
(removals and smaller numbers only), and a stale-entry test names entries
that no longer fire. A guard asserts the scan saw more than 500
files, so a broken scan cannot pass vacuously.

| Step | Commit | What |
| ---- | ------ | ---- |
| S1.1 | `test(frontend): measure file and function sizes` | `tests/unit/architecture/sourceMetrics.ts` with its own unit tests (a nested arrow, an object method, a `.vue` with two script blocks). |
| S1.2 | `test(frontend): size ratchet with a shrink-only ledger` | The spec and the ledger. |
| C1a | `chore(planning): close V4 S1a — sprint test, status, ledgers` | Loop §7 |

## 4. S1b — one stream handler

```
frontend/src/services/chat/stream/
  streamUpdateHandler.ts     createStreamUpdateHandler(turn, variant): (payload) => void — a dispatch table, not an if-chain
  handlers/content.ts        data, reasoning, links, file, audio, complete, error
  handlers/progress.ts       started, preprocessing, classifying, classified, searching, …, the timeline
  handlers/tasks.ts          plan, plan_discarded, task_*, document_step, generating_file
  handlers/memory.ts         the memory and feedback statuses (signed-in only)
  handlers/guest.ts          guest_remaining, guest_limit_reached (guests only)
  streamTurn.ts              StreamTurn: the message, the stores, the timeline, notify, scroll — every side effect named
frontend/src/views/ChatView.vue   streamAIResponse() builds the request, picks the transport (member / guest / attach), wires the handler
```

- One table serves both variants. A status that only one variant handles
  is registered only for it, so a guest can never reach memory code.
- Every status handler is a small function (≤ 50 lines) with a unit test.
  Side effects go through `StreamTurn`, which tests replace with a fake.
- The continue-answer stream (line 2,991) uses the content handlers.
- No copy change. If an i18n key moves, it keeps its name and all five
  locales.

| Step | Commit | What |
| ---- | ------ | ---- |
| S1.3 | `test(frontend): stream transcripts for the chat view` | `tests/unit/views/ChatView.stream.spec.ts` mounts `ChatView` with Pinia, i18n and `useMarkdown`, and stubs `chatApi` so it replays recorded payload sequences. It snapshots the history store's message state (parts, content, timeline, files, task plan, error card, memories, version links). Sequences: plain answer; reasoning; web search with links; file analysis; generated image; generated file; multi-step task plan; plan discarded; provider error; guest answer with remaining count; guest limit reached; memories loaded and suggested; Stop mid-stream; re-attach after reload; continue a truncated answer. Recorded on S1a's head. |
| S1.4 | `refactor(frontend): the stream statuses as one dispatch table` | The handler modules, moved from the signed-in `onUpdate` with the guest differences registered per variant. |
| S1.5 | `refactor(frontend): guest and signed-in streams share the handler` | Both `onUpdate`s and the continue stream use it; the duplicated branches are deleted. |
| S1.6 | `refactor(frontend): streamAIResponse() builds and wires the turn` | Request building and transport choice only. |
| C1b | `chore(planning): close V4 S1b — sprint test, journeys, status, ledgers` | Loop §7 |

## 5. User flow (UX contract §6)

S1b is `ota-candidate`. It changes no screen, but it rewires the code
behind every chat answer, so its journeys are walked before merge.
Pointer: `ux-journeys.spec.ts` (J-UX-1…5) plus these:

| Journey | Who and what | Where the result is found |
| ------- | ------------ | ------------------------- |
| **J-V4-1** | A signed-in user asks a question, watches the answer stream with its progress timeline, presses Stop halfway, then presses Again with another model | The cancelled answer and the new version in the same chat; the version switcher shows both |
| **J-V4-2** | A guest asks on the landing chat, sees the answer and the remaining-messages notice, and keeps asking until the limit | The register prompt with one primary action; the guest's answers are still on screen |
| **J-V4-3** | A signed-in user reloads the page while an answer is still generating | The same bubble re-attaches and completes; no duplicate, no stuck "running" |
| **J-V4-4** | A signed-in user asks for an image, then uploads a PDF and asks about it | The image in the chat and in Files → Generated; the analysis answer below the PDF |

Exit bullets:

1. J-V4-1…4 walked in the browser (U10), recorded in STATUS.
2. Findability (U2): every answer is in the same chat and in the chat
   list within ten seconds, as before.
3. Copy (U3): no string changes; the five locales are untouched, or a
   moved key keeps all five.
4. Empty, error and flag-off states (U5, U8, U11): the provider-error
   card, the guest limit and the cancelled state look as before.
5. Dark, V2 design, 320 px (U9): J-V4-1 and J-V4-2 walked in light and
   dark, and at 320 px.

## 6. Sprint test

- **S1a proof:** the spec fails on a scratch file of 801 lines and a
  scratch 101-line function (tests inside the spec, on fixtures). The
  measurement unit tests. Mutation check: drop arrow functions from the
  measurement and see the ledger test report `ChatView.vue::streamAIResponse`
  missing.
- **S1b proof:** the stream transcript snapshot, recorded on S1a's head,
  is unchanged after every step. A unit test per status handler. Mutation
  check: register `memories_loaded` for guests and see the guest
  transcript fail.
- **Regression:** the full Vitest suite, `vue-tsc`, lint;
  `make test-e2e`; `make -C frontend test-e2e-layout`;
  `npm run test:e2e:firefox`; `guest-chat.spec.ts` needs a fresh guest
  quota on the dev stack (known-local list).
- **Journey:** §5. Before the app release that carries S1b, the
  `synaplan-apps` device check for streaming runs against that build. The
  pull request says it is pending.

## 7. Reviewer focus

Abort handling: every handler must still return early once the turn is
aborted (`streamingAbortController.signal.aborted`). The order of side
effects on `complete` (save, version link, memories, scroll) moves with
the code. Guests must not reach signed-in stores.

## 8. Exit

- The frontend ratchet runs in `make -C frontend test` and CI.
- `ChatView.vue` ≤ 4,500 lines; `streamAIResponse()` ≤ 200; no status
  branch exists twice; the ledger entries for `ChatView.vue` shrank.
- `docs/FRONTEND_CONVENTIONS.md` names the ratchet and the stream handler.

## 9. Not in S1

The other 28 files over 800 lines. `ChatWidget.vue`: whether its stream
code repeats the same statuses is recorded in STATUS for V5. Any visual
change.
