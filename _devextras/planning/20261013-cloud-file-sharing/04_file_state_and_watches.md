# Goal 3 — file state, watched folders, automations

Plan of record: [`00_master_plan.md`](./00_master_plan.md) (decisions 13–21).

Three parts: **A** what the AI knows about a cloud file (the bird), **B**
watching files and folders (the bird with eyes), **C** what happens to what
arrives (automations). **D** covers clouds without the app; **E** sums up API
and schema.

---

## A. Searchable state — the bird

### A.1 States

State is *mine*: whether the file is searchable by AI for the person looking
at it. Only states that ask for attention or confirm success show a glyph;
"not searchable" shows nothing, so ordinary folders stay quiet.

| State | Glyph | Tooltip and `aria-label` (en) | Derived from |
| ----- | ----- | ----------------------------- | ------------ |
| Searchable | bird | "Searchable by AI — in “Nextcloud · Specs”" | `vector_state = vectorized`, etag current |
| Being added | bird + clock badge | "Being made searchable…" | `pending` |
| Changed, updating | bird + arrows badge | "Changed — Synaplan is updating it" | stale, and the file is kept up to date (watch or option) |
| Changed | bird + dot badge | "Changed since it was made searchable — open to update" | stale, not kept up to date |
| Could not | bird + alert badge | "Could not be made searchable — open to see why" | `failed` |
| Not searchable | — | — | missing, `none` |
| Watched | bird with eyes | Generated sentence, e.g. "Watched by Synaplan — new files are translated to English" | an active watch on this folder or file |
| Watch paused | bird with eyes, pause badge | "Watching paused — open to resume" | watch `paused` |
| Watch needs attention | bird with eyes, alert badge | "Watching stopped — open to see why" | watch `attention` |

### A.2 Glyph kit (decision 13)

- Two base glyphs — **bird** and **bird with eyes** — monochrome SVG at 16 and
  20 px, light and dark variants, plus four badges (clock, arrows, dot, alert,
  pause). Designed once, shipped in all three apps.
- The glyph is never the only signal: tooltip, `aria-label`, and the sidebar
  sentence carry the meaning; badges differ by shape, not by colour alone.
  WCAG AA (3:1 for icons) against the host's list background in both themes.
- White label: the runtime config (`GET /api/v1/config/runtime`) may carry
  replacement SVGs for both base glyphs (additive field). The OpenCloud app
  already fetches the operator's brand icon this way
  (`useSynaplanBird.ts`); the indicator follows the same route.
- One glyph per meaning across the three apps (U12, house icon rule).

### A.3 Data path (decision 14)

```text
 folder opens → app collects the visible rows (~50 ms) → one call:
 POST /api/v1/cloud/status   (person credential, scope `files`)
   { instance_id, items: [ { id: "5678", version: "a1b2c3" }, … up to 500 ] }
 ← { items: [ { id, state, knowledge_folder, file_id, kept_up_to_date } ],
     watches: [ { id, target_id, sentence, state } ] }      ← watches touching these rows
 cache per (id, version) for 60 s; invalidate after any Synaplan action
```

- Built on `check-stale` (`FileController::checkStale`,
  `FileRepository::findByUserSourceIds`), which already answers current /
  stale / missing per `(source, source_id, etag)`. The new call adds
  `vector_state`, the knowledge folder and watch coverage; `check-stale`
  itself also gains `vector_state` (additive, CF0.4).
- No per-row HTTP, no local mirror table in v1. A failed status call shows no
  glyphs and one quiet line in the sidebar ("Couldn't check Synaplan right
  now") — never a toast per folder.
- Folder rows get their eyes from the app's watch cache (B.8), not from the
  status call.

### A.4 Rendering per platform

| | Nextcloud | ownCloud.online | OpenCloud |
| - | --------- | --------------- | --------- |
| Row glyph | Files action with inline rendering on each row, fed by the batcher; click opens the sidebar tab (CF-S5 checks 5 000-row folders) | `FileList` plugin decorates rows after render (CF-S4) | No row extension point known (CF-S1). v1: state in the sidebar panel and in the action label ("Searchable by AI — Update"); upstream request for a row indicator (CF4.5) |
| Current folder | List header for a watched folder: "Synaplan watches this folder — new files are translated to English · Details" (CF-S5) | Header above the list (CF-S4) | Sidebar panel of the folder |
| Sidebar | Files sidebar tab "Synaplan" (CF-S5: API across 30–34) | Detail tab "Synaplan" (CF-S4) | `sidebarPanel` "Synaplan" |

### A.5 Follow the source (decision 15)

A file made searchable is a copy of a cloud file. When the cloud file changes
or disappears, the copy follows.

| Event in the cloud | What Synaplan does | What the person sees |
| ------------------ | ------------------ | -------------------- |
| Content changed | Marks every searchable copy of that file from this cloud stale (existing `BSTALE`); a copy that is kept up to date is re-uploaded by its owner's next queue run | Bird + arrows → bird again; or bird + dot "Changed — open to update" |
| Renamed / moved | Updates the display name and path | Nothing changes visually |
| Deleted (moved to trash) | Removes the copy's vectors at once (out of answers), hides it from the Library, keeps the stored copy 7 days, then deletes it | Bird gone; Library no longer lists it |
| Restored within 7 days | Re-vectorizes the kept copy (no upload); watches see the file again | Bird back after a short "Being added" |
| Restored later | Treated as a new file for watches; single adds need **Make searchable** again | — |

- The app reports changes as **instance-level source events**
  (`POST /api/v1/platform-links/sources/events`): one batched call per minute
  with `{id, event, version, path}`. Synaplan filters to files it holds from
  this cloud; the instance never learns who indexed what.
- "Keep it up to date" is a checkbox in the **Make searchable** dialog,
  default **on**. Files inside a Keep-searchable watch are always kept up to
  date. Re-uploads run as the copy's owner (per-user or background key).
- Deletion takes the file out of answers at once; the dialog for **Make
  searchable** says so once ("If you delete the file here, Synaplan forgets it
  too"). The 7-day grace protects against a mistaken delete and against a
  faulty app reporting deletions in bulk; every source event is audited.

### A.6 The sidebar "Synaplan" — five questions on the open surface (U7)

```text
 File
 ┌─────────────────────────────────────────────────────────────┐
 │ [bird] Searchable by AI                                       │
 │        In your knowledge folder "Nextcloud · Specs"           │
 │        Added 13 Oct by you · kept up to date (Specs is watched)│
 │ [ Ask about this file ]  [ Remove from Synaplan ]  Open in Synaplan ↗ │
 │ Copied from Synaplan: from your chat "Q3 plan", 12 Oct ↗      │  ← only for copies (03)
 └─────────────────────────────────────────────────────────────┘

 Folder
 ┌─────────────────────────────────────────────────────────────┐
 │ [eyes] Watched by you since 13 Oct                            │
 │        New files are translated to English and saved in       │
 │        Incoming-EN. Runs on your Synaplan account.            │
 │        Everyone who can open this folder sees that it is watched.│
 │        Last: today 09:12 · 3 translated · 1 could not be read │
 │ [ Pause ]  [ Stop watching ]                Edit in Synaplan ↗ │
 └─────────────────────────────────────────────────────────────┘
```

| Question | Answer on the sidebar |
| -------- | --------------------- |
| Who owns this? | "Added by you" / "Watched by Ada" |
| Who else? | "Everyone who can open this folder sees that it is watched" / the knowledge folder name |
| What will it touch? | The watch sentence (reads new files here, writes translations to Incoming-EN, mails ada@…) |
| How do I stop it? | **Remove from Synaplan**, **Pause**, **Stop watching** — on the same surface (U3) |
| Where did it come from? | "Created in Nextcloud on 13 Oct" / "Copied from Synaplan from your chat …" |

The data comes from `GET /api/v1/cloud/files/{id}?instance_id=…` (person
credential, `files`): state, knowledge folder, covering watches, provenance
from the copy ledger.

### A.7 Cloud-side overview

Each app's own page (Nextcloud and ownCloud.online "Synaplan" page, OpenCloud
"Synaplan" app view) gains two lists beside the chat: **Watched folders**
(state, last activity, Pause / Stop / Open folder) and **Recently copied from
Synaplan**. This is the cloud-side ten-second path to every watch, without
browsing folder by folder (U2, U6).

---

## B. Watched folders — the bird with eyes

### B.1 What a watch is

A watch belongs to one person, looks at one folder (optionally with
sub-folders) or one file in one cloud, reacts to new files (optionally to
changed files), and does one thing: **keep them searchable** or **run a
task**. A folder may carry several watches; the eyes tooltip lists them.

| Field | Values |
| ----- | ------ |
| Owner | Synaplan user, through a cloud identity (or a WebDAV connection, D) |
| Target | Folder or file, by the platform's stable id; display path refreshed from events |
| Scope | This folder only (default) / with sub-folders |
| Reacts to | New files (default); changed files (default on for Keep searchable, off for tasks) |
| File types | Documents (PDF, Word, ODF, text, Markdown, presentations, spreadsheets) by default; images opt-in |
| Action | Keep searchable (knowledge folder) or run a task |
| State | active · paused (reason) · needs attention (reason) · stopped |

### B.2 States and reasons

| State | Reason codes | Sentence (en) | Way out |
| ----- | ------------ | ------------- | ------- |
| active | — | "Watching" | — |
| paused | `by_owner` | "Paused by you" | Resume |
| paused | `identity_off` | "Paused — Nextcloud is disconnected from your Synaplan account" | Connect / turn back on |
| paused | `platform_user_disabled` | "Paused — your Nextcloud account is disabled" | Resumes when re-enabled |
| paused | `permission` | "Paused — Synaplan needs your permission to watch folders" | `02` §4.2 card |
| attention | `target_gone` | "Stopped — the folder was deleted" | Stop watching |
| attention | `no_access` | "Stopped — you can no longer open this folder" | Stop watching |
| attention | `task_gone` | "Stopped — the task it ran was deleted" | Pick another task |
| attention | `policy_off` | "Stopped — your administrator turned off watching shared folders" | Ask the administrator, or stop watching |
| attention | `failing` | "Stopped after 5 failed files in a row — the last one: “…”" | Resume after fixing |

### B.3 Event sources (decision 18)

| | Nextcloud | ownCloud.online | OpenCloud |
| - | --------- | --------------- | --------- |
| Source | Node created / written / renamed / deleted events (CF-S5: trash, restore, group folders, external storage) | Files hooks or dispatcher events (CF-S4) | Event bus consumer: upload finished, moved, trashed, restored (CF-S3) |
| In the request | Only enqueue `(file id, kind)` — no HTTP while the person's upload is running | Same | — |
| Queue | Platform job list (`IJobList`), one deduplicated job per file | Same | Durable consumer cursor + in-process queue |
| Runner | Background job every minute — **needs system cron**; the admin screen warns when background jobs run in AJAX / webcron mode | Same | Go service goroutine |
| Unattended credential | Owner's per-user key from preferences | Same | Owner's key from the store (CF-S3) |

**Matching.** The job walks the file's parents to the storage root and
intersects their ids with the watched targets from the cache (B.8). Shares
keep the folder id, so a file dropped into a shared folder matches the
owner's watch. Type filters apply before anything is uploaded.

### B.4 Quiet period

A file is handled once it has been quiet for **60 seconds** (re-queued while
it keeps changing). Desktop clients that save repeatedly, and large uploads,
produce one run, not five.

### B.5 Reconciliation and backfill

- **Reconciliation** every hour by default (admin: 15 minutes to 24 hours):
  for each active watch the app lists the target in the owner's view and
  sends `(id, version)` pairs to `POST /api/v1/cloud/watches/{id}/reconcile`;
  Synaplan answers which ids to process (unknown to the ledger) and which to
  forget. This catches missed events, external storage without events, and
  time while the app was off. It closes the "nightly reconciliation" item of
  the Nextcloud hosting guide's roadmap.
- **Backfill** on creation: "Also the 7 files already in this folder" — on by
  default for Keep searchable, off for task presets. More than 200 files asks
  first: "Make 1 240 files searchable? This takes about an hour."
- **Resume** after a pause asks: "Also handle the 4 files that arrived while
  paused?" (default yes for Keep searchable, ask for tasks).

### B.6 Idempotency and loops (decision 19)

- The ledger `BCLOUDWATCHITEMS` has a unique key `(watch, file id, version)`.
  Events, reconciliation and retries all go through it; a second upload of the
  same version is answered "already handled" and starts nothing (C6).
- **Loop guard, three layers** (C7): the app ignores events for files it wrote
  for Synaplan in the last 10 minutes; Synaplan drops events for remote ids in
  its copy ledger; the Translate preset skips names that already carry its
  language suffix (`*.en.*`).
- Every item ends in `done`, `skipped` (with reason) or `failed` (with
  reason); a sweep fails items stuck in `running` for 30 minutes, like the
  stale-run reaper of the single-scheduler plan (C8).

### B.7 Limits and cost (C14)

- Per watch: 100 files per hour (Keep searchable: 500); per instance: 1 000.
  Beyond that, items wait and the row says "37 files waiting".
- Each file a task watch handles is one task run on the owner's plan; at the
  limit, items wait: "Your Synaplan limit for today is reached — 4 files wait
  until tomorrow."
- 100 MB per file; unsupported types are `skipped` with "This file type is not
  read".
- Summarize-and-mail sends at most 20 mails per watch per hour; more files
  wait for the next hour (the row says so).

### B.8 Shared folders and visibility (decision 21)

- A watch runs as its owner and on the owner's plan.
- The app keeps a cache of **all active watches of its instance**
  (`GET /api/v1/platform-links/watches`, instance credential: target id, owner
  display name, sentence, state). It refreshes on the `watches_changed` notify
  and every 10 minutes.
- Everyone who can open a watched folder sees the eyes and the sentence:
  "Ada's Synaplan watches this folder: new files are translated to English and
  saved in Incoming-EN. Only Ada can change this." Their files are processed;
  they deserve to know (U7).
- Only people who may edit the folder can create a watch on it. Admins can
  turn watches on shared folders off (app admin setting). The watch dialog
  tells the creator who will see it.

### B.9 Pause, resume, stop

Same three controls in the cloud sidebar, the app overview page (A.7) and the
Synaplan **Watched folders** row (U3). Stop asks once with the consequence:
"Synaplan stops watching Incoming-DE. Files and translations already made
stay where they are." Pausing never loses items: they are picked up on
resume (B.5).

---

## C. Automations — what happens to what arrives

### C.1 Presets (decision 16)

| Preset (watch dialog) | Kind | Steps (existing capabilities) | Output | Consequence sentence (en) |
| --------------------- | ---- | ----------------------------- | ------ | ------------------------- |
| **Keep them searchable** | Keep searchable | — (upload with `group_key`, `intake=direct`) | Knowledge folder "Nextcloud · Specs" (name editable) | "New and changed files become searchable by AI in your knowledge folder “Nextcloud · Specs”." |
| **Translate them** | Task | `extract_text` → `translate` (language) → `document_generation` (Word, ODF and PDF inputs → a new DOCX; Markdown and text stay Markdown and text) → `save_to_folder` (platform, next to the original or a chosen folder, name `<stem>.<lang>.<ext>`) | A new file in the cloud; the translation also stays in Synaplan → Generated | "Each new file is translated to English and saved next to it. The layout of the translation is simplified." |
| **Summarize them and mail me** | Task | `extract_text` → `summarize` ("4–5 sentences") → `email_me` (subject "Summary: {file.name}"; body: summary, **Open in Nextcloud**, **See this run in Synaplan**) | An HTML mail to the owner's Synaplan address | "For each new file you get a mail with 4–5 sentences to ada@example.org." |
| **Run my instruction** | Task | `chat` with the instruction, the file attached and `{file.name}`, `{file.path}`, `{file.link}` filled in → `email_me` (default) or `save_to_folder` or nothing | Mail, a file next to the original, or only the run history | "For each new file, Synaplan runs your instruction and mails you the answer." |
| **Use one of my tasks** | Task | The chosen task's own steps | As the task defines | "“Contract check” will run for every new file in Incoming-DE instead of when you start it." |

- Presets create the task **in Synaplan** through the watch call (`POST
  /api/v1/cloud/watches` with `preset` and its inputs); the app never needs
  the Saved Tasks API. The created task is an ordinary task: editable in the
  Steps editor, shareable as a template, visible under Tasks.
- "Use one of my tasks" lists tasks whose trigger is **Manual** (a task has one
  trigger); binding changes it to the file trigger and the consequence
  sentence says so.
- "Run my instruction" offers the person's **Shortcuts** as starting points;
  the instruction can be edited before saving.

### C.2 The `file_event` trigger and its payload

`SavedTask::TRIGGER_TYPES` gains `file_event`; `triggerConfig = {watch_id}`.
The run is queued like a webhook run (`RunSavedTaskCommand` with
`trigger_payload`), so steps read the file with the existing
`StepInputResolver` (`{ "from": "trigger", "field": "file.path" }`).

```json
{
  "file": {
    "event": "created",
    "name": "contract.docx",
    "path": "Incoming-DE/contract.docx",
    "folder": "Incoming-DE",
    "folder_id": "1234",
    "id": "5678",
    "version": "a1b2c3",
    "mime": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "size": 48213,
    "modified": "2026-10-13T09:10:00Z",
    "link": "https://files.example.org/f/5678",
    "cloud": "Nextcloud",
    "host": "files.example.org",
    "synaplan_file_id": 991
  },
  "watch": { "id": 17, "name": "Incoming-DE" }
}
```

Task card and trigger row, one generated sentence (UX contract §4.8):
"When a file arrives in **Nextcloud › Incoming-DE** → translate it to English
and save it next to the original · **Runs on its own (as you)** · Last: today
09:12 · ok".

### C.3 Inputs, outputs, approvals

- Files a task watch reads are **working copies** (decision 20): stored
  hidden (`BEPHEMERAL`), kept 7 days for retries, then removed by the
  ephemeral reaper; never listed in the Library. Keep-searchable files skip
  Incoming (`intake=direct`, CF0.3) and live in their knowledge folder.
- Outputs into the cloud go through the copy protocol (`03`), with the "next
  to the original" target and the replace-only-our-own-files rule.
- Approvals stay available through the Steps editor ("Ask me before this
  step"). Presets do not ask by default — the person chose the action in the
  dialog — except when a preset writes into a folder the owner does not own:
  then the dialog offers "Ask me before saving into a shared folder" (on).

### C.4 Failure copy per file

| Case | Run result (one sentence, what did not happen) |
| ---- | ---------------------------------------------- |
| No readable text (scanned image PDF) | "contract-scan.pdf has no readable text. Nothing was translated." |
| Target folder not writable | "You cannot add files to Incoming-EN. The translation was not saved there; it is in Synaplan under Generated." |
| Mail could not be sent | "The summary of report.pdf is ready, but the mail could not be sent. Read it in this run." |
| Plan limit reached | "Your Synaplan limit for today is reached. report.pdf waits until tomorrow." |
| Cloud copy unconfirmed | "Nextcloud did not confirm the copy of contract.en.docx. Check Incoming-EN." |

Failures also show on the watch row and in the cloud sidebar; the owner gets
a cloud notification for a failed file (not for every success — the output
file or the mail is the success signal).

### C.5 Where automations are managed — the answer to the open question

| Job | Where | Why |
| --- | ----- | --- |
| Start watching | **In the cloud**: right-click → **Watch with Synaplan…** | The person is looking at the folder; the native picker knows it; no path is typed |
| See that something is watched | **Both**: eyes + sidebar in the cloud; **Tasks → Watched folders** in Synaplan | Ten-second findability from either place (U2) |
| Pause, resume, stop | **Both**, same three controls | Undo is a click where the person is (U3) |
| Change the preset or the language | In the cloud dialog (re-open) | Small change, same place it was made |
| Edit the steps, add approvals, share as template | **In Synaplan** (Steps editor) | One task editor, not three (U12) |
| Runs, failures, retries, history | **In Synaplan** (run history), linked from the cloud | One runs ledger with terminal states |
| Approvals | **In Synaplan** (Approvals inbox, existing) | One inbox |

In Synaplan, **Tasks → Watched folders** sits beside **Saved tasks** and
**Watched pages** (`SavedTasksOverview.vue`). Each row: cloud icon, folder,
cloud and host, the sentence, on/off, last activity and counts, **Open in
Nextcloud**, **Edit task**, **Stop watching**. Keep-searchable watches also
show a "Kept in sync with Nextcloud › Specs" badge on their knowledge folder
in the Library, with **Stop syncing**. Empty state: "Watch a folder from
Nextcloud, ownCloud.online or OpenCloud: right-click it and choose Watch with
Synaplan." + **Show my connected clouds** (U5).

Not in v1: an automation editor inside the clouds; a Nextcloud Flow operation
(possible later for admins who already script Flow, CF6.3).

---

## D. Without the app — the WebDAV feeder (CF6.1)

For a WebDAV folder connection (Nextcloud, ownCloud, OpenCloud presets or any
WebDAV server) Synaplan itself scans the folder on a schedule. This is the
roadmap's "Folder watch (scheduled scan, seen-state per file) — same shape as
URL watch".

- `WebDavClient` gains `list()` (PROPFIND depth 1 with etag, size, modified,
  and the server's file id where offered) and `get()`.
- Same watch model with a connection instead of an identity, same ledger, same
  presets; outputs through the WebDAV destination.
- Runs in the single scheduler's `hourly` lane (or `tasks` for shorter
  intervals). Honest copy: "Checked every hour."
- Watches can be created in Synaplan here, because Synaplan can browse the
  folder through the connection.

---

## E. API and schema

### E.1 Endpoints

| Method | Path | Auth / scope | Purpose |
| ------ | ---- | ------------ | ------- |
| `POST` | `/api/v1/cloud/status` | person, `files` | Batched state for a folder view (A.3) |
| `GET` | `/api/v1/cloud/files/{id}` | person, `files` | Sidebar detail (A.6) |
| `GET` | `/api/v1/cloud/watches` | person, `cloud:watch` | My watches on an instance |
| `POST` | `/api/v1/cloud/watches` | person, `cloud:watch` | Create with `preset` and inputs; creates the task |
| `GET` | `/api/v1/cloud/watches/options` | person, `cloud:watch` | Manual tasks, Shortcuts, knowledge folders, languages for the dialog |
| `PATCH` | `/api/v1/cloud/watches/{id}` | person, `cloud:watch` | Pause, resume, change preset inputs and filters |
| `DELETE` | `/api/v1/cloud/watches/{id}` | person, `cloud:watch` | Stop watching |
| `POST` | `/api/v1/cloud/watches/{id}/files` | person or background key, `cloud:watch` | Upload one file version (multipart + id, version, path, mime, event) |
| `POST` | `/api/v1/cloud/watches/{id}/reconcile` | person or background key, `cloud:watch` | Compare a listing with the ledger |
| `POST` | `/api/v1/platform-links/sources/events` | instance | Changed / moved / deleted files from this cloud (A.5) |
| `GET` | `/api/v1/platform-links/watches` | instance | Descriptions of active watches on this cloud (B.8) |
| `POST` | `/api/v1/files/check-stale` | existing | Adds `vector_state` per item |
| `POST` | `/api/v1/files/upload` | existing | Adds `intake=direct` (skip Incoming when a knowledge folder is named) |

Synaplan web uses session-authenticated equivalents for the **Watched
folders** tab (list, pause, resume, stop, edit) under the existing Saved
Tasks controller family.

### E.2 Schema (ask recorded)

| Table | Columns |
| ----- | ------- |
| `BCLOUDWATCHES` | `BID`, `BOWNERID`, `BIDENTITYID` (nullable), `BCONNECTIONID` (nullable, D), `BTARGETID`, `BTARGETKIND` (`folder` / `file`), `BTARGETPATH`, `BRECURSIVE`, `BEVENTS` (JSON), `BFILTER` (JSON), `BACTION` (`searchable` / `task`), `BPRESET`, `BGROUPKEY` (nullable), `BTASKID` (nullable), `BSTATE`, `BREASON`, `BCOUNTS` (JSON), `BLASTEVENTAT`, `BLASTRUNAT`, `BLASTSTATUS`, `BCREATEDIN` (`cloud` / `synaplan`), `BCREATED`, `BUPDATED` |
| `BCLOUDWATCHITEMS` | `BID`, `BWATCHID`, `BSOURCEID`, `BVERSION`, `BSTATE` (`waiting` / `running` / `done` / `skipped` / `failed`), `BREASON`, `BFILEID`, `BRUNID`, `BDELIVERYID`, `BCREATED`, `BUPDATED`; unique (`BWATCHID`, `BSOURCEID`, `BVERSION`) |
| `BCLOUDDELIVERIES` | see [`03`](./03_send_to_cloud.md) §4 |
| `BFILES` | `BSOURCEDELETEDAT BIGINT NULL` — set by a delete source event: the file is hidden from the Library and has no vectors; the ephemeral reaper removes it after 7 days; a restore event clears it and re-vectorizes |
| `BSAVEDTASKS` | no column change; `BTRIGGERTYPE` accepts `file_event` |

Raw idempotent SQL; children (`BCLOUDWATCHITEMS`) are deleted before their
watch; no `ON DELETE CASCADE` assumed (Galera rules 2 and 3).
