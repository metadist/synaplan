# S8 — One MIME-to-extension map

| | |
| - | - |
| **V3 §7** | #8 |
| **Branch** | `fix/cleanup-v4-s8-mime-map`, from S7's branch |
| **Pull request** | `fix(files): one MIME-to-extension map` |
| **Class** | backend-only |
| **Owner decision** | **D3** — the merged map and the fallback (§2). Tick before step S8.2. |
| **Chore** | C8 |

## 1. Goal

A file gets the same extension whichever path stored it. Today two maps
answer "which extension for this MIME type", and they disagree.

## 2. Measured (2026-10-16) and D3

| | `FileHelper::getExtensionFromMimeType()` | `WhatsAppService::getExtensionFromMimeType()` (private, line 2,202) |
| - | ---------------------------------------- | -------------------------------------------------------------------- |
| Callers | `MediaGenerationService` (2), `DataUrlFixer`, `MediaGenerationHandler` (2) | WhatsApp media download (line 2,137), then checked against `ALLOWED_EXTENSIONS` |
| Parameters (`audio/ogg; codecs=opus`) | not stripped: falls to the fallback | stripped |
| Unmapped | the caller's fallback (`bin` by default, `png` / `mp4` in two callers) | `unknown`, rejected by the allow-list |
| Only here | `image/jpg`, `image/heic`, `image/heif`, `audio/mp3` | `audio/ogg`, `audio/mp4`, `audio/amr`, `audio/opus`, `audio/webm`, `video/3gpp`, PDF, Word, Excel, PowerPoint (old and OOXML), `text/plain`, `text/csv`, `text/markdown` |
| Both, same answer | `image/png`, `image/jpeg`, `image/gif`, `image/webp`, `video/mp4`, `video/webm`, `audio/mpeg`, `audio/wav` | |

No key maps to two different extensions. The maps differ in coverage and
in parameter handling.

**D3 proposed:** one map in `FileHelper`, the union of both. Parameters
are stripped first. The caller keeps its own fallback: WhatsApp passes
`unknown` and keeps its allow-list unchanged.

`symfony/mime` cannot replace the curated map: its first extension for
`audio/webm` is `weba`, and media files would be renamed.

What moves: a generated or data-URL file whose type carries parameters,
or is one of the WhatsApp-only types, gets its real extension instead of
`bin`. A WhatsApp HEIC image is mapped to `heic`, and its allow-list still
decides whether it is accepted.

## 3. Steps

| Step | Commit | What |
| ---- | ------ | ---- |
| S8.1 | `test(files): pin both MIME maps` | A table test over the union of keys, with and without parameters, that records **today's** answer of each map: green, and the rows where they disagree are visible side by side. |
| S8.2 | `fix(files): one MIME-to-extension map with parameters stripped` | `FileHelper` gets the union; the WhatsApp copy is deleted and its call passes `unknown`. The table's expectations change to the one map in the same commit: that diff is the behaviour change, quoted in the pull request. |
| C8 | `chore(planning): close V4 S8 — sprint test, status, ledgers` | Loop §7 |

## 4. Sprint test

- **Proof:** the table test green on the union. Regression tests run red
  on S7's head first (quoted in STATUS) and committed with S8.2:
  `audio/ogg; codecs=opus` → `ogg` through `FileHelper`, and a generated
  `audio/mp4` → `m4a`. Mutation check: drop the stripping and see three
  rows fail.
- **Regression:** `WhatsAppServiceTest` and the media tests green; every
  snapshot unchanged (`media_turn.json` included, unless a case used a
  changed type: then the diff is quoted). Unfiltered gates.
- **Journey:** `chat.spec.ts` media cases and `file-manage.spec.ts`;
  `whatsapp.spec.ts` in CI (test stack).

## 5. Reviewer focus

Every caller with a fallback other than `bin`, and the WhatsApp allow-list:
a newly mapped type must not slip past it.

## 6. Exit

- One MIME-to-extension map; no private copy (`rg getExtensionFromMimeType
  backend/src` shows the owner and its callers only).

## 7. Not in S8

Content sniffing; the upload allow-lists.

## 8. As delivered (2026-10-17)

One map: `FileHelper::getExtensionFromMimeType()` holds the union of both
(D3) and ignores a type's parameters; WhatsApp's private copy is gone, and
`rg getExtensionFromMimeType backend/src` shows the owner and six calls
in four classes. The branch starts from S7's head (`4da3c52c4`). Where the result
differs from the plan:

- **The callers moved before the sprint.** `MediaGenerationHandler`'s two
  calls are in `MediaUploadStore` since S5, and WhatsApp's copy sat in the
  transport `WhatsAppService` keeps since S4c. The regression test for a
  generated `audio/mp4` runs through the store's data-URL path.
- **Twenty-nine rows, not three.** Every type the map knows is tested with
  and without a parameter, so dropping the stripping fails all 28
  parameter rows and the voice-note test.
- **What changed for WhatsApp.** Media sent as `image/jpg` or `audio/mp3`
  is accepted like `image/jpeg` and `audio/mpeg`, whose extensions its
  allow-list already holds. `image/heic` and `image/heif` are still
  refused, now logged as `heic` instead of `unknown`.
- **Carried, not changed.** Matching stays case-sensitive: RFC 2045 calls
  MIME types case-insensitive, but neither map ever lowercased a type, and
  D3 does not ask for it. A provider that sends `Audio/OGG` still gets the
  caller's fallback.
