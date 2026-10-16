# S5 — The media generation turn as stages

| | |
| - | - |
| **V3 §7** | #5 |
| **Branch** | `refactor/cleanup-v4-s5-media-turn`, from S4c's branch |
| **Pull request** | `refactor(media): media generation turn as stages` |
| **Class** | backend-only |
| **Owner decision** | — |
| **Chore** | C5 |

## 1. Goal

`MediaGenerationHandler::handleStream()`, the longest method on the chat
path, becomes a short sequence of named stages. Every guard, every billing
rule and every issue fix it carries keeps its behaviour. V3's K2 already
removed its copied helpers.

## 2. Measured (2026-10-16)

`Service/Message/Handler/MediaGenerationHandler.php`: 2,245 lines, 22
constructor parameters. `handleStream()` (line 142) has 1,010 lines;
`detachMediaToAsyncJob()` has 166. `handle()` (line 101) is a buffering
wrapper over `handleStream()`, so there is no twin to collapse.

The phases inside `handleStream()`, in order (from its comments):

| # | Phase | Carries |
| - | ----- | ------- |
| 1 | References | attached images, explicit reference images (#1144), image URLs in the text, the multitask runner's forwarded URLs, the cross-turn edit source |
| 2 | Prompt | the mediamaker extraction; the extractor's final media type; re-extraction without edit wording when the type changed |
| 3 | Model | slash command, Again `model_id` > task-prompt `aiModel` > default; retired-model successor; no-default error (#1320) |
| 4 | Guards | TTS-script re-route to chat (`TtsScriptGuard`); text-to-video on an i2v model; unreachable `APP_URL` for i2v; inline reference images |
| 5 | Limits | media rate limit; cost-budget backstop |
| 6 | Generate | sync generation, or detach to an async job |
| 7 | Store | download / data URL, `BFILES` row (Generated gallery), video thumbnail, marker, folder delivery |
| 8 | Stream and bill | the reply stream; cost recorded the moment the provider billed (#1146); Stop as a neutral outcome with its cost recorded |

Unit tests exist per concern (`MediaGenerationHandler{AsyncDetach,
AudioEcho, ConversationEdit, EditBilling, MarkerStorage, RetiredModel,
TextReply}Test`). There is no turn-level snapshot.

## 3. Target shape

```
Service/Message/Handler/Media/
  MediaReferenceResolver   phase 1
  MediaPromptResolver      phase 2
  MediaModelResolver       phase 3
  MediaRequestGuard        phase 4 — returns an outcome: proceed, answer as chat, or a named refusal
  MediaTurnBudget          phase 5
  MediaGenerationRun       phase 6 (sync) — detachMediaToAsyncJob() / detachVideoToAsyncJob() move here
  GeneratedMediaStore      phase 7 (uses MediaDownloader, S5.1)
  MediaDownloader          the cURL / file_get_contents transport for external media URLs, moved verbatim
  MediaTurnBilling         phase 8 billing (#1146 recorded at the provider-billed moment)
  MediaTurnRequest         final readonly value object carried between the stages
MediaGenerationHandler     handle(), handleStream() ≤ 60 lines; ≤ 10 deps
```

The stage names are the plan; the boundaries are the phases above. A
phase that turns out to be two reasons to change splits; two that share
all state merge. Write the result in "As delivered".

## 4. Steps

| Step | Commit | What |
| ---- | ------ | ---- |
| S5.1 | `refactor(media): media downloads behind an injectable downloader` | `downloadMedia()` fetches external URLs with native cURL, with `file_get_contents()` as a fallback (lines 1,243–1,264); no HTTP-client double can intercept either. That transport moves **verbatim** (`git diff --color-moved`) into a `MediaDownloader`: the same options, timeouts, user agent, IPv4 pin and error messages. The handler gets it as a constructor dependency, so tests can replace it. Its own test fetches a fixture from a server on `127.0.0.1` inside the test container, never an external host. |
| S5.2 | `test(media): turn snapshot for media generation` | `MediaTurnCharacterizationTest` through `handle()` and `handleStream()`: the status events, what reaches `AiFacade` / the async job bus, the stream chunks, the downloaded URLs, the stored file rows, the usage records, the result shape. Cases: image from an external URL; image as a data URL; image edit from an attachment; cross-turn edit; image URL in the text → video; video detached to async; audio (TTS); TTS script re-routed to chat; no default model; i2v without a reference; unreachable `APP_URL`; rate limited; over budget; provider failure; download failure; Stop mid-generation (cost recorded); retired model with successor. External URLs go through a recording `MediaDownloader` double that returns fixture bytes; data URLs go through `saveDataUrlAsFile()` into a temporary upload directory. The test asserts that no request left the process. Recorded after S5.1, before any stage moves. |
| S5.3 | `refactor(media): resolve references, prompt and model as stages` | Phases 1–3. |
| S5.4 | `refactor(media): guards and limits as stages` | Phases 4–5. |
| S5.5 | `refactor(media): generation, storage and billing as stages` | Phases 6–8. |
| S5.6 | `refactor(media): the handler runs the stages` | `handleStream()` ≤ 60 lines; the existing unit tests move to the stage they test, assertions unchanged. |
| C5 | `chore(planning): close V4 S5 — sprint test, status, ledgers` | Loop §7 |

## 5. Sprint test

- **Proof:** `media_turn.json`, recorded after the S5.1 seam and before
  any stage moves, unchanged after every later step; a unit test per stage
  and for `MediaDownloader`. Mutation check: move the budget check after
  generation and see the "over budget" case fail.
- **Regression:** the seven existing handler tests green with unchanged
  assertions; every other snapshot unchanged; unfiltered gates.
- **Journey:** `chat.spec.ts` (media cases), `multitask.spec.ts`,
  `file-manage.spec.ts` (Generated gallery) in `make test-e2e`.

## 6. Reviewer focus

Billing: #1146 must still record the cost before the stream can be torn
down, and on Stop. The order of the guards against the rate limit and the
budget: a refused request must not be billed or counted.

## 7. Exit

- `MediaGenerationHandler` < 800 lines, ≤ 10 deps, no method > 100 lines;
  it leaves all three ledgers.
- `docs/ARCHITECTURE.md` gains "Media turn".

## 8. Not in S5

`MediaGenerationService` and the provider calls (S10). The MIME map (S8).
