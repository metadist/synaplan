# S2 — Align the buffered and streamed turn

| | |
| - | - |
| **V3 §7** | #2 |
| **Branch** | `fix/cleanup-v4-s2-turn-alignment`, from S1b's branch |
| **Pull request** | `fix(message): one buffered and streamed turn, widget turns classified once` |
| **Class** | backend-only |
| **Owner decision** | **D1** — which differences align (§2). Tick before step S2.2. |
| **Chore** | C2 |

## 1. Goal

One turn behaves the same whether it is answered as a stream or buffered.
The two transports keep only what is truly transport: who emits the
terminal status, how a user stops a turn, which telemetry is collected.
First fix: a streamed widget turn runs the paid AI sorter **once**.

## 2. Measured (2026-10-16) and D1

V3 split `MessageProcessor` into shared stages and named nine differences
(V3 §3). Today they live in `MessageProcessor::process()` /
`processStream()` and in the `…ForBufferedTurn` / `…ForStreamedTurn` pairs
of `Message\Processing\TurnClassificationResolver` (474 lines).

**The double classification.** `processStream()` classifies a widget turn
at line 72 without history and sets `is_widget_mode`. It then runs the
classifier again at line 90 with the history. The second result replaces
the first and loses `is_widget_mode`. K6 pinned this in
`message_turn.json`. The cost is one extra sorter call per widget message.

| # | Difference | Buffered today | Streamed today | **D1 proposed** | Why |
| - | ---------- | -------------- | -------------- | --------------- | --- |
| W | Widget turn | generic classifier branch | classified twice, `is_widget_mode` lost | **Align:** classify once, with history; set `is_widget_mode` on the result in both | One paid call less; the flag reaches the handler |
| 1 | `rag_group_key`, `rag_limit`, `rag_min_score` | ignored | applied (`withRagScope()`) | **Align:** applied in both | A caller that scopes retrieval means it on every transport |
| 2 | `options.language` | honoured | ignored | **Align:** an explicit caller language wins, else the message language | Same input, same language |
| 3 | Fixed-prompt language | override, else `en` | message language, else `auto` | **Align:** override, else message language, else `auto` | A German Saved Task answered over email or MCP is no longer forced to English |
| 5 | Prompt metadata | reuses `classification.prompt_metadata`, lookup without language | always loads with language → `resolved_prompt_data` | **Align:** one lookup (topic, user, language), reused when the classifier already carries it; both pass the same keys | One prompt, one language |
| 6 | Search results | `classification.search_results`, removed afterwards | `options.search_results` | **Align:** `options.search_results` in both, once every reader is confirmed (`rg search_results backend/src`) | A routing verdict is not a payload carrier |
| 7 | `complete` / `error` status | emitted by the processor | emitted by the caller | **Keep**, named | The stream caller owns terminal SSE events; a buffered caller has no stream |
| 8 | User cancel | generic failure | `cancelled: true` | **Keep**, named | Only a stream has a Stop button |
| 9 | Perf timer | none | per phase | **Keep**, named | Only the stream has a consumer for phase timings |

## 3. Steps

| Step | Commit | What |
| ---- | ------ | ---- |
| S2.1 | `test(message): turn cases that pin every buffered / streamed difference` | `MessageTurnCharacterizationTest` already runs 17 cases through both entries (`widget_without_fixed_prompt`, `rag_scope_and_language`, `fixed_prompt_*`, `classifier_votes_web_search`, …). Add what D1 still needs: a fixed prompt with a message language and no caller language, a classifier verdict that carries `prompt_metadata`, and the sorter call count per case in the transcript. Recorded on the parent. |
| S2.2 | `fix(message): classify a streamed widget turn once, with its history` | Delete the early widget branch; the widget flag is applied after the one classification. Regression test: the scripted classifier is called exactly once and the handler receives `is_widget_mode: true`. |
| S2.3 | `fix(message): apply the knowledge-base scope to buffered turns` | `withRagScope()` in both entries. |
| S2.4 | `fix(message): honour the caller's language on streamed turns` | One language rule in `TurnClassificationResolver`. |
| S2.5 | `fix(message): one language rule for fixed-prompt turns` | `fixedPromptFor…` pair → one method; the status report stays per transport. |
| S2.6 | `fix(message): resolve the prompt once, in the turn's language` | `promptFor…` pair → one method. |
| S2.7 | `refactor(message): search results travel in the options on both turns` | Readers confirmed first; the commit is a `fix:` if any observable output moves. |
| S2.8 | `docs(message): name the three transport differences that stay` | `MessageProcessor` docblock and `docs/ARCHITECTURE.md` "Message turn": #7–#9 with their reason, nothing else. |
| C2 | `chore(planning): close V4 S2 — sprint test, status, ledgers` | Loop §7 |

Each `fix:` commit carries its regression test (run red on the parent
first and quoted in STATUS, loop step 4) and the
`message_turn.json` diff of exactly its cases, re-recorded with
`UPDATE_CLEANUP_V2_SNAPSHOTS=1` (the switch named in the test's header)
and reviewed line by line.

## 4. Sprint test

- **Proof:** one regression test per aligned difference, each run red on
  S1b's head and committed with its fix. A test that counts sorter calls on a streamed widget turn (1, not
  2). Mutation check: put the early widget branch back and see the count
  test fail.
- **Regression:** `message_turn.json` diff limited to the cases of S2.2–S2.7
  and quoted in the pull request; `routing_classification.json`,
  `sse_transcript.json`, `file_analysis.json`, `chat_prompt*.json`
  unchanged. Unfiltered gates.
- **Journey:** `make test-e2e` with `widget.spec.ts`, `chat.spec.ts`,
  `chat-again.spec.ts`, `saved-task-roundtrip.spec.ts`, `rag-search.spec.ts`
  green. `email.spec.ts` needs the test stack (known-local list).

## 5. Reviewer focus

Every reader of `is_widget_mode` downstream (`ChatHandler`, the widget
model override, usage logging). The language precedence when message
language, caller language and a fixed prompt all exist. Any reader of
`classification['search_results']` left behind.

## 6. Exit

- The buffered and streamed entries differ only in #7–#9, each named in
  the code with its reason.
- The `fixedPromptFor…`, `againFor…`, `forcedImageFor…` and `promptFor…`
  pairs are one method each wherever D1 aligns them; what stays per
  transport is the status report.
- A streamed widget turn makes one sorter call.
- `TurnClassificationResolver` is shorter than its 474 lines today.

## 7. Not in S2

The topic / intent rule (S3). Strict types (S11). Any change to
`ChatHandler`'s two turns (V5).
