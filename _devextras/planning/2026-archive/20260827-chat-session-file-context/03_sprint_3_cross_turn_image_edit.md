# Sprint 3 — Cross-Turn Image Edit (pic2pic from history)

Branch: `feat/cross-turn-image-edit`
Answers the reported bug directly: *"a user has asked to create an image and
then asks to change a color in an image, the image then created was completely
new and different."*

Depends on: Sprint 1 (catalog), Sprint 2 (`input_mode` in the classification,
correct routing of edit follow-ups to `mediamaker`).

## The change

`MediaGenerationHandler::handleStream()`
(`backend/src/Service/Message/Handler/MediaGenerationHandler.php`), after the
current pic2pic decision (~163–169):

```php
$attachedImagePaths = $this->collectAttachedImagePaths($message, $referenceImagePaths);

// NEW: cross-turn edit — no image on this message, but the request edits
// an image from earlier in the conversation (sorter set BINPUTMODE, or the
// mediamaker prompt extractor flagged an edit). Resolve the newest thread
// image via the catalog and treat it as the pic2pic reference (#session-files).
if ([] === $attachedImagePaths && $this->isEditIntent($classification, $promptData)) {
    $catalog = $this->conversationFileCatalog->build($message, $thread);
    $target = $this->conversationFileCatalog->latestImage($catalog);
    if (null !== $target) {
        $attachedImagePaths[] = $target->absolutePath;
        $editSourceFile = $target;   // for progress + metadata below
    }
}

$isPic2Pic = !empty($attachedImagePaths);
```

### `isEditIntent()` — when do we reach into history?

Deliberately conservative — reusing a file on a "draw me something new" request
is worse than the current bug. All of the following count as edit intent;
anything else keeps today's behavior:

| Signal | Source |
| ------ | ------ |
| `classification['input_mode'] === 'reference_images'` with no current attachment | AI sorter (S2) — the primary signal |
| `media_type === 'image'` + explicit edit verdict from the mediamaker prompt extractor (see below) | `MediaPromptExtractor` |

Explicit new-image phrasing ("another one", "something completely different",
"neues bild") must map to `text_only` — that discrimination belongs to the
sorter/mediamaker prompts, not to a PHP keyword list.

### `mediamaker` prompt extension (`PromptCatalog::mediaMakerPrompt` ~1143)

Today the prompt assumes attached images for edit mode. Extend it so the
extractor, which receives `$thread`, returns an explicit edit flag:

- Input addition: the S1 `renderInventoryBlock()` images of the thread
  ("Images available from this conversation", newest first, with markers).
- Output addition: `"edit_of": "file:123"` (marker of the referenced image) or
  absent for a fresh generation. This also opens the door to model-chosen
  targets later — v1 only distinguishes "edit newest" vs "fresh".
- Enhancement rule: when editing, the prompt must describe the CHANGE
  ("make the car blue, keep everything else"), not re-describe the whole
  scene — re-description is what makes edits drift.

### Target selection (v1, deterministic)

1. Current-message attachment → always wins (unchanged behavior).
2. Else `latestImage()` of the thread — newest generated or uploaded image.
3. If the extractor returned a valid `edit_of` marker that resolves in the
   catalog, it overrides recency (accept only markers the catalog offered —
   the `DocumentImageCatalog` never-invent-a-marker discipline).

### Model resolution & fallback

- Pic2pic path resolves the `PIC2PIC` model exactly as today (no change; no
  hardcoded names).
- If no PIC2PIC model is configured/active for the user, log
  (`MediaGenerationHandler: cross-turn edit requested but no PIC2PIC model`)
  and fall back to today's TEXT2PIC — degraded, never broken.

### User feedback + telemetry

- Progress: `notify($progressCallback, 'editing', 'Editing <displayName>…')`
  before the provider call (SSE `progress` event — frontend surfacing in S5).
- Result metadata: `edit_source_file_id`, `edit_source_path` so the OUT
  message records what was edited (frontend + debugging).
- Log line with `is_pic2pic`, `edit_source`, `catalog_size` next to the
  existing "Starting media generation" entry (~194).

### Multitask parity

`MediaGenerationRunner` (`backend/src/Service/Multitask/Execution/Runner/`)
already passes `reference_image_paths` for same-turn chains (#1144) — those
arrive as `$extraImagePaths` and keep priority. The cross-turn lookup only
fires when both current attachments AND caller references are empty, so #1144
behavior is untouched by construction.

## Steps

1. Inject `ConversationFileCatalog` into `MediaGenerationHandler`; add the
   cross-turn resolution block + `isEditIntent()`.
2. Extend `mediamaker` prompt (inventory block in, `edit_of` out) +
   `MediaPromptExtractor` parsing; rollout for existing installs per the S2
   prompt-update path.
3. Progress event, metadata, logging.
4. Apply the same resolution to the non-streaming `handle()` path (channel
   parity: WhatsApp/email/MCP flow through it).
5. Re-record characterization snapshots if the mediamaker prompt shape drifts
   them; review every line.

## Tests (sprint gate)

- `MediaGenerationHandlerTest` (fake AI + fixture thread):
  - generate → "make it blue" (`input_mode=reference_images`, no attachment)
    → provider called with `images=[<previous generated file>]`, PIC2PIC model;
  - same follow-up but user attaches a NEW image → attachment wins;
  - fresh request (`input_mode=text_only`) in a thread full of images → no
    reference passed (no false-positive reuse);
  - thread image deleted from disk → graceful TEXT2PIC fallback, no exception;
  - no PIC2PIC model configured → TEXT2PIC fallback + log;
  - multitask `reference_image_paths` present → cross-turn lookup skipped.
- `MediaPromptExtractorTest` — `edit_of` parsed, invalid/invented markers
  rejected, enhancement keeps edit phrasing.
- Non-streaming `handle()` path covered once (channel parity).
- Full unfiltered gate.

## Explicitly out of scope

- Model-chosen historical targets beyond newest + `edit_of` v1 ("edit the
  SECOND image I made yesterday") — needs the S2 inventory to grow message
  dates and better prompt evidence first.
- Video/audio cross-turn references (video-from-old-image works only via the
  existing URL/multitask paths for now).
- Frontend indicator (S5).
