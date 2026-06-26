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
- **The flat gallery becomes a foldered tree** — the **Generated** tab is the
  surface for the auto-foldered **"AI generated"** library (Images / Videos /
  Audio / Documents / Calendar). Full design in **§11 (AI-Generated Files:
  Auto-Foldering & Categorization)**.

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

### 4.9 UX helpers, onboarding & feedback — the human layer

This is the part that makes the file world feel *finished* instead of merely
functional. Today the manager has columns and buttons but almost no explanation;
a new user cannot tell what "vectorized" means, why a file is in "Incoming", or
what happens when they click "Move group". 4.0 ships a deliberate, consistent
help-and-feedback layer across **every** surface (`FilesView.vue`,
`FilesTabs.vue`, `FileSelectionModal.vue`, `FileMentionPalette.vue`,
`KnowledgeFolderPicker.vue`, `ShareModal.vue`). All copy is i18n and lands in
**all four locales** (`en`, `de`, `es`, `tr`) — see §4.11.

#### A. Voice & microcopy principles

1. **Plain words, not jargon.** Say "Searchable by AI" before "Vectorized";
   keep the technical term as the secondary line/tooltip. Never show a bare
   internal token (`web_upload`, `not_applicable`, `BGROUPKEY`).
2. **One sentence, one job.** Every helper answers exactly one question: *what is
   this?* / *what will this button do?* / *what just happened?*
3. **Outcome-oriented.** Toasters and tooltips describe the user's result
   ("Moved 3 files to Contracts"), not the implementation ("PATCH succeeded").
4. **Consistent vocabulary** with the chat input and the rest of the app:
   *files*, *knowledge group*, *searchable by AI*, *incoming*, *generated*.
   Define each once (§4.11 glossary) and reuse the exact term everywhere.
5. **Quiet by default, helpful on demand.** Persistent inline hints stay short;
   deeper explanation lives behind a discreet `(?)` help bubble so power users
   aren't nagged.

#### B. First-run & empty states (every surface gets one)

No surface is ever a blank rectangle. Each empty/zero state explains the purpose
and offers the primary next action.

| Surface | When empty | Message (intent) | Primary action |
|---|---|---|---|
| Browse (no files) | brand-new account | "This is your file library — every upload, chat attachment and AI-generated file lives here, ready to search and reuse." | **Upload your first file** |
| Browse (filtered to 0) | filters exclude all | "No files match these filters." | **Clear filters** |
| Incoming (empty) | nothing pushed | "Files sent in from Outlook, Nextcloud or OpenCloud will appear here for you to review before filing." | link: *Set up integrations* |
| Generated (empty) | no AI media yet | "Images, video and audio you create in chat are saved here so you can find and download them again." | link: *Start a chat* |
| Group with 0 files | empty knowledge group | "This group has no files yet. Add files to make them searchable together." | **Add files** |
| Attachment window (no recents) | first attach | "Pick a recent file or upload a new one to use in this chat." | **Upload** |

#### C. Help bubbles & tooltips (discoverable, not noisy)

A small, consistent `(?)` affordance (and native `aria-describedby` tooltips on
controls) explains each non-obvious element. Each entry below is a single i18n
key; the bubble copy is the contract.

| Element | Help bubble / tooltip copy (intent) |
|---|---|
| **"Searchable by AI" pill** | "When a file is searchable by AI, its contents are added to your knowledge base so the assistant can use it to answer you. Click to see the group it belongs to." |
| **Group / folder chip** | "Knowledge groups bundle files. When the AI searches a group, it can use every file in it." |
| **Source badge** | "Where this file came from — an upload, a chat, Outlook, Nextcloud, OpenCloud, WhatsApp, the widget, the API, or AI-generated." |
| **Incoming tab badge** | "Files other apps pushed in for your knowledge base. Review them, then keep or dismiss." |
| **Type filter** | "Show only a kind of file — documents, images, video or audio." |
| **Vectorized filter** | "Filter by whether files are searchable by AI." |
| **Re-vectorize action** | "Re-read this file and refresh what the AI knows from it. Use after editing or if it failed." |
| **Move group action** | "Move files into another knowledge group. This changes which group the AI searches them in — it does not delete anything." |
| **Storage meter** | "How much of your storage you've used. Generated media is counted but shown separately." |

#### D. Toaster catalogue (every action gives feedback)

Built on the existing `useNotification` composable (`success` / `error` /
`warning` / `info`). Rules: **every** mutating action confirms or explains;
bulk actions report a **count**; destructive actions offer **Undo** where the
backend can support a soft window, otherwise a confirm dialog precedes them;
identical rapid events are **debounced/coalesced** into one toast.

| Action | Type | Toast copy (intent) | Notes |
|---|---|---|---|
| Upload complete | success | "Uploaded {name}." / "Uploaded {n} files." | per-batch, not per-file |
| Upload failed | error | "Couldn't upload {name}. {reason}" | actionable reason |
| File too large / wrong type | warning | "{name} is too large (max {limit})." / "{type} files aren't supported." | pre-flight, before upload |
| Vectorize started | info | "Making {name} searchable by AI…" | followed by live pill |
| Vectorize done | success | "{name} is now searchable by AI." | — |
| Vectorize failed | error | "Couldn't process {name}. Retry?" | inline Retry + toast |
| Re-vectorize (bulk) | success | "Refreshed {n} files." | coalesced |
| Move to group | success | "Moved {n} files to {group}." | **Undo** |
| Assign group (from No group) | success | "Filed {n} files in {group}." | **Undo** |
| Delete | success | "Deleted {n} files." | **Undo** (soft window) |
| Incoming → Keep | success | "Kept {n} files in {group}." | promotes from staging |
| Incoming → Dismiss | success | "Dismissed {n} files." | **Undo** |
| Share link created | success | "Share link copied to clipboard." | auto-copies |
| Share revoked | info | "Sharing turned off for {name}." | — |
| Download (zip) | info | "Preparing {n} files for download…" | then browser save |
| Network/permission error | error | "Something went wrong. {reason}" | generic fallback |

#### E. Inline status explainers (in place, no clicking required)

- The **"Searchable by AI" pill** carries its own one-line meaning per state
  (searchable · processing · not yet · not applicable · failed); the **failed**
  state shows the reason on hover and a **Retry**.
- The **group chip** on a searchable file states the retrieval consequence:
  "AI searches in *{group}* include this file."
- **Incoming** rows show a short provenance line ("from Outlook · 2h ago") so the
  user immediately trusts what arrived.
- A first-visit **dismissible explainer strip** at the top of Browse ("Everything
  you upload or create lives here…") that can be re-opened from the `(?)` in the
  header — shown once, remembered per user.

#### F. Confirmations & safety

- **Destructive** (Delete, Dismiss) → confirm dialog **or** instant action with a
  time-boxed **Undo** toast (preferred for single items; confirm for bulk over a
  threshold). Copy names the consequence: "Delete 12 files? Their AI-searchable
  content is removed too."
- **Reversible** (Move, Assign group, Re-vectorize) → no modal; act + Undo toast.
- Never a naked "Are you sure?" — always state *what* and *what happens to RAG*.

#### G. Progress & long-running feedback

- Uploads: existing per-file + byte-percent progress, plus a **slow-upload** hint
  (already in `FileSelectionModal`) — keep and apply the same pattern in the new
  manager.
- Vectorization & generated media in flight: the live **pill** updates in place
  (subscribes to Feature 1's `media_job.update`); no spinner-only dead ends.
- Skeleton rows/tiles while the list/thumbnails load; never a blank flash.

### 4.10 The file attachment window (chat picker) redesign

The "attachment window" (`FileSelectionModal.vue`) and the inline
`@`-mention picker (`FileMentionPalette.vue`) are where most users *touch* files,
yet today they only upload + list. They must become a fast, clear "pick the right
file" surface that mirrors the manager's vocabulary.

#### 4.10.1 Goals

- Reuse the **same** language, icons, source badges and "searchable by AI"
  indicator as the manager — one mental model.
- Make the *right* file findable in seconds: recents, search, and **type filter
  in the picker itself** (the gap noted earlier — "filtering of file types in
  selection").
- Clarify the consequence of attaching: an attached file is sent to the AI for
  *this* message; a *searchable-by-AI* file is also reusable across chats.

#### 4.10.2 Layout (attachment window)

```
┌─ Add files to this message ─────────────────────────────── (?) ─ ✕ ─┐
│  [⬆ Upload new]   or drag & drop          🔍 search your files       │
│  Type:  All ▾ | 📄 Docs | 🖼 Images | 🎬 Video | 🎵 Audio            │
│  ───────────────────────────────────────────────────────────────── │
│  Recent                                                              │
│   ☐ 📄 contract.pdf   ⬆ upload   ✅ searchable · Contracts   1.2 MB   │
│   ☐ 🖼 sunset.png     ✨ gen      🚫 n/a                     540 KB   │
│  All files (filtered)                                    [≣ list ⊞]  │
│   ☐ 📄 specs.docx     ☁ opencl.  ⚠ failed · retry            90 KB   │
│  ─────────────────────────────────────────────────────────────────  │
│  Selected: 2 files          [Cancel]              [Attach 2 files]   │
└─────────────────────────────────────────────────────────────────────┘
  Helper line: "Attached files are sent to the AI for this message.
                Files marked ✅ are also searchable across all your chats."
```

#### 4.10.3 Behaviours

- **Type filter chips** (Docs / Images / Video / Audio / All) filter the list
  client- or server-side; honoured for both recents and search results. This is
  the picker counterpart of the manager's Type filter.
- **Search** (debounced) over name + original name; empty-result and
  no-recents states use §4.B copy.
- **Inline source badge + searchable pill** on every row, identical to the
  manager — so users learn the icons in one place.
- **Drag & drop and Upload** preserved (with the existing progress + slow-upload
  hint), but the destination/consequence is stated in the helper line.
- **Clear primary CTA** with a live count ("Attach 2 files"); disabled with a
  tooltip when nothing is selected.
- **`@`-mention palette** (`FileMentionPalette.vue`): same vocabulary; show the
  searchable pill + group so the user mentions the *right* document; keyboard-first
  (↑/↓/Enter), a one-line header hint ("Type to find a file to give the AI").

#### 4.10.4 Accessibility & motion

- Full keyboard path (open → filter → search → select → attach), visible focus
  ring, `aria-describedby` for every helper/tooltip, `aria-live="polite"` for
  toasts and result counts.
- Respect `prefers-reduced-motion`; modal/transition already tokenised — keep
  dark-mode-safe design tokens, no hardcoded colors.
- All hit targets ≥ 40px on touch; the window is already bottom-sheet on mobile —
  keep that and make filter chips horizontally scrollable.

### 4.11 i18n copy deck (en / de / es / tr)

All new strings are **vue-i18n** keys added to `frontend/src/i18n/{en,de,es,tr}.json`
(registered as `supportedLanguages = ['de','en','es','tr']`). Per the workspace
rule, **a key missing from one locale silently falls back to English** — so each
of the strings in §4.9/§4.10 ships in all four at the same time.

#### 4.11.1 Namespacing

- Manager: `files.*` (extend existing); attachment window: `fileSelection.*`
  (extend existing); mention palette: `fileMention.*`; shared helpers:
  `files.help.*`; toasts: `files.toast.*`; empty states: `files.empty.*`.
- Glossary terms defined once under `files.terms.*` and **referenced** by other
  strings (vue-i18n linked messages) so "searchable by AI" reads identically
  everywhere and is translated once.

#### 4.11.2 Glossary (translate once, reuse everywhere)

| Concept | en | de | es | tr |
|---|---|---|---|---|
| searchable by AI | searchable by AI | KI-durchsuchbar | consultable por IA | yapay zekâ ile aranabilir |
| knowledge group | knowledge group | Wissensgruppe | grupo de conocimiento | bilgi grubu |
| incoming | incoming | Eingang | entrantes | gelen |
| generated | AI-generated | KI-erzeugt | generado por IA | yapay zekâ üretimi |

#### 4.11.3 Representative copy (the bar for quality)

| Key | en | de | es | tr |
|---|---|---|---|---|
| `files.empty.browse` | Every upload, chat attachment and AI-generated file lives here. | Jeder Upload, Chat-Anhang und jede KI-erzeugte Datei liegt hier. | Aquí están todas tus subidas, adjuntos de chat y archivos generados por IA. | Tüm yüklemeleriniz, sohbet ekleri ve yapay zekâ dosyaları burada. |
| `files.toast.moved` | Moved {n} files to {group}. | {n} Dateien nach {group} verschoben. | {n} archivos movidos a {group}. | {n} dosya {group} grubuna taşındı. |
| `files.help.vectorized` | Its contents are in your knowledge base, so the assistant can use them. | Der Inhalt ist in deiner Wissensbasis und kann vom Assistenten genutzt werden. | Su contenido está en tu base de conocimiento para que el asistente lo use. | İçeriği bilgi tabanınızda; asistan bunu kullanabilir. |
| `fileSelection.helper` | Attached files are sent to the AI for this message. | Angehängte Dateien werden für diese Nachricht an die KI gesendet. | Los archivos adjuntos se envían a la IA para este mensaje. | Eklenen dosyalar bu mesaj için yapay zekâya gönderilir. |

#### 4.11.4 Quality gates for copy

- **No untranslated keys**: a CI/check (or `make -C frontend ...` script) asserts
  the four locale files share the same key set; a missing key fails the gate.
- **Length/overflow**: German strings run ~30% longer — buttons/pills use the
  longest locale in layout review; verify in dark mode and at 320px.
- **No hardcoded user-facing text** in any new component (lint rule already
  favours this); every label/tooltip/toast is a key.

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
- **H — UX helpers, attachment window & copy deck (§4.9–4.11).** Empty/first-run
  states, `(?)` help bubbles + tooltips, the full toaster catalogue (with Undo on
  reversible/destructive actions), inline status explainers, and the redesigned
  attachment window + `@`-mention palette (type filter in the picker, recents,
  searchable/source badges, helper line). All strings added to **all four
  locales** (`en`/`de`/`es`/`tr`) with a locale-key-parity check; a11y + dark-mode
  + 320px review. Component + i18n-parity tests. (Builds on Sprints E/F.)

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
- **Every AI-generated file is auto-foldered** into the **"AI generated"** library
  by category (Images / Videos / Audio / Documents / Calendar) with **zero manual
  filing**, including AI-written Word/Office documents (§11).
- **Generated files count toward the storage quota** (shown separately in the
  meter) and are deletable; deleting one frees exactly its bytes with no drift
  (§12).
- Filter by source/group/type/vectorized state + fast search, on thousands of
  files, feels instant.
- Share works from the file manager (file-id based), with tests.
- **Every surface explains itself**: no blank empty states; a `(?)` help bubble or
  tooltip on every non-obvious control; the "searchable by AI", group, source and
  incoming concepts are explained in plain words (§4.9).
- **Every mutating action gives feedback**: a toast per the catalogue (§4.9 D),
  bulk actions report counts, and reversible/destructive actions offer Undo or a
  consequence-naming confirm.
- **Attachment window & `@`-mention picker** match the manager's vocabulary,
  support a type filter + search + recents, and state the attach consequence
  (§4.10).
- **All user-facing strings exist in `en`/`de`/`es`/`tr`** (no English fallbacks),
  pass the locale-key-parity check, and are verified for overflow in dark mode at
  320px.
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
2. **Decided:** generated media **counts against the storage quota** (one shared
   budget) but is **shown separately** in the storage meter; generated files are
   fully deletable and deletion is quota-correct with zero drift. Full design in
   **§12 (Generated Files & Storage Quota)**.
3. Retention for generated media — keep forever (until user deletes) vs. auto-
   prune after N days? (Proposal: keep; user-managed.)
4. Should the Generated gallery be a tab here or also surface on the dashboard?

---

## 11. AI-Generated Files: Auto-Foldering & Categorization

**Added:** 2026-06-26 · **Scope:** strictly `BSOURCE=generated` (AI engine
outputs only) · **Builds on:** G1 (§1), §3.2 (generalise the registrar), §4.6
(the Generated tab), and Feature 1's `MediaJob` finalize.

> Goal: **every file an AI engine produces** — images, video, audio/TTS, the
> **Word/Office documents** the AI writes, and calendar/ICS — is **taken into the
> file manager automatically** and **organised, with zero manual filing**, under
> a single **"AI generated"** library that is split into clear categories
> (**Images · Videos · Audio · Documents · Calendar**). The user opens "AI
> generated", sees their AI output grouped by kind, and can preview, download, or
> jump back to the originating chat in one click.

### 11.1 Why (beyond §4.6)

§4.6 makes generated media *visible* as a single flat "Generated" gallery. That
answers "where did my last image go?" but not "show me **all** the videos the AI
made" or "find the **Word document** the assistant wrote last week." It also
under-serves **generated documents**, which are easy to lose among media. This
section turns the flat gallery into a **foldered, auto-categorized library** and
guarantees that **all** engine outputs — not just media — land there correctly.

### 11.2 Scope (decided)

- **In scope:** only files with `BSOURCE=generated` (image, video, audio/TTS,
  generated document/Office, calendar/ICS).
- **Out of scope:** user uploads, chat attachments, and integration/incoming
  pushes — they keep their own source taxonomy and the Incoming flow (§4.5). We
  do **not** fold other sources by type here.

### 11.3 Folder representation (decided: virtual / derived)

A "folder" in Synaplan today is a `BGROUPKEY` — a **RAG knowledge group**
(`FileController::getFileGroups`, `FilesView.vue`). Generated media is
`BVECTORSTATE=not_applicable`, so it must **not** become a real knowledge group
(that would pollute RAG retrieval).

Therefore the **"AI generated" library is a virtual/derived folder tree**, not a
stored `BGROUPKEY`:

- Computed from `BSOURCE=generated` + `BORIGINKIND`. **No new columns, no
  reserved group keys.**
- Generated **documents** that the user *wants* searchable can still be assigned
  to a real knowledge group independently — that is the orthogonal "Make
  searchable by AI" action (§11.6); it never moves them out of the AI-generated
  library view.

### 11.4 The kind → category classifier (single source of truth)

One classifier maps every engine artefact to exactly one category, and is used by
**both** `GeneratedFileRegistrar` (sync path) **and** the `MediaJob` finalize
(async path, Feature 1) so sync and async always agree. It falls back to the file
extension when the handler's `type` is empty (mirrors the registrar's current
`mimeForExtension` behaviour).

| `BORIGINKIND` | Category (virtual folder) | Typical extensions |
|---|---|---|
| `image` | **Images** | png, jpg, jpeg, webp, gif, svg |
| `video` | **Videos** | mp4, webm, mov |
| `audio` | **Audio** | mp3, wav, ogg |
| `document` | **Documents** (incl. **Word**/Office) | docx, xlsx, pptx, pdf, csv, md, txt, html, json |
| `calendar` | **Calendar** (its own category) | ics |

- Unknown/empty kind → fall back to extension → default to `document` (never
  drop a file out of the library).
- **Guarantee:** the registrar must set `BORIGINKIND` (via this classifier),
  `BPROVIDER`, `BMESSAGEID`, and `BSOURCE=generated` for **every** generated file.
  Today the registrar sets *none* of these and has **only one caller**
  (`WidgetPublicController`), so implementation must route **all** generation
  paths through it (or the finalize step) — see §11.9 AG-1.

### 11.5 The "AI generated" tree (GUI)

The Generated tab (§4.6) renders the virtual tree:

```
📁 AI generated (137)
   ├─ 🖼 Images (54)
   ├─ 🎬 Videos (12)
   ├─ 🎵 Audio (31)
   ├─ 📄 Documents (38)    ← Word / Office / PDF the AI wrote
   └─ 📅 Calendar (2)
```

- Clicking a category filters to `source=generated` + `origin_kind=<kind>`.
- **Media categories** (Images/Videos/Audio) default to the **grid/gallery** with
  thumbnails/posters + lightbox/player (as §4.6). **Documents** and **Calendar**
  default to a **list** with a kind icon, provider/prompt, and date.
- Counts come from the **facets API** (§11.7) — no per-row calls.
- Per item, keep §4.6 actions: **Preview**, **Download**, **Open in chat** (via
  `BMESSAGEID`), **Delete**. Generated **documents** additionally get **"Make
  searchable by AI"** (assign to a knowledge group → vectorize), which is the
  only RAG-touching action and is fully optional.
- **Delete frees quota:** because generated files count toward storage, deleting
  one (single or bulk) immediately frees its bytes and refreshes the storage
  meter — see **§12 (Generated Files & Storage Quota)**.
- Empty states per §4.B style: e.g. "Images, videos, audio and documents created
  by the AI are filed here automatically."

### 11.6 Relationship to RAG (generated documents)

- Generated media: `BVECTORSTATE=not_applicable` — pure organisational foldering.
- Generated documents: start `BVECTORSTATE=none`; the **"Make searchable by AI"**
  action vectorizes and assigns a knowledge group. The file stays in **AI
  generated → Documents** (virtual) *and* gains a real group — the two views are
  independent and both correct.

### 11.7 API (additive — no new endpoints)

- `GET /api/v1/files` — reuse the **already-planned** `origin_kind` filter
  (§5) together with `source=generated`.
- `GET /api/v1/files/facets` — must include the **per-`origin_kind` counts for
  `source=generated`** so the tree renders category counts in one call. (Already
  introduced in §5 for source/group/type/vector_state; this just pins the
  generated-category breakdown as a requirement.)
- **No new endpoints** are required for this feature — call this out to avoid
  scope creep.

### 11.8 Backfill & heal

- Extend `app:files:backfill-media` (§3.2 / Sprint B): for legacy
  `status=generated` rows, set `BORIGINKIND` via the §11.4 classifier (by
  extension) so existing generated files fold into the right category.
- Fix-on-read in `ChatController::getMessages` so opened chats heal their old
  generated files. Idempotent and batched (reuse §8 mitigations).

### 11.9 Sprints (sequencing for the future code effort)

These slot **after/alongside** Feature 2 Sprints A/B/D/F (shared data model +
Generated tab) and Feature 1 Sprint C (finalize site).

- **AG-1 — Classifier + always-register.** Add the single kind→category
  classifier; route **all** generation paths through `GeneratedFileRegistrar`;
  registrar **and** `MediaJob` finalize stamp
  `BORIGINKIND`/`BPROVIDER`/`BMESSAGEID`/`BSOURCE=generated`. Extend
  `GeneratedFileRegistrarTest`. *(Depends on Feature 2 Sprint A.)*
- **AG-2 — Backfill kinds.** Extend `app:files:backfill-media` + fix-on-read to
  set `BORIGINKIND` on legacy generated rows. Idempotent; tested.
- **AG-3 — Facets + filter.** Ensure `/facets` returns the generated-category
  counts; confirm `origin_kind` filter on `/files`. OpenAPI + schema regen.
- **AG-4 — Folder-tree GUI.** Turn the Generated tab into the "AI generated" tree
  (categories, counts, grid-for-media / list-for-docs), reuse §4.6 actions, add
  the generated-document "Make searchable by AI" action. Component + a11y tests.
- **AG-5 — i18n + polish.** All four locales (§11.11), key-parity check,
  dark-mode/320px review, E2E ("generate an image / a video / have the AI write a
  Word doc → each appears under **AI generated → correct category** → download /
  open in chat").

### 11.10 Risks & mitigations

- **Mis-classification** when `type` is empty/odd → extension fallback + default
  `document` bucket; unit-test the mapping table.
- **Duplicate registration** (sync + async both register one artefact) → make
  registration idempotent keyed on path + `BMESSAGEID`; Feature 1 finalize is the
  single site for async.
- **Generated docs that should be RAG-searchable** → the orthogonal "Make
  searchable" action; foldering never blocks vectorization.
- **Tree count drift** → derive counts from indexed facets, never per-row.

### 11.11 i18n (en/de/es/tr) — copy-paste ready

Additive keys under the **existing** `files` namespace (do **not** create a new
top-level namespace). All four locales ship together; the Sprint H locale-key
parity check (Appendix A.5) covers these too. Glossary terms reuse
`files.terms.*` where possible (e.g. `@:files.terms.generated`).

```jsonc
// merge into "files": { ... }
"aiGenerated": {
  "folder": "AI generated",
  "subtitle": "Everything the AI created for you, organised by kind.",
  "category": {
    "images": "Images",
    "videos": "Videos",
    "audio": "Audio",
    "documents": "Documents",
    "calendar": "Calendar"
  },
  "empty": "Images, videos, audio and documents created by the AI are filed here automatically.",
  "makeSearchable": "Make searchable by AI",
  "makeSearchableHelp": "Add this AI-written document to a knowledge group so the assistant can search it."
}
```

```jsonc
// de.json — merge into "files": { ... }
"aiGenerated": {
  "folder": "KI-erzeugt",
  "subtitle": "Alles, was die KI für dich erstellt hat, nach Art geordnet.",
  "category": {
    "images": "Bilder",
    "videos": "Videos",
    "audio": "Audio",
    "documents": "Dokumente",
    "calendar": "Kalender"
  },
  "empty": "Von der KI erstellte Bilder, Videos, Audios und Dokumente werden hier automatisch abgelegt.",
  "makeSearchable": "KI-durchsuchbar machen",
  "makeSearchableHelp": "Dieses KI-erstellte Dokument einer Wissensgruppe hinzufügen, damit der Assistent es durchsuchen kann."
}
```

```jsonc
// es.json — merge into "files": { ... }
"aiGenerated": {
  "folder": "Generado por IA",
  "subtitle": "Todo lo que la IA ha creado para ti, organizado por tipo.",
  "category": {
    "images": "Imágenes",
    "videos": "Vídeos",
    "audio": "Audio",
    "documents": "Documentos",
    "calendar": "Calendario"
  },
  "empty": "Las imágenes, vídeos, audio y documentos creados por la IA se archivan aquí automáticamente.",
  "makeSearchable": "Hacer consultable por IA",
  "makeSearchableHelp": "Añade este documento creado por IA a un grupo de conocimiento para que el asistente pueda consultarlo."
}
```

```jsonc
// tr.json — merge into "files": { ... }
"aiGenerated": {
  "folder": "Yapay zekâ üretimi",
  "subtitle": "Yapay zekânın sizin için oluşturduğu her şey, türüne göre düzenlenmiş.",
  "category": {
    "images": "Görseller",
    "videos": "Videolar",
    "audio": "Ses",
    "documents": "Belgeler",
    "calendar": "Takvim"
  },
  "empty": "Yapay zekânın oluşturduğu görseller, videolar, ses ve belgeler buraya otomatik olarak dosyalanır.",
  "makeSearchable": "Yapay zekâ ile aranabilir yap",
  "makeSearchableHelp": "Bu yapay zekâ tarafından yazılan belgeyi bir bilgi grubuna ekleyin; böylece asistan onu arayabilir."
}
```

### 11.12 Definition of done (for the feature when later built)

- Every AI engine output (image/video/audio/**Word & Office docs**/ICS) appears
  automatically under **AI generated → <category>** with the correct
  source/provider/message link, **no manual filing**.
- Legacy generated files fold into the right category after backfill / on chat
  open.
- The category vocabulary is identical in the manager and the attachment picker,
  in all four locales (parity check green).
- No new API endpoints; counts driven by facets.
- Full gate green + the AG-5 E2E.

---

## 12. Generated Files & Storage Quota

**Added:** 2026-06-26 · **Scope:** AI-generated files (`BSOURCE=generated`) ·
**Resolves:** §10 open question #2 · **Builds on:** §3.2 (every generated file is
a `BFILES` row), §11 (the AI-generated library), Feature 1's `MediaJob` finalize.

> Goal: every file an AI engine produces **counts toward the user's storage
> quota** (one shared budget, but **shown separately** so users see how much is
> AI-generated), is **deletable** wherever it appears, and **deletion is always
> quota-correct** — freeing exactly the file's bytes with **no drift**.

### 12.1 The model that makes this almost free

Storage usage today is **computed live**, not cached:
`StorageQuotaService::getStorageUsage()` returns
`SUM(BFILES.fileSize) WHERE BUSERID = :user` — there is **no usage counter** and
**no exclusion** by status or source (verified). Two consequences drive this
whole section:

1. **Attribution is automatic.** The moment a generated file exists as a `BFILES`
   row (guaranteed by §3.2 / §11 AG-1), it is counted like any other file. We do
   **not** add a parallel accounting system. The *only* requirement is an
   **accurate `fileSize` at registration**.
2. **Deletion is drift-proof by construction.** Since usage is a live `SUM`,
   removing the `BFILES` row makes the next usage read correct **immediately** —
   there is no counter to decrement and therefore nothing that can drift. The
   "deletion must be correct in the quota count" requirement is satisfied by the
   design; we add tests that *prove* it rather than new bookkeeping.

### 12.2 Attribution — accurate size, sync & async

- **Sync** (`GeneratedFileRegistrar`): already sets `fileSize` from `filesize()`
  of the written artefact. Keep; cover with a test.
- **Async** (`MediaJob` finalize, Feature 1): after `downloadVideoRaw()` + save,
  the finalize step **must** record the downloaded artefact's real byte size on
  the `BFILES` row before/at `markCompleted` — never `0`. This is the one place a
  generated file could otherwise slip into the registry with a wrong size.
- No double counting: usage sums `BFILES` only; a file referenced by both a
  `BFILES` row and `BMESSAGES.BFILEPATH` is counted once.

### 12.3 "Shown separately" (transparency, not a second budget)

Generated media shares the **same** quota, but the UI reveals how much of the
used space is AI-generated.

- Extend `StorageQuotaService::getStorageStats()` with a generated breakdown:
  `generated_usage` (+ `generated_usage_formatted`). *(Optional / nice-to-have:
  per-category bytes `{images, videos, audio, documents, calendar}` from
  `BSOURCE=generated` + `BORIGINKIND`.)*
- `GET /api/v1/files/storage-stats` returns the breakdown (additive; OpenAPI +
  schema regen).
- `StorageQuotaWidget.vue` shows a secondary line / bar sub-segment, e.g.
  "incl. {x} AI-generated", consistent with the §4.9 storage-meter help
  ("AI-generated media counts too, shown separately"). New `storage.*` i18n keys
  in all four locales.
- **Performance:** the breakdown query uses the already-planned
  `(BUSERID, BSOURCE)` index (§3.1); the total uses `idx_file_user`. Both are
  cheap; no per-row calls.

### 12.4 Deletable everywhere

- The **Delete** action in §4.6 / §11 calls the existing
  `DELETE /api/v1/files/{id}`, which removes vectors (if any) + the disk file +
  the `BFILES` row. On success the client calls the already-exposed
  `StorageQuotaWidget.refresh()` so freed space shows immediately.
- **Bulk delete** (§4.7) reports a count and refreshes the meter once.
- Toaster + Undo follow §4.9 D ("Deleted {n} files." + Undo soft window). **Undo
  semantics:** deletion is a hard delete, so usage drops immediately on delete; if
  a soft-delete/trash is ever introduced, trashed files must be **excluded** from
  `getStorageUsage` so the meter still reflects reclaimable space honestly.

### 12.5 Deletion correctness — the invariant (+ edge case E1)

- **Invariant:** `usage = SUM(BFILES.fileSize)`; deleting a generated file's row
  reduces usage by **exactly** that file's `fileSize`, with no drift and no
  reconciliation job. Concurrency-safe: a delete and a usage read are independent;
  no locking needed.
- **Edge case E1 — dangling chat reference (decided):** a generated file is often
  also referenced by its originating `BMESSAGES.BFILEPATH` (the chat card). Since
  delete removes the disk file, the chat card would otherwise 404. **On delete we
  detach the message media link and render a tasteful "deleted by user"
  placeholder** in the chat — so deletion is consistent across the manager, the
  AI-generated library, and chat history. (Alternative considered and rejected:
  warn-only, which leaves broken cards.)

### 12.6 Backfill grandfathering (important)

When Feature 2 **Sprint B** backfills legacy generated media into `BFILES`,
existing users' usage will **jump** and some may exceed their limit. Policy:

- **Never retroactively block** existing content. Quota enforcement
  (`checkStorageLimit`) gates only **new uploads** and **new generations**.
- An over-limit user sees the meter at 100% + a clean-up / upgrade prompt, but
  nothing is deleted and existing files remain usable.

### 12.7 Enforcement on generation (decided)

- **Uploads** keep their pre-flight `checkStorageLimit` (unchanged).
- **AI generation** does **not** hard-block when over quota (it is a billed
  action and blocking mid-DAG is poor UX). Instead: **warn** when near/over the
  limit (in chat + the storage meter) and **count** the produced file afterward.
  Users reclaim space by deleting generated files (§12.4) or upgrading.

### 12.8 i18n (en/de/es/tr)

Additive `storage.*` keys, all four locales, covered by the Sprint H key-parity
check.

```jsonc
// merge into "storage": { ... }   // en.json
"includingGenerated": "incl. {size} AI-generated",
"generatedBreakdownHelp": "Part of your used space is taken by files the AI created. Delete them anytime to free space.",
"overLimitGenerateWarning": "You're over your storage limit. New files you create are still saved, but please free up space or upgrade."
```
```jsonc
// de.json
"includingGenerated": "inkl. {size} KI-erzeugt",
"generatedBreakdownHelp": "Ein Teil deines belegten Speichers stammt aus KI-erstellten Dateien. Du kannst sie jederzeit löschen, um Platz zu schaffen.",
"overLimitGenerateWarning": "Du hast dein Speicherlimit überschritten. Neue Dateien werden weiterhin gespeichert – bitte schaffe Platz oder führe ein Upgrade durch."
```
```jsonc
// es.json
"includingGenerated": "incl. {size} generado por IA",
"generatedBreakdownHelp": "Parte de tu espacio usado lo ocupan archivos creados por la IA. Puedes eliminarlos cuando quieras para liberar espacio.",
"overLimitGenerateWarning": "Has superado tu límite de almacenamiento. Los archivos nuevos se siguen guardando, pero libera espacio o mejora tu plan."
```
```jsonc
// tr.json
"includingGenerated": "{size} yapay zekâ üretimi dahil",
"generatedBreakdownHelp": "Kullanılan alanınızın bir kısmı yapay zekânın oluşturduğu dosyalara aittir. Yer açmak için istediğiniz zaman silebilirsiniz.",
"overLimitGenerateWarning": "Depolama sınırınızı aştınız. Yeni dosyalar yine de kaydedilir; lütfen yer açın veya yükseltin."
```

### 12.9 Sprints (for the future code effort)

Depend on Feature 2 Sprint A/B and §11 AG-1.

- **Q-1 — Accurate size + breakdown.** Ensure sync **and** async registration set
  a real `fileSize`; extend `getStorageStats()` with `generated_usage`; OpenAPI +
  schema regen. Test: registering a generated file increases usage by exactly its
  size.
- **Q-2 — Deletion correctness + chat detach (E1).** Test that deleting a
  generated file (single + bulk) drops usage by exactly its bytes; detach the
  `BMESSAGES` media link + render the "deleted" placeholder; meter refreshes.
- **Q-3 — "Shown separately" widget + grandfathering + warnings.** Widget
  sub-segment + copy; enforcement gates only new uploads/generations; over-limit
  warning on generation; i18n (4 locales). Component/i18n tests.

### 12.10 Risks & mitigations

- **Wrong/zero size at async registration** → usage under-counts. Mitigation:
  finalize records the downloaded size; Q-1 test asserts it.
- **Backfill usage spike** → §12.6 grandfathering (never retroactively block).
- **Broken chat cards after delete** → §12.5 E1 detach + placeholder.
- **Breakdown query cost at scale** → `(BUSERID, BSOURCE)` index (§3.1).

### 12.11 Definition of done (feature, when built)

- Every generated file (image/video/audio/Word & Office doc/ICS) counts toward
  the quota with its **real byte size**, sync and async.
- The storage widget shows **total** usage and the **AI-generated portion**.
- Deleting a generated file (single or bulk) frees **exactly** its bytes
  immediately, with **no drift**, and leaves **no dangling chat card** (E1).
- Backfilling legacy generated media never retroactively blocks existing users;
  enforcement gates only new uploads/generations.
- Full gate green + tests for the count and delete invariants.

---

## 13. Appendix A — i18n key deck (copy-paste ready, en/de/es/tr)

These are the **new** keys for the §4.9–4.11 UX layer, ready to merge into
`frontend/src/i18n/{en,de,es,tr}.json`. They are nested under the **existing**
namespaces (`files`, `fileSelection`, `fileMention`) — add the objects below into
those namespaces; do **not** create new top-level namespaces.

**Implementation notes**

- These are **additive**. A few flat legacy keys already exist (`files.filterTypeAll`,
  `files.filterTypeImages`, `files.filterTypeAudio`, `files.emptyState.*`,
  `files.vectorized`, `files.movedSuccess`, `files.reVectorize`). During the
  Sprint E rebuild, migrate call-sites to the structured keys below
  (`files.filter.*`, `files.empty.*`, `files.vectorState.*`, `files.toast.*`) and
  remove the superseded flat ones in the same PR so there is exactly one key per
  string.
- Plurals use vue-i18n pipe syntax (`singular | plural`). Turkish has no count
  plural; both arms are intentionally the same.
- `{name}`, `{count}`, `{group}`, `{limit}`, `{type}`, `{reason}`, `{source}`,
  `{time}` are interpolation params — keep them verbatim in every locale.
- The glossary (`files.terms.*`) is the single source for shared words; prefer
  vue-i18n linked messages (`@:files.terms.vectorized`) when embedding them in
  longer strings so the term is translated once.
- After adding: `make -C frontend lint` + `npm run check:types`, and run the
  locale-key-parity check (Sprint H) so no locale is missing a key.

### A.1 English — `en.json`

```jsonc
// merge into "files": { ... }
"terms": {
  "vectorized": "searchable by AI",
  "knowledgeGroup": "knowledge group",
  "incoming": "incoming",
  "generated": "AI-generated",
  "notApplicable": "not applicable"
},
"tabBrowse": "Browse",
"tabIncoming": "Incoming",
"tabGenerated": "Generated",
"incomingBadge": "{count} new",
"source": {
  "web_upload": "Upload",
  "chat_attachment": "Chat",
  "outlook": "Outlook",
  "nextcloud": "Nextcloud",
  "opencloud": "OpenCloud",
  "whatsapp": "WhatsApp",
  "widget": "Widget",
  "api": "API",
  "generated": "AI-generated"
},
"vectorState": {
  "vectorized": "Searchable by AI",
  "vectorizedDetail": "Searchable by AI · {group} · {count} chunks",
  "processing": "Processing…",
  "none": "Not searchable",
  "notApplicable": "Not applicable",
  "failed": "Failed"
},
"filter": {
  "source": "Source",
  "group": "Group",
  "type": "Type",
  "vectorized": "Searchable",
  "date": "Date",
  "clear": "Clear filters",
  "typeAll": "All types",
  "typeDocs": "Documents",
  "typeImages": "Images",
  "typeVideo": "Video",
  "typeAudio": "Audio",
  "vectorizedYes": "Searchable by AI",
  "vectorizedNo": "Not searchable",
  "noGroup": "No group"
},
"help": {
  "vectorized": "When a file is searchable by AI, its contents are added to your knowledge base so the assistant can use them to answer you.",
  "group": "Knowledge groups bundle files together. When the AI searches a group, it can use every file in it.",
  "source": "Where this file came from — an upload, a chat, Outlook, Nextcloud, OpenCloud, WhatsApp, the widget, the API, or AI-generated.",
  "incoming": "Files other apps pushed in for your knowledge base. Review them, then keep or dismiss.",
  "typeFilter": "Show only one kind of file — documents, images, video or audio.",
  "vectorizedFilter": "Filter by whether files are searchable by AI.",
  "reVectorize": "Re-read this file and refresh what the AI knows from it. Use after editing it or if it failed.",
  "moveGroup": "Move files into another knowledge group. This changes which group the AI searches them in — it doesn't delete anything.",
  "storage": "How much of your storage you've used. AI-generated media counts too, shown separately."
},
"empty": {
  "browseTitle": "Your file library",
  "browseBody": "Every upload, chat attachment and AI-generated file lives here, ready to search and reuse.",
  "browseAction": "Upload your first file",
  "filteredBody": "No files match these filters.",
  "filteredAction": "Clear filters",
  "incomingBody": "Files sent in from Outlook, Nextcloud or OpenCloud appear here for you to review before filing.",
  "incomingAction": "Set up integrations",
  "generatedBody": "Images, video and audio you create in chat are saved here so you can find and download them again.",
  "generatedAction": "Start a chat",
  "groupBody": "This group has no files yet. Add files to make them searchable together.",
  "groupAction": "Add files"
},
"toast": {
  "uploaded": "Uploaded {name}. | Uploaded {count} files.",
  "uploadFailed": "Couldn't upload {name}. {reason}",
  "tooLarge": "{name} is too large (max {limit}).",
  "unsupported": "{type} files aren't supported.",
  "vectorizeStart": "Making {name} searchable by AI…",
  "vectorizeDone": "{name} is now searchable by AI.",
  "vectorizeFailed": "Couldn't process {name}.",
  "revectorizedBulk": "Refreshed {count} files.",
  "moved": "Moved {count} files to {group}.",
  "filed": "Filed {count} files in {group}.",
  "deleted": "Deleted {count} files.",
  "incomingKept": "Kept {count} files in {group}.",
  "incomingDismissed": "Dismissed {count} files.",
  "shareCreated": "Share link copied to clipboard.",
  "shareRevoked": "Sharing turned off for {name}.",
  "downloadPreparing": "Preparing {count} files for download…",
  "genericError": "Something went wrong. {reason}",
  "undo": "Undo"
},
"confirm": {
  "deleteTitle": "Delete {count} files?",
  "deleteBody": "Their AI-searchable content is removed too. This can't be undone.",
  "deleteConfirm": "Delete",
  "dismissTitle": "Dismiss {count} files?",
  "dismissBody": "They won't be added to your library. You can re-import them later from the source.",
  "dismissConfirm": "Dismiss"
},
"incoming": {
  "title": "Incoming",
  "subtitle": "Files pushed in for your knowledge base — review, then keep or dismiss.",
  "keep": "Keep",
  "keepAll": "Keep all",
  "assignGroup": "Assign group",
  "dismiss": "Dismiss",
  "open": "Open",
  "retry": "Retry",
  "provenance": "from {source} · {time}"
},
"generated": {
  "title": "Generated",
  "subtitle": "Images, video and audio created in your chats.",
  "openInChat": "Open in chat",
  "download": "Download"
},
"explainer": {
  "text": "Everything you upload or create lives here. Files marked \"searchable by AI\" are added to your knowledge base so the assistant can use them.",
  "dismiss": "Got it",
  "reopen": "What is this?"
}
```

```jsonc
// merge into "fileSelection": { ... }
"helper": "Attached files are sent to the AI for this message. Files marked ✅ are also searchable across all your chats.",
"searchYourFiles": "Search your files",
"typeAll": "All",
"typeDocs": "Docs",
"typeImages": "Images",
"typeVideo": "Video",
"typeAudio": "Audio",
"recent": "Recent",
"allFilesFiltered": "All files",
"attachCount": "Attach {count} file | Attach {count} files",
"noRecents": "Pick a recent file or upload a new one to use in this chat.",
"noResults": "No files match. Try another search or upload a new file."
```

```jsonc
// merge into "fileMention": { ... }
"header": "Type to find a file to give the AI"
```

### A.2 German — `de.json`

```jsonc
// merge into "files": { ... }
"terms": {
  "vectorized": "KI-durchsuchbar",
  "knowledgeGroup": "Wissensgruppe",
  "incoming": "Eingang",
  "generated": "KI-erzeugt",
  "notApplicable": "nicht zutreffend"
},
"tabBrowse": "Durchsuchen",
"tabIncoming": "Eingang",
"tabGenerated": "Erzeugt",
"incomingBadge": "{count} neu",
"source": {
  "web_upload": "Upload",
  "chat_attachment": "Chat",
  "outlook": "Outlook",
  "nextcloud": "Nextcloud",
  "opencloud": "OpenCloud",
  "whatsapp": "WhatsApp",
  "widget": "Widget",
  "api": "API",
  "generated": "KI-erzeugt"
},
"vectorState": {
  "vectorized": "KI-durchsuchbar",
  "vectorizedDetail": "KI-durchsuchbar · {group} · {count} Abschnitte",
  "processing": "Wird verarbeitet…",
  "none": "Nicht durchsuchbar",
  "notApplicable": "Nicht zutreffend",
  "failed": "Fehlgeschlagen"
},
"filter": {
  "source": "Quelle",
  "group": "Gruppe",
  "type": "Typ",
  "vectorized": "Durchsuchbar",
  "date": "Datum",
  "clear": "Filter zurücksetzen",
  "typeAll": "Alle Typen",
  "typeDocs": "Dokumente",
  "typeImages": "Bilder",
  "typeVideo": "Video",
  "typeAudio": "Audio",
  "vectorizedYes": "KI-durchsuchbar",
  "vectorizedNo": "Nicht durchsuchbar",
  "noGroup": "Keine Gruppe"
},
"help": {
  "vectorized": "Wenn eine Datei KI-durchsuchbar ist, wird ihr Inhalt deiner Wissensbasis hinzugefügt, damit der Assistent ihn für Antworten nutzen kann.",
  "group": "Wissensgruppen bündeln Dateien. Wenn die KI eine Gruppe durchsucht, kann sie jede Datei darin nutzen.",
  "source": "Woher diese Datei stammt – Upload, Chat, Outlook, Nextcloud, OpenCloud, WhatsApp, Widget, API oder KI-erzeugt.",
  "incoming": "Dateien, die andere Apps für deine Wissensbasis eingeliefert haben. Prüfe sie und behalte oder verwirf sie.",
  "typeFilter": "Nur eine Dateiart anzeigen – Dokumente, Bilder, Video oder Audio.",
  "vectorizedFilter": "Danach filtern, ob Dateien KI-durchsuchbar sind.",
  "reVectorize": "Diese Datei neu einlesen und das Wissen der KI daraus auffrischen. Nach Änderungen oder bei Fehlern verwenden.",
  "moveGroup": "Dateien in eine andere Wissensgruppe verschieben. Das ändert, in welcher Gruppe die KI sie durchsucht – es wird nichts gelöscht.",
  "storage": "Wie viel deines Speichers belegt ist. KI-erzeugte Medien zählen mit und werden separat angezeigt."
},
"empty": {
  "browseTitle": "Deine Dateibibliothek",
  "browseBody": "Jeder Upload, Chat-Anhang und jede KI-erzeugte Datei liegt hier – bereit zum Suchen und Wiederverwenden.",
  "browseAction": "Erste Datei hochladen",
  "filteredBody": "Keine Dateien passen zu diesen Filtern.",
  "filteredAction": "Filter zurücksetzen",
  "incomingBody": "Dateien aus Outlook, Nextcloud oder OpenCloud erscheinen hier, damit du sie vor dem Ablegen prüfen kannst.",
  "incomingAction": "Integrationen einrichten",
  "generatedBody": "Bilder, Video und Audio, die du im Chat erstellst, werden hier gespeichert, damit du sie wiederfinden und erneut herunterladen kannst.",
  "generatedAction": "Chat starten",
  "groupBody": "Diese Gruppe enthält noch keine Dateien. Füge Dateien hinzu, um sie gemeinsam durchsuchbar zu machen.",
  "groupAction": "Dateien hinzufügen"
},
"toast": {
  "uploaded": "{name} hochgeladen. | {count} Dateien hochgeladen.",
  "uploadFailed": "{name} konnte nicht hochgeladen werden. {reason}",
  "tooLarge": "{name} ist zu groß (max. {limit}).",
  "unsupported": "{type}-Dateien werden nicht unterstützt.",
  "vectorizeStart": "{name} wird KI-durchsuchbar gemacht…",
  "vectorizeDone": "{name} ist jetzt KI-durchsuchbar.",
  "vectorizeFailed": "{name} konnte nicht verarbeitet werden.",
  "revectorizedBulk": "{count} Dateien aktualisiert.",
  "moved": "{count} Dateien nach {group} verschoben.",
  "filed": "{count} Dateien in {group} abgelegt.",
  "deleted": "{count} Dateien gelöscht.",
  "incomingKept": "{count} Dateien in {group} behalten.",
  "incomingDismissed": "{count} Dateien verworfen.",
  "shareCreated": "Freigabelink in die Zwischenablage kopiert.",
  "shareRevoked": "Freigabe für {name} deaktiviert.",
  "downloadPreparing": "{count} Dateien werden zum Download vorbereitet…",
  "genericError": "Etwas ist schiefgelaufen. {reason}",
  "undo": "Rückgängig"
},
"confirm": {
  "deleteTitle": "{count} Dateien löschen?",
  "deleteBody": "Ihr KI-durchsuchbarer Inhalt wird ebenfalls entfernt. Das kann nicht rückgängig gemacht werden.",
  "deleteConfirm": "Löschen",
  "dismissTitle": "{count} Dateien verwerfen?",
  "dismissBody": "Sie werden nicht zu deiner Bibliothek hinzugefügt. Du kannst sie später erneut aus der Quelle importieren.",
  "dismissConfirm": "Verwerfen"
},
"incoming": {
  "title": "Eingang",
  "subtitle": "Dateien, die für deine Wissensbasis eingeliefert wurden – prüfen, dann behalten oder verwerfen.",
  "keep": "Behalten",
  "keepAll": "Alle behalten",
  "assignGroup": "Gruppe zuweisen",
  "dismiss": "Verwerfen",
  "open": "Öffnen",
  "retry": "Erneut versuchen",
  "provenance": "von {source} · {time}"
},
"generated": {
  "title": "Erzeugt",
  "subtitle": "Bilder, Video und Audio aus deinen Chats.",
  "openInChat": "Im Chat öffnen",
  "download": "Herunterladen"
},
"explainer": {
  "text": "Alles, was du hochlädst oder erstellst, liegt hier. Als „KI-durchsuchbar“ markierte Dateien werden deiner Wissensbasis hinzugefügt, damit der Assistent sie nutzen kann.",
  "dismiss": "Verstanden",
  "reopen": "Was ist das?"
}
```

```jsonc
// merge into "fileSelection": { ... }
"helper": "Angehängte Dateien werden für diese Nachricht an die KI gesendet. Mit ✅ markierte Dateien sind außerdem in all deinen Chats durchsuchbar.",
"searchYourFiles": "Deine Dateien durchsuchen",
"typeAll": "Alle",
"typeDocs": "Dok.",
"typeImages": "Bilder",
"typeVideo": "Video",
"typeAudio": "Audio",
"recent": "Zuletzt",
"allFilesFiltered": "Alle Dateien",
"attachCount": "{count} Datei anhängen | {count} Dateien anhängen",
"noRecents": "Wähle eine zuletzt verwendete Datei oder lade eine neue für diesen Chat hoch.",
"noResults": "Keine Dateien gefunden. Andere Suche versuchen oder neue Datei hochladen."
```

```jsonc
// merge into "fileMention": { ... }
"header": "Tippen, um eine Datei für die KI zu finden"
```

### A.3 Spanish — `es.json`

```jsonc
// merge into "files": { ... }
"terms": {
  "vectorized": "consultable por IA",
  "knowledgeGroup": "grupo de conocimiento",
  "incoming": "entrantes",
  "generated": "generado por IA",
  "notApplicable": "no aplicable"
},
"tabBrowse": "Explorar",
"tabIncoming": "Entrantes",
"tabGenerated": "Generados",
"incomingBadge": "{count} nuevos",
"source": {
  "web_upload": "Subida",
  "chat_attachment": "Chat",
  "outlook": "Outlook",
  "nextcloud": "Nextcloud",
  "opencloud": "OpenCloud",
  "whatsapp": "WhatsApp",
  "widget": "Widget",
  "api": "API",
  "generated": "Generado por IA"
},
"vectorState": {
  "vectorized": "Consultable por IA",
  "vectorizedDetail": "Consultable por IA · {group} · {count} fragmentos",
  "processing": "Procesando…",
  "none": "No consultable",
  "notApplicable": "No aplicable",
  "failed": "Falló"
},
"filter": {
  "source": "Origen",
  "group": "Grupo",
  "type": "Tipo",
  "vectorized": "Consultable",
  "date": "Fecha",
  "clear": "Quitar filtros",
  "typeAll": "Todos los tipos",
  "typeDocs": "Documentos",
  "typeImages": "Imágenes",
  "typeVideo": "Vídeo",
  "typeAudio": "Audio",
  "vectorizedYes": "Consultable por IA",
  "vectorizedNo": "No consultable",
  "noGroup": "Sin grupo"
},
"help": {
  "vectorized": "Cuando un archivo es consultable por IA, su contenido se añade a tu base de conocimiento para que el asistente pueda usarlo al responderte.",
  "group": "Los grupos de conocimiento agrupan archivos. Cuando la IA busca en un grupo, puede usar todos sus archivos.",
  "source": "De dónde viene este archivo: una subida, un chat, Outlook, Nextcloud, OpenCloud, WhatsApp, el widget, la API o generado por IA.",
  "incoming": "Archivos que otras apps han enviado para tu base de conocimiento. Revísalos y consérvalos o descártalos.",
  "typeFilter": "Mostrar solo un tipo de archivo: documentos, imágenes, vídeo o audio.",
  "vectorizedFilter": "Filtrar según si los archivos son consultables por IA.",
  "reVectorize": "Volver a leer este archivo y actualizar lo que la IA sabe de él. Úsalo tras editarlo o si falló.",
  "moveGroup": "Mover archivos a otro grupo de conocimiento. Esto cambia en qué grupo los busca la IA; no elimina nada.",
  "storage": "Cuánto almacenamiento has usado. El contenido generado por IA también cuenta y se muestra por separado."
},
"empty": {
  "browseTitle": "Tu biblioteca de archivos",
  "browseBody": "Aquí están todas tus subidas, adjuntos de chat y archivos generados por IA, listos para buscar y reutilizar.",
  "browseAction": "Sube tu primer archivo",
  "filteredBody": "Ningún archivo coincide con estos filtros.",
  "filteredAction": "Quitar filtros",
  "incomingBody": "Los archivos enviados desde Outlook, Nextcloud u OpenCloud aparecen aquí para que los revises antes de archivarlos.",
  "incomingAction": "Configurar integraciones",
  "generatedBody": "Las imágenes, el vídeo y el audio que creas en el chat se guardan aquí para que puedas encontrarlos y descargarlos de nuevo.",
  "generatedAction": "Iniciar un chat",
  "groupBody": "Este grupo aún no tiene archivos. Añade archivos para hacerlos consultables juntos.",
  "groupAction": "Añadir archivos"
},
"toast": {
  "uploaded": "{name} subido. | {count} archivos subidos.",
  "uploadFailed": "No se pudo subir {name}. {reason}",
  "tooLarge": "{name} es demasiado grande (máx. {limit}).",
  "unsupported": "Los archivos {type} no son compatibles.",
  "vectorizeStart": "Haciendo {name} consultable por IA…",
  "vectorizeDone": "{name} ya es consultable por IA.",
  "vectorizeFailed": "No se pudo procesar {name}.",
  "revectorizedBulk": "{count} archivos actualizados.",
  "moved": "{count} archivos movidos a {group}.",
  "filed": "{count} archivos archivados en {group}.",
  "deleted": "{count} archivos eliminados.",
  "incomingKept": "{count} archivos conservados en {group}.",
  "incomingDismissed": "{count} archivos descartados.",
  "shareCreated": "Enlace para compartir copiado al portapapeles.",
  "shareRevoked": "Se desactivó el uso compartido de {name}.",
  "downloadPreparing": "Preparando {count} archivos para descargar…",
  "genericError": "Algo salió mal. {reason}",
  "undo": "Deshacer"
},
"confirm": {
  "deleteTitle": "¿Eliminar {count} archivos?",
  "deleteBody": "También se elimina su contenido consultable por IA. Esto no se puede deshacer.",
  "deleteConfirm": "Eliminar",
  "dismissTitle": "¿Descartar {count} archivos?",
  "dismissBody": "No se añadirán a tu biblioteca. Puedes volver a importarlos más tarde desde el origen.",
  "dismissConfirm": "Descartar"
},
"incoming": {
  "title": "Entrantes",
  "subtitle": "Archivos enviados para tu base de conocimiento: revísalos y consérvalos o descártalos.",
  "keep": "Conservar",
  "keepAll": "Conservar todo",
  "assignGroup": "Asignar grupo",
  "dismiss": "Descartar",
  "open": "Abrir",
  "retry": "Reintentar",
  "provenance": "de {source} · {time}"
},
"generated": {
  "title": "Generados",
  "subtitle": "Imágenes, vídeo y audio creados en tus chats.",
  "openInChat": "Abrir en el chat",
  "download": "Descargar"
},
"explainer": {
  "text": "Todo lo que subes o creas está aquí. Los archivos marcados como «consultables por IA» se añaden a tu base de conocimiento para que el asistente pueda usarlos.",
  "dismiss": "Entendido",
  "reopen": "¿Qué es esto?"
}
```

```jsonc
// merge into "fileSelection": { ... }
"helper": "Los archivos adjuntos se envían a la IA para este mensaje. Los archivos marcados con ✅ también son consultables en todos tus chats.",
"searchYourFiles": "Buscar en tus archivos",
"typeAll": "Todos",
"typeDocs": "Doc.",
"typeImages": "Imágenes",
"typeVideo": "Vídeo",
"typeAudio": "Audio",
"recent": "Recientes",
"allFilesFiltered": "Todos los archivos",
"attachCount": "Adjuntar {count} archivo | Adjuntar {count} archivos",
"noRecents": "Elige un archivo reciente o sube uno nuevo para usar en este chat.",
"noResults": "No hay archivos. Prueba otra búsqueda o sube uno nuevo."
```

```jsonc
// merge into "fileMention": { ... }
"header": "Escribe para encontrar un archivo que dar a la IA"
```

### A.4 Turkish — `tr.json`

```jsonc
// merge into "files": { ... }
"terms": {
  "vectorized": "yapay zekâ ile aranabilir",
  "knowledgeGroup": "bilgi grubu",
  "incoming": "gelen",
  "generated": "yapay zekâ üretimi",
  "notApplicable": "uygulanamaz"
},
"tabBrowse": "Gözat",
"tabIncoming": "Gelen",
"tabGenerated": "Üretilen",
"incomingBadge": "{count} yeni",
"source": {
  "web_upload": "Yükleme",
  "chat_attachment": "Sohbet",
  "outlook": "Outlook",
  "nextcloud": "Nextcloud",
  "opencloud": "OpenCloud",
  "whatsapp": "WhatsApp",
  "widget": "Widget",
  "api": "API",
  "generated": "Yapay zekâ üretimi"
},
"vectorState": {
  "vectorized": "Yapay zekâ ile aranabilir",
  "vectorizedDetail": "Yapay zekâ ile aranabilir · {group} · {count} parça",
  "processing": "İşleniyor…",
  "none": "Aranamaz",
  "notApplicable": "Uygulanamaz",
  "failed": "Başarısız"
},
"filter": {
  "source": "Kaynak",
  "group": "Grup",
  "type": "Tür",
  "vectorized": "Aranabilir",
  "date": "Tarih",
  "clear": "Filtreleri temizle",
  "typeAll": "Tüm türler",
  "typeDocs": "Belgeler",
  "typeImages": "Görseller",
  "typeVideo": "Video",
  "typeAudio": "Ses",
  "vectorizedYes": "Yapay zekâ ile aranabilir",
  "vectorizedNo": "Aranamaz",
  "noGroup": "Grup yok"
},
"help": {
  "vectorized": "Bir dosya yapay zekâ ile aranabilir olduğunda içeriği bilgi tabanınıza eklenir; böylece asistan yanıt verirken bunu kullanabilir.",
  "group": "Bilgi grupları dosyaları bir araya getirir. Yapay zekâ bir grupta arama yaptığında içindeki tüm dosyaları kullanabilir.",
  "source": "Bu dosyanın nereden geldiği — yükleme, sohbet, Outlook, Nextcloud, OpenCloud, WhatsApp, widget, API veya yapay zekâ üretimi.",
  "incoming": "Diğer uygulamaların bilgi tabanınız için gönderdiği dosyalar. İnceleyin, sonra saklayın veya çıkarın.",
  "typeFilter": "Yalnızca tek bir dosya türünü göster — belgeler, görseller, video veya ses.",
  "vectorizedFilter": "Dosyaların yapay zekâ ile aranabilir olup olmadığına göre filtrele.",
  "reVectorize": "Bu dosyayı yeniden okuyup yapay zekânın ondan öğrendiklerini tazele. Düzenledikten sonra veya başarısız olduğunda kullan.",
  "moveGroup": "Dosyaları başka bir bilgi grubuna taşı. Bu, yapay zekânın onları hangi grupta aradığını değiştirir; hiçbir şey silinmez.",
  "storage": "Depolamanızın ne kadarını kullandığınız. Yapay zekâ üretimi içerik de sayılır ve ayrı gösterilir."
},
"empty": {
  "browseTitle": "Dosya kitaplığınız",
  "browseBody": "Her yükleme, sohbet eki ve yapay zekâ üretimi dosya burada; aramaya ve yeniden kullanmaya hazır.",
  "browseAction": "İlk dosyanızı yükleyin",
  "filteredBody": "Bu filtrelere uyan dosya yok.",
  "filteredAction": "Filtreleri temizle",
  "incomingBody": "Outlook, Nextcloud veya OpenCloud'dan gönderilen dosyalar, dosyalamadan önce incelemeniz için burada görünür.",
  "incomingAction": "Entegrasyonları ayarla",
  "generatedBody": "Sohbette oluşturduğunuz görseller, video ve ses, tekrar bulup indirebilmeniz için burada saklanır.",
  "generatedAction": "Sohbet başlat",
  "groupBody": "Bu grupta henüz dosya yok. Birlikte aranabilir olmaları için dosya ekleyin.",
  "groupAction": "Dosya ekle"
},
"toast": {
  "uploaded": "{name} yüklendi. | {count} dosya yüklendi.",
  "uploadFailed": "{name} yüklenemedi. {reason}",
  "tooLarge": "{name} çok büyük (en fazla {limit}).",
  "unsupported": "{type} dosyaları desteklenmiyor.",
  "vectorizeStart": "{name} yapay zekâ ile aranabilir yapılıyor…",
  "vectorizeDone": "{name} artık yapay zekâ ile aranabilir.",
  "vectorizeFailed": "{name} işlenemedi.",
  "revectorizedBulk": "{count} dosya tazelendi.",
  "moved": "{count} dosya {group} grubuna taşındı.",
  "filed": "{count} dosya {group} grubuna eklendi.",
  "deleted": "{count} dosya silindi.",
  "incomingKept": "{count} dosya {group} grubunda saklandı.",
  "incomingDismissed": "{count} dosya çıkarıldı.",
  "shareCreated": "Paylaşım bağlantısı panoya kopyalandı.",
  "shareRevoked": "{name} için paylaşım kapatıldı.",
  "downloadPreparing": "{count} dosya indirme için hazırlanıyor…",
  "genericError": "Bir şeyler ters gitti. {reason}",
  "undo": "Geri al"
},
"confirm": {
  "deleteTitle": "{count} dosya silinsin mi?",
  "deleteBody": "Yapay zekâ ile aranabilir içerikleri de kaldırılır. Bu geri alınamaz.",
  "deleteConfirm": "Sil",
  "dismissTitle": "{count} dosya çıkarılsın mı?",
  "dismissBody": "Kitaplığınıza eklenmezler. Daha sonra kaynaktan yeniden içe aktarabilirsiniz.",
  "dismissConfirm": "Çıkar"
},
"incoming": {
  "title": "Gelen",
  "subtitle": "Bilgi tabanınız için gönderilen dosyalar — inceleyin, sonra saklayın veya çıkarın.",
  "keep": "Sakla",
  "keepAll": "Tümünü sakla",
  "assignGroup": "Grup ata",
  "dismiss": "Çıkar",
  "open": "Aç",
  "retry": "Yeniden dene",
  "provenance": "{source} · {time}"
},
"generated": {
  "title": "Üretilen",
  "subtitle": "Sohbetlerinizde oluşturulan görseller, video ve ses.",
  "openInChat": "Sohbette aç",
  "download": "İndir"
},
"explainer": {
  "text": "Yüklediğiniz veya oluşturduğunuz her şey burada. „Yapay zekâ ile aranabilir“ olarak işaretli dosyalar bilgi tabanınıza eklenir; böylece asistan bunları kullanabilir.",
  "dismiss": "Anladım",
  "reopen": "Bu nedir?"
}
```

```jsonc
// merge into "fileSelection": { ... }
"helper": "Eklenen dosyalar bu mesaj için yapay zekâya gönderilir. ✅ ile işaretli dosyalar ayrıca tüm sohbetlerinizde aranabilir.",
"searchYourFiles": "Dosyalarınızda ara",
"typeAll": "Tümü",
"typeDocs": "Belge",
"typeImages": "Görsel",
"typeVideo": "Video",
"typeAudio": "Ses",
"recent": "Son kullanılan",
"allFilesFiltered": "Tüm dosyalar",
"attachCount": "{count} dosya ekle | {count} dosya ekle",
"noRecents": "Bu sohbette kullanmak için son kullanılan bir dosya seçin veya yeni bir tane yükleyin.",
"noResults": "Dosya yok. Başka bir arama deneyin veya yeni dosya yükleyin."
```

```jsonc
// merge into "fileMention": { ... }
"header": "Yapay zekâya verecek bir dosya bulmak için yazın"
```

### A.5 Key-parity checklist (Sprint H gate)

- Every key in A.1 has a counterpart in A.2–A.4 (same path). The Sprint H
  locale-parity check fails the build if any path is missing in any locale.
- No flat legacy duplicate remains for a string that now lives under
  `files.filter.*` / `files.empty.*` / `files.vectorState.*` / `files.toast.*`.
- Interpolation params (`{name}`, `{count}`, `{group}`, `{limit}`, `{type}`,
  `{reason}`, `{source}`, `{time}`) appear identically across all four locales.
- Plural pipe `|` present in `files.toast.uploaded`, `fileSelection.attachCount`
  (both arms) in every locale.
