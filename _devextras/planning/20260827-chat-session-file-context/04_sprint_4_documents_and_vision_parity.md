# Sprint 4 — Documents & Vision Parity

Branch: `feat/file-context-doc-vision-parity`
Answers: *"Also when a Word Doc is generated, etc."* — documents mostly work
today (#1042/#1382); this sprint consolidates them onto the shared catalog and
closes the remaining file-context gap in plain chat: vision questions about
generated images.

## Part A — `DocumentImageCatalog` onto the shared catalog (refactor, no behavior change)

`DocumentImageCatalog` (`backend/src/Service/File/DocumentImageCatalog.php`)
keeps its public API (`build()`, `renderPromptBlock()`, `attachments()`,
`absolutePath()`, markers) — `DocumentImageReferenceResolver` and the
officemaker path depend on it. Internally it delegates thread resolution to
`ConversationFileCatalog` (S1) instead of its private
`threadImages()`/`filesForPaths()`:

- one resolution logic, one legacy-path fallback, one security check;
- the document path silently gains the legacy-BFILEPATH fallback the shared
  catalog has (old generated images without a `BFILES` row become embeddable);
- `DocumentImage` construction maps 1:1 from `ConversationFile`
  (marker scheme was aligned in S1 for exactly this step).

Gate for this part: the existing `DocumentImageCatalog`/officemaker tests pass
**unchanged** (behavioral no-op except the legacy fallback, which gets its own
new test).

## Part B — document follow-up hardening

Verified working today (keep, cover with regression tests where missing):

- fast-path defers via `__FILE_GENERATED__:` guards (`MessageClassifier`);
- `ChatHandler` re-injects prior file text ("Current content of the file you
  previously generated", `getAllFilesText()` path);
- `officemaker` prompt has the explicit "Editing a document from earlier"
  section (`PromptCatalog::officeMakerPrompt`).

Hardening items:

1. The re-injection reads file text from thread messages — confirm it covers
   files attached via `BMESSAGE_FILE_ATTACHMENTS` **and** the legacy channel;
   route the lookup through the shared catalog if a gap shows up.
2. Acceptance case B (master plan) as an explicit integration test: generate
   docx → edit → assert the second `officemaker` turn receives the prior
   content block.

## Part C — generated images visible to vision chat (flag-gated)

Use case C: "create an image of a cat" → "what breed does the cat look like?"
Today `ChatHandler::buildStreamingMessages()` (~1363–1369) builds multimodal
content **only for `user` turns** (`extractImageDataUrls($msg)`), so the model
literally cannot see its own generated image and answers from the text prompt.

Change (OFF by default):

- BCONFIG `FILE_CONTEXT.VISION_INCLUDE_GENERATED` (bootstrap-only default
  `0`; admin-togglable like other feature groups).
- When enabled and the selected chat model is vision-capable (existing
  capability check used for user-turn images — same predicate, no new model
  metadata): assistant turns that produced an image contribute it as image
  content, resolved via the shared catalog.
- Budget: max 2 generated images per request, newest first; skip silently
  beyond the cap. Base64 payload cost is why the flag defaults OFF.
- Applies to `handleStream()` and `handle()` (channel parity).

## Steps

1. Part A refactor + legacy-fallback test.
2. Part B regression/integration tests; fix gaps only if the tests expose them.
3. Part C: BCONFIG flag + seeder, `ChatHandler` assistant-turn image inclusion
   behind flag + capability check + cap.
4. Admin UI exposure of the flag follows the existing config-group pattern
   (backend only here; any frontend admin toggle text lands with S5 i18n).

## Tests (sprint gate)

- `DocumentImageCatalogTest` — unchanged assertions green after refactor;
  new: legacy-path generated image (no BFILES row) becomes offerable.
- Integration: docx generate → edit keeps prior content (acceptance case B).
- `ChatHandlerTest` — flag off: no assistant images (byte-identical request to
  today); flag on + vision model: newest generated image included, cap
  enforced; flag on + non-vision model: text-only (no provider error).
- Full unfiltered gate.

## Explicitly out of scope

- Editing documents that the *user* uploaded (officemaker currently edits its
  own generated docs; upload-edit is a separate feature with format-fidelity
  questions).
- RAG/`user_documents` changes — retrieval-based old-file access belongs to
  the conversation-continuity epic (`20260827-conversation-continuity/`).
- Frontend (S5).
