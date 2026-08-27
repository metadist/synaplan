# Status — Chat Session File Context

| Sprint | Branch | State | Notes |
| ------ | ------ | ----- | ----- |
| 1 — Conversation file catalog | `feat/conversation-file-catalog` | not started | |
| 2 — Routing awareness (sorter, fast-path, BINPUTMODE) | `feat/file-context-routing` | not started | snapshot re-record required |
| 3 — Cross-turn image edit (pic2pic from history) | `feat/cross-turn-image-edit` | not started | the headline bug fix |
| 4 — Documents & vision parity | `feat/file-context-doc-vision-parity` | not started | |
| 5 — Frontend affordances & hardening | `feat/file-context-frontend` | not started | ota-candidate |

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

## Measured results (fill in per sprint)

| Check | Before | After |
| ----- | ------ | ----- |
| "change the color" reuses prior image (pic2pic) | no — fresh text2pic | |
| Fast-path defers after image generation turn | no | |
| Sorter sets `reference_images` without current attachment | no | |
| Document follow-up regression suite | green | |
