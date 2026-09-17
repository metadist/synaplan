# Sprint 5 — Frontend Affordances & Hardening

Branch: `feat/file-context-frontend`
Mobile impact: `ota-candidate` (frontend/** only; backend additions in this
sprint are `backend-only`).

The backend fix (S1–S4) is invisible when it works — this sprint makes it
visible and controllable, and closes the epic with E2E coverage and docs.

## Part A — "Editing <file>" transparency

- Backend already emits (S3): SSE progress event `editing` +
  `edit_source_file_id`/`edit_source_path` in the OUT message metadata.
- `ChatView.vue` SSE handling: render the `editing` progress state like the
  existing generation states ("Editing car-sunset.png…").
- Message rendering: an OUT message with `edit_source_file_id` shows a small
  "edited from <thumbnail/name>" reference linking to the source message —
  same visual grammar as the quote block, tokens from `style.css` only, checked
  in light, dark, AND V2 glass.
- Zod: after the backend metadata lands, `make -C frontend generate-schemas` →
  re-run `vue-tsc`; never a manual interface.

## Part B — quote-an-image attaches it as reference

Today quoting is text-only (`quotedText`/`quotedMessageId`). Extend:

- Quoting a message that carries an image adds that image visibly to the
  composer as a pinned reference chip (distinct from a fresh upload).
- Send path: include the file id in the existing `fileIds` array when the
  quoted message's file has a `BFILES` id — zero backend API change; for
  legacy-path-only messages fall back to `quotedMessageId` (backend resolves
  via the catalog).
- This gives users an **explicit** override of the "newest image" heuristic:
  quote the image you mean, then say "make this one blue".

## Part C — i18n & copy

New keys in **all four** locales (`frontend/src/i18n/{en,de,es,tr}.json`):
editing-progress label, "edited from" reference, quote-reference chip
tooltip, admin toggle description for `FILE_CONTEXT.VISION_INCLUDE_GENERATED`
(S4). Canonical, non-technical wording ("Editing your image…"), no
implementation jargon.

## Part D — E2E + docs (epic close-out)

- Playwright (`docs/E2E_TESTING.md` guidelines): generate image → "change the
  color" → assert the second OUT message carries the edit-source reference and
  a different file than a fresh-generation control (with the Test provider's
  deterministic classification, `TestProvider::detectMediaIntent` already
  emits `reference_images` for edit phrasing).
- Docs: short "Session files & follow-up edits" section in the user-facing
  docs; `docs/` architecture note pointing to `ConversationFileCatalog` as the
  single resolver (so the next feature does not grow resolver #4).
- Update `STATUS.md` measured-results table for the whole epic.

## Steps

1. SSE `editing` state + edit-source reference rendering (light/dark/V2 pass).
2. Quote-image → reference chip → `fileIds` send path.
3. i18n, all four locales in the same change.
4. Schema regeneration + `vue-tsc`.
5. Playwright flow; docs; STATUS close-out.

## Tests (sprint gate)

- Vitest: reference chip component (stub heavy deps per house rules), SSE
  `editing` state handling, quoted-image send payload includes the file id.
- `make -C frontend lint`, `npm run check:types`, `make -C frontend test`,
  plus the backend gate if any backend file was touched.
- Playwright E2E (nightly-tier, not per-commit).

## Explicitly out of scope

- Widget UI affordances (widget benefits from the backend fix automatically;
  its minimal UI stays as-is).
- A full "session files" side panel / file manager in chat — worth considering
  after the epic proves the catalog, as a separate plan.
- Mobile-app native work — this is all WebView layer (`ota-candidate`).
