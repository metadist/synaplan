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
| S8.1 | `test(files): pin both MIME maps` | A table test over the union of keys, with and without parameters, against both maps; red where they disagree (recorded in STATUS). |
| S8.2 | `fix(files): one MIME-to-extension map with parameters stripped` | `FileHelper` gets the union; the WhatsApp copy is deleted and its call passes `unknown`. |
| C8 | `chore(planning): close V4 S8 — sprint test, status, ledgers` | Loop §7 |

## 4. Sprint test

- **Proof:** the table test green on the union; regression tests red on
  S7's head: `audio/ogg; codecs=opus` → `ogg` through `FileHelper`, and a
  generated `audio/mp4` → `m4a`. Mutation check: drop the stripping and see
  three rows fail.
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
