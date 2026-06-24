# Feature 2 — File Management World ("one home for every file")

**Release:** 4.0 · **Priority:** P0 · **Status:** Planned
**Related:** [`01_async-media-jobs.md`](./01_async-media-jobs.md) (generated media must
land here), [File Storage Migration](../file-storage-migration.md),
[Nextcloud integration](../nextcloud-integration/README.md)

> Goal: replace today's thin "uploads list" with a **fast, beautiful, complete
> file manager** that is the single home for *every* file in a user's Synaplan
> world — uploads, chat attachments, email/Synamail pushes, Nextcloud/OpenCloud
> syncs, and **all AI-generated media** (images, video, audio, calendar/ICS,
> documents). It must make two things instantly obvious for every file:
> **(1) is it vectorized?** and **(2) which knowledge group (folder) is it in?** —
> and let users find, filter, preview, re-download, re-vectorize, move, and share
> anything in seconds.

---

## 1. Why (the problem, grounded in the current code)

A full read-only map was done ([file-world map](8f5a187b-76c0-484c-8710-3cf08c5bd251)).
The current world (`FilesView.vue`, `FileController`, `File`/`BFILES`,
`MediaGenerationHandler`, `RagDocument`/`BRAG`) has five concrete gaps:

| # | Gap | Evidence |
|---|---|---|
| **G1** | **Generated media is mostly invisible.** AI images/video/audio/TTS are written to disk and referenced from `BMESSAGES.BFILEPATH` but **no `BFILES` row** is created — so they never appear in the file manager. Users can only re-find them by scrolling chat. | `MediaGenerationHandler::downloadMedia/saveDataUrlAsFile`; only generated **documents** create a `BFILES` row (`status=generated`). |
| **G2** | **Vectorized-or-not is not shown.** The list API returns `status`, but `FilesView.vue` never displays it; authoritative vectorization (`isVectorized`, chunk count) lives behind `GET /files/{id}/group-key` and is only used in chat pickers. | `FilesView.vue` columns = name/folder/size/date only. |
| **G3** | **File group is weakly surfaced.** `BGROUPKEY` shows as an optional badge; no group column, no group filter in the "All files" table; `DEFAULT` hidden; legacy files need lazy backfill. | `FileController::getFileGroups/resolveVectorGroupKeys`. |
| **G4** | **No source/origin.** `BFILES` has **no** source column. Web upload, chat attach, **email/Synamail** (`POST /files/upload` from the add-in), WhatsApp, widget, Nextcloud/OpenCloud, and generated are indistinguishable. | `File.php` has no `source`; Synamail uses `/api/v1/files/upload`. |
| **G5** | **Dual file model + broken share.** Files live in `BFILES` *and* as `BMESSAGES.BFILEPATH`; `FileController` share routes look up by **message id** while the manager passes **file id** → `ShareModal` is imported but unwired/broken. | `FileController` share methods use `MessageRepository::findUserFileMessage`. |

Net: the file world is "simplified and missing a solid management + filter GUI"
(user's words). 4.0 fixes the data model, makes `BFILES` the single registry,
and ships a top-notch GUI.

---

## 2. Design principles

1. **One registry.** Every user-visible file is a `BFILES` row — uploads,
   attachments, **and generated media** — linked to its originating message when
   there is one. `BMESSAGES.BFILEPATH` remains for serving/back-compat but is no
   longer the *only* record of a file.
2. **Truthful status, always visible.** Vectorization state is authoritative and
   shown on every row: `Vectorized · <group>` / `Not vectorized` / `Processing` /
   `Not applicable` (media) / `Failed`.
3. **Know where it came from.** Every file has a `source`; the GUI filters by it
   and badges it (upload / Outlook / Nextcloud / OpenCloud / WhatsApp / widget /
   generated). Externally pushed files are additionally flagged **`incoming`**
   and carry their **original name**, so the user instantly recognises what just
   arrived and from where.
4. **Fast by default.** Server-side filter/sort/paginate + indexes + thumbnails +
   virtualized list. Sub-200ms interactions on thousands of files.
5. **Additive & safe.** Additive migrations; lazy backfill (fix-on-read) for
   legacy rows; no destructive schema change.
6. **Reuse, don't reinvent.** Build on `FileController`, `VectorStorageFacade`,
   `GeneratedFileRegistrar`, the `useNotification` toaster, and the design tokens.

---

## 3. Data model changes (additive)

### 3.1 `BFILES` new columns (Doctrine migration)

| Column | Type | Purpose |
|---|---|---|
| `BSOURCE` | varchar(32) | Origin: `web_upload`, `chat_attachment`, `outlook` (Synamail add-in), `nextcloud`, `opencloud`, `whatsapp`, `widget`, `api`, `generated`. |
| `BORIGINKIND` | varchar(24) null | For `generated`: `image`/`video`/`audio`/`calendar`/`document`; else null. |
| `BORIGINALNAME` | varchar(255) null | The file's **original name at the source** (e.g. the Outlook attachment name, the Nextcloud/OpenCloud path/basename) — preserved even if the stored name is normalised/timestamped. Shown to the user; falls back to `BFILENAME`. |
| `BINCOMING` | tinyint default 0 | `1` while the file is a freshly-arrived external push **awaiting the user / vectorization** (the "incoming inbox"). Cleared once the user has triaged it (vectorized, assigned a group, or dismissed). |
| `BSTAGEPATH` | varchar(255) null | Relative path in the **separate incoming/staging area** where external pushes land before promotion to the canonical user tree (see §3.3). Null once promoted. |
| `BMESSAGEID` | int null | FK-ish link to the originating `BMESSAGES.BID` (generated media + chat attachments). Enables "jump to chat" and reuses async-media linkage. |
| `BVECTORSTATE` | varchar(16) | Authoritative vectorization state: `none`/`pending`/`vectorized`/`failed`/`not_applicable`. Decouples from `BSTATUS` (which mixes upload + extraction lifecycle). |
| `BCHUNKCOUNT` | int default 0 | Cached chunk count (kept in sync by `VectorizationService`) so the list needs no per-row Qdrant call. |
| `BPROVIDER` | varchar(48) null | Generating provider/model (for generated media). |
| `BTHUMBPATH` | varchar(255) null | Optional generated thumbnail (images/video poster) for fast grids. |

- `BVECTORSTATE` is **derived/backfilled** from `BSTATUS` + `VectorStorageFacade::getFileChunkInfo()` on first read (fix-on-read), then maintained going forward.
- `BSOURCE`/`BORIGINKIND` backfilled by heuristic for legacy rows (`status=generated` → `generated`; else `web_upload`), refined where the message link exists.
- Indexes: `(BUSERID, BSOURCE)`, `(BUSERID, BGROUPKEY)`, `(BUSERID, BVECTORSTATE)`, `(BUSERID, BINCOMING)`, `(BUSERID, BCREATEDAT)` to keep filter/sort fast.

### 3.2 Generated media → always a `BFILES` row

- Generalise `GeneratedFileRegistrar` so **every** generated artefact (image,
  video, audio, TTS, calendar) registers a `BFILES` row with
  `BSOURCE=generated`, `BORIGINKIND=<kind>`, `BMESSAGEID`, `BPROVIDER`,
  `BVECTORSTATE=not_applicable` (media) and a thumbnail where cheap.
- **Integration with Feature 1:** the `MediaJob` finalize step
  (`markCompleted`) is the natural single call site — when a render completes, it
  both attaches to the message *and* registers the `BFILES` row. One code path
  for sync and async.
- **Legacy backfill:** a one-time idempotent command (`app:files:backfill-media`)
  walks `BMESSAGES` rows with a media `BFILEPATH` and no `BFILES` row, creating
  the missing rows (disk file already exists). Plus fix-on-read in
  `ChatController::getMessages` so old chats heal as they're opened.

### 3.3 Incoming files for vectorization (the "Incoming inbox")

External integrations push documents *for the user to use as knowledge* — the
**Outlook add-in (Synamail)** (`POST /api/v1/files/upload`, already live) and the
planned **Nextcloud / OpenCloud** pushes. These must arrive clearly labelled and
be instantly workable, even though they are **stored apart** from hand-curated
uploads until the user triages them.

**Rules:**

- Such a file is created with `BINCOMING=1`, the correct `BSOURCE`
  (`outlook` / `nextcloud` / `opencloud`), and `BORIGINALNAME` set to the
  source's original name (Outlook attachment name, Nextcloud/OpenCloud basename)
  whenever the integration provides it.
- It lands in a **separate staging area** — `var/incoming/{source}/{user tree}/…`
  recorded in `BSTAGEPATH` — not the canonical `var/uploads/…` tree. This keeps
  the curated library clean and lets us treat unreviewed pushes differently
  (e.g. retention, quota display) without polluting "my files".
- Vectorization still runs (these arrive *for* RAG): `BVECTORSTATE` moves
  `pending → vectorized`/`failed` exactly as for uploads. "Incoming" is an
  **orthogonal triage flag**, not a vector state — a file can be
  `incoming + vectorized` (ready to use, not yet filed).
- **Promotion / triage:** when the user accepts an incoming file (assigns a
  group, or clicks "Keep"), it is promoted — moved from `BSTAGEPATH` into the
  canonical tree, `BINCOMING=0`, `BSTAGEPATH=null`. Dismiss → delete (vectors +
  staging file). Bulk accept/dismiss supported.
- **Work with it immediately:** even while `incoming`, the file is fully usable —
  previewable, downloadable, and selectable in chat (`Use in chat`) and the
  `@`-mention palette — served from the staging path via the existing serve
  controllers (path-agnostic). "Stored elsewhere" must never mean "harder to
  use".

**Source mapping note:** the Outlook add-in currently uploads via the same
`/files/upload` endpoint as the web. To distinguish it, the add-in sends an
explicit `source=outlook` (and optional `original_name`) form field; the endpoint
records `BSOURCE`/`BORIGINALNAME`/`BINCOMING` accordingly. Web uploads omit it →
`web_upload`, not incoming. (Small additive change in `Synamail/src/shared/synaplan-client.ts`
`fileUpload()` + `FileController::uploadFiles()`.)

---

## 4. The new GUI

### 4.1 Layout

```
┌─ Files ──────────────────── [ Incoming ④ | Browse | Generated | Search ] ─┐
│ Storage ▓▓▓▓░░ 3.2/10 GB        🔍 search…   [⊞ grid] [≣ list]            │
│                                                                            │
│ Filters:  Source ▾   Group ▾   Type ▾   Vectorized ▾   Date ▾   ✕clear     │
│                                                                            │
│ ┌─ Groups (folders) ───────────────────────────────────────────────────┐ │
│ │  📁 Contracts (12)   📁 Brand (8)   📁 Outlook (23)   + New group      │ │
│ └───────────────────────────────────────────────────────────────────────┘ │
│ ┌─ Files ───────────────────────────────────────────────────────────────┐ │
│ │ ☐  Name              Source     Group      Vectorized   Size   Date    │ │
│ │ ☐ 📄 contract.pdf    ⬆ upload   Contracts  ✅ ·12 chk   1.2MB  Jun 3   │ │
│ │ ☐ 🖼 sunset.png      ✨ gen      —          — n/a        540KB  Jun22   │ │
│ │ ☐ 🎬 waves.mp4       ✨ gen      —          — n/a        8.1MB  Jun22   │ │
│ │ ☐ 📄 specs.docx      ☁ opencl.  Specs      ⚠ failed      90KB  Jun19   │ │
│ └───────────────────────────────────────────────────────────────────────┘ │
│ row actions (hover / bulk): Preview · Download · Re-vectorize ·             │
│                              Move group · Use in chat · Share · Delete      │
└──────────────────────────────────────────────────────────────────────────┘

  Incoming inbox tab (BINCOMING=1) — separate, triage-focused:
┌─ Incoming (4) — files pushed in for your knowledge base ───────────────────┐
│  [✓ Keep all] [+ Assign group ▾] [🗑 Dismiss]            sort: newest ▾     │
│ ┌───────────────────────────────────────────────────────────────────────┐ │
│ │ ☐ 📧 Q3-Report.pdf      🟦 Outlook   "Q3 Report.pdf"   ✅ vectorized    │ │
│ │      from Synamail · 2h ago                     [Keep] [Group▾] [Open]  │ │
│ │ ☐ 📄 brief.docx         ☁ Nextcloud  "/Shared/brief.docx" ⏳ pending    │ │
│ │ ☐ 📄 notes.md           ☁ OpenCloud  "notes.md"        ✅ vectorized    │ │
│ │ ☐ 📄 invoice-44.pdf     🟦 Outlook   "invoice 44.pdf"  ⚠ failed · retry │ │
│ └───────────────────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────────────┘
```

### 4.2 Vectorized indicator (G2) — explicit, per row

A single, legible status pill:

| Pill | Meaning |
|---|---|
| ✅ **Vectorized · `<group>` · N chunks** | In RAG; clickable → filters to that group. |
| ⏳ **Processing** | Extracting/embedding in progress (live-updates). |
| ⬜ **Not vectorized** | Stored only; one-click **Vectorize** action. |
| 🚫 **Not applicable** | Media (image/video/audio) — not a RAG doc. |
| ⚠ **Failed** | Extraction/embedding failed; **Retry** + reason tooltip. |

Driven by `BVECTORSTATE` + `BCHUNKCOUNT` (no per-row network call).

### 4.3 File group (G3)

- Dedicated **Group** column + a **Group** filter (multi-select), available in
  the root "All files" table, not just inside a folder.
- A vectorized file's group chip is the RAG retrieval group — clicking it filters
  and explains "RAG searches in this group will include this file."
- `DEFAULT`/ungrouped shown explicitly as "No group" (not hidden) with a quick
  "Assign group" action; legacy backfill stays.

### 4.4 Source (G4)

- **Source** column with an icon + label, and a **Source** filter:
  ⬆ Upload · 💬 Chat · 🟦 Outlook (Synamail) · ☁ Nextcloud · ☁ OpenCloud ·
  🟢 WhatsApp · 🧩 Widget · 🔌 API · ✨ Generated.
- Each row shows the **original name** (`BORIGINALNAME`) as the primary label when
  it differs from the stored/normalised name (with the stored name as a tooltip),
  so an Outlook attachment reads as `"Q3 Report.pdf"`, not `Q3-Report_173.pdf`.
- The "Generated" tab is just `source=generated` pre-applied (see 4.6); the
  "Incoming" tab is just `BINCOMING=1` pre-applied (see 4.5).

### 4.5 Incoming inbox (the triage surface)

- **Incoming** tab = `BINCOMING=1`, with a **count badge** in the tab bar so the
  user notices when integrations have pushed new files.
- Each row leads with the **source badge** (Outlook / Nextcloud / OpenCloud), the
  **original name**, arrival time, and the live **vectorized** pill (these arrive
  *for* RAG, so most are already `vectorized` or `pending`).
- Triage actions, per-row and bulk: **Keep** (promote, clear incoming),
  **Assign group ▾** (promote into a chosen group in one step), **Open** /
  **Use in chat**, **Retry** (if vectorization failed), **Dismiss** (delete).
- Promotion moves the file from `BSTAGEPATH` to the canonical tree and flips
  `BINCOMING=0` (see §3.3). Until then it's still fully usable everywhere.
- A subtle dashboard/sidebar hint ("4 files arrived from Outlook & Nextcloud")
  links here — the user is never surprised by silently-ingested knowledge.

### 4.6 Generated-media gallery (G1) — find & re-download

- A **Generated** tab = `source=generated`, defaulting to a **grid/gallery** with
  real thumbnails/posters and inline preview (lightbox for images, player for
  video/audio, ICS preview/download for calendar).
- Each tile: kind icon, prompt (from `BPROVIDER`/message), date, **Download**,
  **Open in chat** (via `BMESSAGEID`), **Delete**.
- This is where the async-media results live permanently — closes the loop with
  Feature 1 ("saved for the user to find and download again").

### 4.7 Speed & polish

- Server-side filter/sort/paginate (extend `FileController::listFiles` with
  `source`, `vector_state`, `sort`); virtualized rows for large sets.
- Thumbnails served cheaply (`BTHUMBPATH`); lazy-loaded; skeletons.
- Debounced keyword search (name + extracted text) with a clear hand-off to the
  semantic **Search** tab ("Search inside files →").
- Bulk select → download (zip), move group, re-vectorize, delete.
- Grid/List toggle persisted per user; keyboard nav; dark-mode safe tokens.

### 4.8 Fix the broken share (G5)

- Repoint `FileController` share routes to operate on **file id** (current
  `ShareModal` contract), backed by `BFILES` + share meta; wire `ShareModal` into
  row/bulk actions. Add tests (the current message-id lookup is the bug).

---

## 5. API changes (additive)

| Endpoint | Change |
|---|---|
| `GET /api/v1/files` | Add filters `source`, `vector_state`, `origin_kind`, `incoming`, and `sort`; include `source`, `original_name`, `incoming`, `vector_state`, `chunk_count`, `group_key`, `thumb_url`, `message_id`, `provider` in each row (so the list renders fully with no follow-up calls). |
| `GET /api/v1/files/facets` | **New:** counts per source / group / type / vector_state, **plus the incoming count** for the tab badge (fast, indexed). |
| `POST /api/v1/files/upload` | Accept optional `source` + `original_name` form fields so external integrations (Outlook/Nextcloud/OpenCloud) mark files `incoming` with the right origin; default → `web_upload`, not incoming. |
| `POST /api/v1/files/{id}/accept` | **New:** triage an incoming file → promote from `BSTAGEPATH` to the canonical tree, optional `group_key`, clear `BINCOMING`. (Bulk: `POST /api/v1/files/accept` with ids.) |
| `POST /api/v1/files/{id}/vectorize` | Re-expose the one-click vectorize/re-vectorize (already partly there as `process`/`re-vectorize`). |
| `GET /api/v1/files/{id}/thumb` | **New:** thumbnail/poster (or static via `BTHUMBPATH`). |
| share routes | Repointed to file-id semantics (G5). |

All annotated with OpenAPI; regenerate frontend schemas (`make -C frontend generate-schemas`).

---

## 6. Sprints

- **A — Data model & registry.** Migration for new `BFILES` columns
  (incl. `BSOURCE`, `BORIGINALNAME`, `BINCOMING`, `BSTAGEPATH`) + indexes;
  generalise `GeneratedFileRegistrar`; wire generated media (sync + async via
  Feature 1 finalize) to always create a row; `BVECTORSTATE`/`BCHUNKCOUNT`
  maintained by `VectorizationService`. Backend tests. No UI yet.
- **B — Backfill & heal.** `app:files:backfill-media` (idempotent) + fix-on-read
  for legacy message-only media and missing `source`/`vector_state`. Verified on
  a copy of prod-like data.
- **C — Incoming pipeline + staging.** Staging storage (`var/incoming/…`),
  `/files/upload` `source`+`original_name` fields, `BINCOMING` lifecycle, the
  `accept` (promote) + bulk endpoints; Outlook add-in sends `source=outlook`
  (small `Synamail` client change). Serve controllers verified to serve staged
  files. Tests.
- **D — List API upgrade.** `listFiles` filters/sort (incl. `incoming`) + full
  row payload + `/facets` (incl. incoming count) + thumbnails. OpenAPI + schema
  regen. Tests.
- **E — New GUI (Browse + Incoming).** Rebuilt `FilesView`: source/group/
  vectorized columns + pills, original-name display, filter bar, group filter,
  fast search, bulk actions, grid/list; the **Incoming inbox** tab with count
  badge + triage actions (Keep/Assign group/Dismiss/Retry). i18n (en/de/es/tr).
  Component + a11y tests.
- **F — Generated gallery + previews.** Generated tab, thumbnails, lightbox/
  players, "Open in chat", download. Live status for in-flight generated media
  (subscribes to Feature 1's `media_job.update`).
- **G — Share fix + polish + perf.** Repoint share to file-id, wire `ShareModal`;
  virtualization; index/perf pass; E2E (Outlook push → appears in Incoming →
  vectorized badge → Keep into a group → find generated video → download).

---

## 7. Cross-feature synergy with Feature 1 (async media)

- The `MediaJob` finalize is the **single** place generated media is persisted →
  it registers the `BFILES` row (`source=generated`, kind, message link, thumb).
- The file manager's Generated tab + a generated row's "Processing" pill can
  subscribe to the same `media_job.update` realtime channel, so a video that's
  still rendering shows up immediately and flips to ready in place — consistent
  with the Jobs tray.
- Net: a generated video appears in **three** consistent surfaces — the chat
  card, the global Jobs tray, and the Files → Generated gallery — all backed by
  one job + one `BFILES` row.

## 8. Risks & mitigations

- **Backfill volume** (many legacy message-only media) → idempotent, batched,
  resumable command + fix-on-read so the UI is correct even before backfill ends.
- **Thumbnail cost** → generate lazily on first view; cache to `BTHUMBPATH`;
  skip for audio (waveform icon).
- **`BVECTORSTATE` drift** → single writer (`VectorizationService`); a periodic
  reconciler can recompute from `VectorStorageFacade` if needed.
- **Dual-model serving** stays working (no change to `StaticUploadController`
  pattern auth); the registry is additive.
- **Share refactor regressions** → cover the message-id→file-id change with tests
  before wiring the UI.

## 9. Definition of done

- Every uploaded *and* generated file appears in the file manager with a correct
  **source**, **group**, and **vectorized** indicator.
- Files pushed from Outlook/Nextcloud/OpenCloud appear in the **Incoming inbox**,
  labelled with their **source** and **original name**, fully usable while staged,
  and promotable into a group in one click.
- Generated images/video/audio/calendar are findable and re-downloadable in the
  Generated gallery (and openable back in their chat).
- Filter by source/group/type/vectorized state + fast search, on thousands of
  files, feels instant.
- Share works from the file manager (file-id based), with tests.
- Full gate green + E2E for the core flow.

## 10. Open questions

1. **Decided:** external pushes (Outlook/Nextcloud/OpenCloud) arrive as
   **`incoming`** files, labelled by source, with their original name, in a
   separate staging area (§3.3). **Still open:** does 4.0 also *build* the
   Nextcloud/OpenCloud connectors that do the pushing, or do we only implement the
   Synaplan-side `incoming` ingestion + GUI now and let the connectors
   (separate repos/apps) adopt the `source`+`original_name` upload contract later?
   (Outlook/Synamail already uploads today, so it can adopt the contract
   immediately; Nextcloud/OpenCloud have no inbound sync yet.)
2. Do generated media count against the storage quota? (Proposal: yes, but shown
   separately.)
3. Retention for generated media — keep forever (until user deletes) vs. auto-
   prune after N days? (Proposal: keep; user-managed.)
4. Should the Generated gallery be a tab here or also surface on the dashboard?
