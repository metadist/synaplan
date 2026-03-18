# VIBE CODING Plan: Pic2Pic (Image-to-Image) Generation

## Goal
Enable Synaplan to accept 1 or 2 reference images plus a text instruction and generate a new image from them.

Example:
`Put the object from image 1 into the scene of image 2.`

This must work in two paths:
- direct API path: `POST /api/v1/media/generate-from-images`
- routed Synaplan chat path: classifier -> sorter -> prompt extractor -> media handler -> provider

## Current Status
Already implemented:
- model catalog entries for pic2pic-capable models
- direct backend endpoint for multipart upload
- OpenAI Responses API path
- Google Gemini image-edit path
- unit tests for `MediaGenerationService` and `MediaController`

Not finished end-to-end:
- routed chat flow does not reliably reach pic2pic
- prompt system is still too broad for image-edit/composition requests
- prompt catalog is seeded in English only, so the prompts must explicitly follow the user's language
- automatic test coverage is missing for routing, prompt seeding, and attachment handling

## Main Problems To Fix

### 1. Routing blocker
`MessageClassifier` currently forces image attachments to `analyzefile`.

Effect:
- a user who uploads 1 or 2 images and asks for image generation/editing will not reach `mediamaker`
- the new provider pic2pic code is then bypassed

### 2. Handler gap
`MediaGenerationHandler` generates images from text, but does not yet pass attached reference images into `AiFacade::generateImage()`.

Effect:
- even if routing is corrected, the normal chat path still behaves like text-to-image

### 3. Missing `BCONFIG` Default for Pic2Pic
`MediaGenerationService` currently falls back to `TEXT2PIC` if no model is provided. But the default `TEXT2PIC` model (DALL-E 3, BID 29) does not support image inputs.

Effect:
- if a user doesn't explicitly select a model, the request fails because it routes to DALL-E 3.
- we need a dedicated `PIC2PIC` default in `BCONFIG` (pointing to Nano Banana 2, BID 190) and `MediaGenerationService` must use it.

### 4. Prompt contract is too weak
`mediamaker` currently focuses on generic prompt enhancement.

Effect:
- it does not clearly separate:
  - text-to-image
  - image editing
  - image composition from 2 references
  - style transfer
  - background replacement

### 5. Language handling must be explicit inside the English prompts
`MessageSorter` supports these 10 languages:
- `de`, `en`, `it`, `es`, `fr`, `nl`, `pt`, `ru`, `sv`, `tr`

`PromptCatalog` is seeded in English only. That is fine, but the prompt text must explicitly instruct the model to:
- detect the user's language from the latest message and attachments
- classify intent independently from language
- return prompt text in the user's language
- preserve the user's wording when transforming image-edit requests

Effect:
- we keep one seeded system-prompt source
- prompt behavior becomes language-aware without duplicating prompts 10 times

### 6. The previous plan overstated test status
The old wording said "all checks pass" while `make -C backend test` still has a pre-existing unrelated failure.

Correct wording:
- pic2pic-specific unit tests passed
- the full backend suite is not fully green yet because of an unrelated existing failure

## Use Case Validation: "Pattern + Room" (First Customer API Priority)

**Scenario**: A user wants to upload 2 images:
1. A pattern (e.g., jungle print).
2. A room (e.g., bathroom/shower).
**Prompt**: "Show the jungle print in my shower as a background"

**Will our implementation work?**
- **Yes.** Both OpenAI Responses API and Gemini 3.1 Flash Image Preview are natively multimodal. They receive the images and the text together. They will visually identify which image is the "jungle print" and which is the "shower", and perform the composition automatically.

**API & UX Improvements for this Use Case:**
- **API Documentation (Swagger/OpenAPI)**: Since this MUST work via API for the first customer, the Swagger documentation must be crystal clear. It must explain that users can rely on the model's visual understanding (like the prompt above) OR explicitly reference the images by upload order (e.g., "Use image1 as the pattern and apply it to the walls of image2") for maximum determinism.
- **Preventing AI Hallucination in Chat**: In the routed chat path, the `mediamaker` prompt enhancer (which is a text model) *cannot see the actual images*, it only sees the filenames/extracted text. Therefore, we must NOT force the text model to guess which image is which. We will simplify the planned `mediamaker` JSON contract to avoid hallucinated roles.

## Correct Target Architecture

### Direct API path
1. User uploads `image1` and optionally `image2`
2. `MediaController::generateFromImages()` validates request
3. `MediaGenerationService::generateFromImages()` resolves model and passes `$options['images']`
4. provider chooses its pic2pic implementation

This path is mostly in place. **Crucial Next Step**: Ensure the OpenAPI annotations in `MediaController.php` are highly descriptive for the first customer, explaining how to prompt with 2 images, and run `make -C frontend generate-schemas` so the Swagger UI reflects this perfectly.

### Routed Synaplan chat path
1. user sends text plus 1 or 2 image attachments
2. classifier must distinguish:
   - analysis intent -> `analyzefile`
   - generation/edit intent -> `mediamaker`
3. sorter enriches routing metadata
4. `MediaPromptExtractor` builds a precise provider-ready prompt
5. `MediaGenerationHandler` collects attached image paths and forwards them as `$options['images']`
6. provider generates final image

This path is the critical missing piece.

## Prompt Upgrade Plan

### Prompt topics that must be improved
- `tools:sort`
- `mediamaker`
- `tools:mediamaker_audio_extract`

### English seed, language-aware behavior
Keep one English system prompt per topic.

Each prompt must clearly say:
- understand any supported user language
- answer or extract in the user's language
- keep JSON keys and routing values in the fixed system format
- do not translate image-edit instructions unless needed for clarity

### Improved `tools:sort` behavior
The sorter prompt must explicitly classify image attachments with text.

Rules:
- if the user asks to describe, read, explain, OCR, summarize, inspect, or extract from an image -> `general` (or `docsummary`)
- if the user asks to create, edit, combine, restyle, replace background, insert object, move object, merge two images, or use the attached image as a reference -> `mediamaker`
- if there is no user text and only an image -> default to `general`
- keep `BMEDIA = "image"` for pic2pic requests

Recommended JSON extension for sorting:
```json
{
  "BTOPIC": "mediamaker",
  "BLANG": "en",
  "BWEBSEARCH": 0,
  "BMEDIA": "image",
  "BINPUTMODE": "text_only|reference_images"
}
```

`BINPUTMODE` is the missing signal that makes routed pic2pic deterministic.

### Improved `mediamaker` behavior
The media prompt should stop returning loose plain text for image editing/composition.

Recommended JSON contract:
```json
{
  "BTEXT": "final provider-ready prompt",
  "BMEDIA": "image|video|audio",
  "BMODE": "text2media|pic2pic",
  "BREFERENCE_COUNT": 0
}
```

Rules:
- preserve the user's language
- preserve concrete instructions exactly
- do not invent new objects or styles
- **CRITICAL**: The text AI cannot see the images. Do NOT attempt to describe the images or assign them roles. Just enhance the user's instruction clearly so the downstream multimodal image generator can execute it.
- for audio, return only literal spoken text
- for video, never include duration inside `BTEXT`

### Removing `analyzefile`
The `analyzefile` prompt is actually unused by the system (the legacy `FileAnalysisHandler` hardcodes its own prompt, and `ChatHandler` natively supports vision, documents, and audio transcripts).
We will remove `analyzefile` from `PromptCatalog` entirely to simplify routing.

## Step-by-Step Implementation Order

### Step 1: Fix routing before touching providers again (✅ COMPLETED)
Update `MessageClassifier` to stop forcing image attachments to `analyzefile`.

Target behavior:
- All image attachments go through `MessageSorter`.
- `MessageSorter` routes to `mediamaker` (for edits) or `general` (for vision analysis via `ChatHandler`).

Tests added and passed.

### Step 2: Add `PIC2PIC` Default Configuration (✅ COMPLETED)
Update `MediaGenerationService` and database seeds to support a dedicated `PIC2PIC` default model.

Target behavior:
- `MediaGenerationService::generateFromImages()` requests `pic2pic` capability instead of `image` when resolving the model.
- `BCONFIG.sql` includes `(57,0,'DEFAULTMODEL','PIC2PIC','190')` (Nano Banana 2).
- `synaplan-platform/scripts/pic2pic-models-update.sql` inserts this config for the live DB.

### Step 3: Extend sorting metadata
Teach `MessageSorter` to parse and return `BINPUTMODE`.

Target behavior:
- `reference_images` when image attachments are meant as inputs for generation
- `text_only` for classic text-to-image

Add unit tests for parsing and fallback behavior.

### Step 4: Strengthen prompt extraction
Upgrade `mediamaker` prompt to the JSON contract above and adapt `MediaPromptExtractor`.

Target behavior:
- reliable extraction of:
  - prompt text
  - media type
  - mode
  - reference count
  - reference roles

Add unit tests for:
- one-image edit
- two-image composition
- multilingual prompts
- plain-text fallback

### Step 5: Wire routed chat flow to providers
Update `MediaGenerationHandler` so it can collect attached image paths and pass them as `$options['images']`.

Target behavior:
- routed pic2pic uses the same provider plumbing as the direct API path
- classic text-to-image remains unchanged

Add handler tests before changing provider code again.

### Step 6: Tighten the English seed prompts
Refine `PromptCatalog::all()` for the remaining prompt topics so the English prompts are explicitly language-aware. Remove `analyzefile`.

Run:
```bash
docker compose exec -T backend php bin/console app:prompt:seed
```

Add tests so this language-aware contract cannot drift.

### Step 7: Frontend integration & API Docs
For the API customer and internal UX:
- **Regenerate OpenAPI schemas**: `make -C frontend generate-schemas` (Crucial for the Swagger docs)
- upload UI for 1-2 images
- `FormData` request path
- model capability label for `pic2pic`

## Automatic Test Plan

### New unit tests to add
- `MessageClassifierTest`
  - image + "describe this" -> goes to sorter (no longer forced)
  - image + "put object from image 1 into image 2" -> goes to sorter
  - image without text -> goes to sorter
- `MessageSorterTest`
  - parses `BINPUTMODE`
  - preserves current behavior for `BMEDIA` and `BDURATION`
- `MediaPromptExtractorTest`
  - parses new JSON contract
  - multilingual extraction examples in the 10 supported languages
- `MediaGenerationServiceTest`
  - verifies `PIC2PIC` capability fallback resolves correctly
- `MediaGenerationHandlerTest`
  - passes attached image paths into `AiFacade::generateImage()`
  - keeps text-only flow unchanged
- `PromptCatalogTest` or equivalent
  - required topics exist in English
  - required prompts explicitly instruct the model to use the user's language

### Existing targeted tests already relevant
- `backend/tests/Unit/Service/MediaGenerationServiceTest.php`
- `backend/tests/Unit/Controller/MediaControllerTest.php`
- `backend/tests/Unit/MessageSorterTest.php`
- `backend/tests/Unit/MediaPromptExtractorTest.php`
- `backend/tests/Unit/Model/ModelCatalogTest.php`

### Local execution order
Run small checks first:
```bash
make -C backend lint
make -C backend phpstan
make -C backend test -- --filter MessageClassifierTest
make -C backend test -- --filter MessageSorterTest
make -C backend test -- --filter MediaPromptExtractorTest
make -C backend test -- --filter MediaGenerationServiceTest
make -C backend test -- --filter MediaControllerTest
```

Then run the normal backend gate:
```bash
make -C backend lint
make -C backend phpstan
make -C backend test
```

For frontend work later:
```bash
make -C frontend generate-schemas
make -C frontend lint
docker compose exec -T frontend npm run check:types
make -C frontend test
```

## Database / Deployment Notes

### Open source / fresh install
- models come from `ModelCatalog` via fixtures
- prompts come from `PromptCatalog` via `app:prompt:seed`
- prompts are seeded in English, but must be written to operate on the user's language

### Live platform
Already planned:
- `synaplan-platform/scripts/pic2pic-models-update.sql`

Still missing and required later:
- an idempotent prompt update SQL patch for `BPROMPTS`

Reason:
- model capabilities alone are not enough
- live systems also need the improved sorter and mediamaker prompts

### No-downtime rollout order
1. deploy backend code
2. run model SQL patch
3. run prompt SQL patch
4. verify one text-to-image request
5. verify one 2-image pic2pic request

## Short Conclusion
The provider work is a good start, but the main production blocker is not the API clients anymore.

The real blocker is this:
- Synaplan routing still treats uploaded images mainly as analysis inputs
- the routed media flow still does not carry reference images through to generation
- the English seed prompts are not yet explicit enough about following the user's language

That is the highest-value next fix.
