# Sprint 1 — Conversation File Catalog

Branch: `feat/conversation-file-catalog`
Answers: *"All chats must keep their session files handy"* — one service that
knows every file of a thread, regardless of how it entered the conversation.

## Why a new service

Three partial resolvers exist today and none covers the whole picture:

| Resolver | Sees | Blind to |
| -------- | ---- | -------- |
| `MediaGenerationHandler::collectAttachedImagePaths()` | current message attachments + legacy path + caller options | everything from earlier turns |
| `DocumentImageCatalog::threadImages()` | thread attachments + generated images via `findImagesByMessageIds()` | non-image files; only wired into officemaker |
| `ChatHandler::getAllFilesText()` | extracted text of thread files | binary/pixel access, origin metadata |

`ConversationFileCatalog` becomes the single source of truth; later sprints
consume it instead of growing a fourth ad-hoc resolver.

## Deliverables

### Value object: `ConversationFile` (`backend/src/Service/File/ConversationFile.php`)

| Field | Type | Notes |
| ----- | ---- | ----- |
| `fileId` | `?int` | `BFILES.BID`; null for legacy-path-only entries |
| `relativePath` | `string` | upload-dir-relative (normalized like `DocumentImageCatalog::normalizeRelativePath()`) |
| `absolutePath` | `string` | realpath-validated inside the upload root (reuse the escape check from `DocumentImageCatalog::absolutePath()`) |
| `displayName` | `string` | sanitized original name (single-line safe) |
| `category` | `string` | `image` \| `document` \| `audio` \| `video` \| `other` (by extension) |
| `origin` | `string` | `attached` (current msg) \| `uploaded` (earlier turn, user) \| `generated` (assistant) |
| `messageId` | `?int` | originating message |
| `direction` | `string` | `IN` / `OUT` of the originating message |

`final readonly` with a `marker(): string` helper (`file:{id}` /
`attached:{n}`) compatible with the existing `DocumentImage` marker scheme so
the S4 refactor is mechanical.

### Service: `ConversationFileCatalog` (`backend/src/Service/File/ConversationFileCatalog.php`)

`final readonly`, constructor DI (`FileRepository`, upload dir).

```php
/** @param array<int, Message|array{role: string, content: string}> $thread */
public function build(Message $message, array $thread = [], array $extraPaths = []): array // list<ConversationFile>
public function latestImage(array $catalog): ?ConversationFile
public function imagesOnly(array $catalog): array
public function renderInventoryBlock(array $catalog): string   // compact prompt block, S2/S3 consumers
```

Resolution order inside `build()` (mirrors `DocumentImageCatalog::build()`,
newest-first within the thread group, de-duplicated by file id and path):

1. **Current attachments** — `$message->getFiles()` (origin `attached`).
2. **Caller-supplied paths** — multitask upstream nodes (`$extraPaths`,
   normalized; origin `generated`).
3. **Thread files**:
   - attachments carried by thread `Message` entities
     (`BMESSAGE_FILE_ATTACHMENTS`);
   - generated files via a new `FileRepository::findFilesByMessageIds()`
     (generalization of `findImagesByMessageIds()` — all extensions, same
     `BMESSAGEID` + `userId` + limit contract);
   - **legacy fallback**: thread OUT messages whose `getFilePath()` is set but
     have no `BFILES` row (old installs, pre-`GeneratedFileRegistrar` data) —
     synthesized `ConversationFile` with `fileId = null`.

Caps: `MAX_FILES = 12`, thread lookup limit 30 (same discipline as
`DocumentImageCatalog::MAX_IMAGES/THREAD_LOOKUP_LIMIT`; images are what S3
needs, so `imagesOnly()` applies the cap per category, never dropping the
newest image in favour of older documents).

Security invariants (copied, not re-derived):

- every `absolutePath` realpath-validated under the upload root;
- user scoping: repository lookups filter `userId = message.userId`;
- widget/guest sessions: same scoping via the session's processing user —
  no cross-user leakage possible by construction.

### Repository: `FileRepository::findFilesByMessageIds()`

Same shape as `findImagesByMessageIds()` (`backend/src/Repository/FileRepository.php`
~238) without the extension filter; keep the existing method delegating to the
new one with an image filter so #1382 callers are untouched.

## Steps

1. `ConversationFile` value object + category/extension mapping.
2. `FileRepository::findFilesByMessageIds()`; re-base `findImagesByMessageIds()`
   on it.
3. `ConversationFileCatalog::build()` with the three-source resolution and the
   legacy fallback; `latestImage()` / `imagesOnly()` / `renderInventoryBlock()`.
4. Service wiring (autowire; no config).

**Not in this sprint:** any caller change. `DocumentImageCatalog`,
`MediaGenerationHandler`, `MessageSorter` remain untouched — this PR is pure
additive infrastructure and must be a no-op in production behavior.

## Tests (sprint gate)

- `ConversationFileCatalogTest` — fixture thread with: current attachment,
  earlier user upload, generated image (BFILES `source=generated` +
  `BMESSAGEID`), generated docx, legacy-path-only OUT message, deleted file on
  disk, path-escape attempt (`../../etc/passwd` in `BFILEPATH`). Assert order,
  origins, categories, de-dup, caps, and that escape/missing entries are
  dropped.
- `FileRepositoryTest` — `findFilesByMessageIds()` scoping (foreign `userId`
  rows excluded), limit, and `findImagesByMessageIds()` equivalence.
- `renderInventoryBlock()` snapshot-style assertion (single-line entries, no
  quotes/control chars — same sanitizing as `DocumentImageCatalog::displayName()`).
- Full unfiltered gate.

## Explicitly out of scope (later sprints)

Sorter/fast-path integration (S2), `MediaGenerationHandler` consumption (S3),
`DocumentImageCatalog` refactor and vision history (S4), any frontend (S5).
