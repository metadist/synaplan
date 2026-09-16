# Status — Chat Session File Context

All five sprints were implemented on a single branch,
`feat/chat-session-file-context`, for one PR.

| Sprint | Branch | State | Notes |
| ------ | ------ | ----- | ----- |
| 1 — Conversation file catalog | `feat/chat-session-file-context` | implemented | `ConversationFile` + `ConversationFileCatalog`; `FileRepository::findFilesByMessageIds()` generalizes the images-only lookup |
| 2 — Routing awareness (sorter, fast-path, BINPUTMODE) | `feat/chat-session-file-context` | implemented | file notes in sorter history, media fast-path guards, `input_mode` passthrough, `tools:sort` prompt; routing snapshots did NOT drift |
| 3 — Cross-turn image edit (pic2pic from history) | `feat/chat-session-file-context` | implemented | the headline bug fix; `mediamaker` is told it is editing, `editing` SSE progress event |
| 4 — Documents & vision parity | `feat/chat-session-file-context` | implemented | `DocumentImageCatalog` delegates to the shared catalog; generated-image vision behind `FILE_CONTEXT.VISION_INCLUDE_GENERATED` (default off) |
| 5 — Frontend affordances & hardening | `feat/chat-session-file-context` | partially implemented | Part A editing indicator + Part C i18n + docs done; see *Deferred* below |

## Deferred out of this PR

- "Edited from &lt;file&gt;" reference badge on the OUT message (needs message-meta
  persistence + an API/Zod surface).
- Part B: quoting an image attaches it to the composer as an explicit reference.
- Part D: the Playwright flow (nightly tier).

## Investigation baseline (2026-08-27)

- **Bug reproduced in code**: "create an image" → "change the color" produces a
  brand-new image. `MediaGenerationHandler::collectAttachedImagePaths()`
  (`backend/src/Service/Message/Handler/MediaGenerationHandler.php` ~1308) only
  reads the **current** message's attachments; `$isPic2Pic` (~169) is false on
  every text-only follow-up → fresh TEXT2PIC with a new composition.
- History reaching `MessageSorter` and the mediamaker prompt is **text only** —
  a previously generated image survives as a string, never as a file reference.
- `BINPUTMODE` is parsed by `MessageSorter::parseResponse()` (~436–443) but
  `MessageClassifier::classify()` never copies `input_mode` into the
  classification array (passthrough ends at `resolution`, ~311–317), and
  `MediaGenerationHandler` never reads it.
- Fast-path guard asymmetry: documents get `lastAssistantGeneratedFile()` /
  `threadHasGeneratedFile()` (both keyed on the `__FILE_GENERATED__:` marker,
  `MessageClassifier` ~798–827); generated images never carry that marker, so a
  short "make it blue" can even be fast-pathed to `general`.
- Working pattern to generalize: `DocumentImageCatalog` (#1382) +
  `FileRepository::findImagesByMessageIds()` already resolve thread images for
  document embedding — but only the officemaker path uses them.
- No schema change expected: `BFILES.BMESSAGEID`, `BMESSAGE_FILE_ATTACHMENTS`,
  and legacy `BMESSAGES.BFILEPATH` already hold everything the catalog needs.

## Measured results

| Check | Before | After |
| ----- | ------ | ----- |
| "change the color" reuses prior image (pic2pic) | no — fresh text2pic | yes — newest conversation image resolved as the edit source |
| Fast-path defers after image generation turn | no | yes — guarded like the document case |
| Sorter sets `reference_images` without current attachment | no | yes — history turns carry a file note the sorter reads |
| Document follow-up regression suite | green | green (unchanged assertions after the catalog refactor) |
| Generated image visible to vision chat | never | opt-in via `FILE_CONTEXT.VISION_INCLUDE_GENERATED` |
| Backend gate (`lint`, `phpstan`, `phpunit`) | green | green — 4272 tests |
| Frontend gate (`lint`, `vue-tsc`, `vitest`) | green | green — 1296 tests |
