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

## 9. As delivered (2026-10-17)

`MediaGenerationHandler` leaves all three size ledgers: 2,245 → 170 lines,
22 → 8 constructor parameters, `handleStream()` 1,010 → 45 lines, and
`detachMediaToAsyncJob()` (166) is split inside `MediaJobDetach`. The branch
starts from S4c's head. Where the result differs from the plan:

- **The downloader seam brought its writers.** The ratchet freezes the
  handler's 22 constructor parameters, so a new dependency could not be
  added. The three methods that write into the upload tree (a data URL, a
  download, the public copy of an image-to-video input) and the APP_URL check
  were the only users of `UserUploadPathBuilder`; they moved verbatim into
  `MediaUploadStore`, which owns `MediaDownloader` and took that slot.
  `curl_close()` (deprecated in PHP 8.5, a no-op since 8.0) went in a commit
  of its own.
- **Thirty-five cases instead of seventeen.** The planned cases left branches
  the split moves unrecorded: the clarification, image and speech jobs, the
  job ceiling, an unreachable queue, both edit fallbacks, a task prompt's
  model, a multitask chain, an inline reference frame, incognito and widget
  turns, a missing duration, no media or none with a URL, an empty prompt, the
  admin diagnostics, a folder delivery. They were recorded additively before
  any stage moved.
- **More stages than planned, one reason to change each.** The chat hand-off
  (`MediaChatHandOff`) sits beside the guard, the memory job is
  `MediaTurnMemory`, and the background jobs are `MediaJobDetach`.
  `MediaTurnRequest` carries the turn from the model on; `MediaReferences`,
  `MediaPrompt`, `MediaModelChoice`, `MediaModel`, `MediaRefusal` and
  `StoredMedia` are the other typed values.
- **The handler ran its stages one commit early.** Each step had to keep the
  constructor at 22, so S5.5 already left the handler with its eight stages.
  S5.6 moved the conversation-edit test to `MediaReferenceResolverTest` and
  added a unit test per stage; the seven other handler tests stay turn-level,
  built through `MediaGenerationHandlerFactory`.
- **The upload directory is configured once.** `services.yaml` gave the
  handler `%kernel.project_dir%/var/uploads`; every stage that reads or
  writes uploads now takes it the same way (the store and the reference
  resolver had first copied the handler's fallback, the same path in the
  images).
- **Found, carried; the snapshot pins all three.** (1) A provider download
  that fails, or a result without a URL, is told as "We couldn't open the
  image you linked": the error classifier reads "image" with "download" or
  "url" as a failed reference image (U8, V5). (2) For a WhatsApp sender
  without a verified phone the turn has no account of its own, and the
  background-job check takes an integer: the turn fails on a type error
  unless the media limit refused first. Whether such a sender may render
  media is the owner's decision. (3) `/pic` with an attached picture renders
  with the text-to-picture default, not the edit default, while it still
  hands the picture to the provider.
