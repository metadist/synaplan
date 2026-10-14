# How cloud files reach a run — files, not paths

Plan of record: [`00_master_plan.md`](./00_master_plan.md) (decisions 27–32).
Added 2026-10-13 after review: "A pure path in a specific prompt has no
meaning. We need to be able to pick up files or file lists from the file
sharing app. The prompt must be able to fetch files and process those on
Synaplan automatically, even in the code sandbox with specific tools."

## 1. The rule

**A path alone does nothing.** Synaplan's steps — text, translation,
summary, file work in the sandbox, custom tools — work on files Synaplan
holds, never on a string that names a file somewhere else. In this plan a
path appears in exactly two roles:

| Role | Example | What turns it into something real |
| ---- | ------- | --------------------------------- |
| **Label** of a file the run already holds | "contract.docx in Nextcloud › Incoming-DE" | Nothing to do — the file is attached to the run; the label is provenance (U7), a link back, and the base for output names (`contract.en.docx`) |
| **Selector** for files the run should fetch | "all PDFs in Invoices/2026 changed this week" | A pickup (§4) resolves it in the cloud, as the person, inside what Synaplan may read (§5), and attaches the files before any processing step runs |

Everything a run processes is a Synaplan file (`BFILES`) **attached to the
run's message** (`Message::addFile()`), exactly like files a person attaches
in a chat. One attachment model, so every existing step sees them without new
plumbing (§6).

The earlier draft's `{file.path}` placeholders in "Run my instruction" are
withdrawn: the instruction now runs *on the attached files*; name and folder
are context, not input.

---

## 2. What a watch run receives

The watch dialog asks one question for task watches: **What should each run
get?**

| Choice | A run starts when | The run's attachments | Fits |
| ------ | ----------------- | --------------------- | ---- |
| **The new file** (default) | A file arrived or changed and stayed quiet for 60 s | That one file | Translate it, summarize it, extract fields from it |
| **New files together** | The collection window closes: 10 minutes after the first file (`BWINDOWSEC` default 600, editable 60 – 86 400), or at 50 files (`BMAXFILES`) | Every file that arrived in the window | "Merge this morning's CSV exports into one Excel", one digest mail for many files |
| **The whole folder** | Something in the folder changed, at most once per window (`BWINDOWSEC` default 3 600, editable 600 – 86 400) | Every file in the folder that passes the type filter, up to the run limit | "Rebuild the price list from all supplier sheets", "compare all versions" |

How the files arrive — always **pushed by the app**, so watches keep working
when Synaplan cannot reach the cloud (outbound HTTPS from the cloud only):

- **The new file:** the existing per-file upload (`04` §B.3).
- **New files together:** the app uploads each file as it settles; Synaplan
  collects them in the watch's open window (ledger rows `waiting`) and the
  scheduler's `tasks` lane starts one run when the window closes. Windowing
  lives in the core, not in three apps.
- **The whole folder:** the app sends a listing
  (`POST /api/v1/cloud/watches/{id}/snapshots`), Synaplan answers which
  versions it does not hold yet **from this cloud** — matched by (instance,
  remote id, version), C19 — the app uploads only those, then closes the
  snapshot; the run gets the new uploads plus the copies Synaplan already
  holds from this cloud. Unchanged files are never sent twice.

Every run also receives a short **"Files in this run"** block in its message
text — name, folder, cloud, change time per file — so the model can refer to
them and name results. The content is the attachments.

---

## 3. Fetching more files during a run — the `folder_fetch` step

Sometimes the files that trigger a run are not all the files it needs ("check
the new invoice against last month's", "summarize everything in Projects/Q3").
A new planner-visible capability does that, sibling of `url_fetch` and
`mcp_fetch`, mirror of `save_to_folder`:

| | `folder_fetch` |
| - | -------------- |
| Planner description | "Read files from one of the user's connected folders (Nextcloud, ownCloud.online, OpenCloud, a WebDAV folder) and attach them to this run" |
| Params | `channel` (a folder channel from the Connected channels list, as `save_to_folder` uses), `folder` (a folder inside that channel), `pattern` (e.g. `*.pdf`), `recursive`, `changed_since` (e.g. `7d`), `max_files`, `mode`: `list` (names, sizes, dates only) or `fetch` (the files) |
| Result | `fetch`: the files attached to the run's message + a listing; `list`: the listing as text and metadata |
| Class | Read. No approval inside what Synaplan may read (§5); a request outside it is refused with one sentence (§10) |
| Where | Planned in chat ("take the PDFs from my Nextcloud folder Invoices/2026 and make one table") and pinned in tasks |
| Card | "Read 12 files from Nextcloud › Invoices/2026" with the file chips — the provenance answer on the open surface (U7) |
| Transport | Linked clouds: a **pickup** through the app (§4). WebDAV folder connections: Synaplan reads directly with `WebDavClient::list()` / `get()` (added in CF3.6; the CF6.1 feeder reuses them) |

The Connected channels list the planner sees names, per cloud, the folders
Synaplan may read, so the model asks for something it can get.

---

## 4. Pickup protocol — Synaplan asks, the app delivers

Mirror of the copy protocol (`03` §2): the notification carries an id and
nothing else, signed with Synaplan's notify key, and the app verifies it
before it claims (C20).

```text
 Synaplan (run step folder_fetch)                    Cloud app (inbound endpoint)
 ────────────────────────────────                    ─────────────────────────────
 check selector against the person's read scope
 ledger row BCLOUDPICKUPS (queued), ticket in Redis (single use, 10 min)
 POST <delivery URL> {type: "pickup", id, …, sig} ──►
                                                     verify as for copies (03 §2), else 401
                                                     POST /api/v1/platform-links/pickups/{id}/claim
                                         ◄────────── (instance credential)
 → {platform uid, selector, read scope, limits, mode}
                                                     resolve the selector in that user's view,
                                                     inside the read scope; list matching files
                                                     POST …/pickups/{id}/listing  {items: [{id, version, name, folder, size, mime}]}
 → {upload: [ids Synaplan does not hold in that version]}
                                                     POST …/pickups/{id}/files  (multipart, one per file)
                                                     POST …/pickups/{id}/result {status, listed, sent, skipped[]}
 attach files to the run message → step returns
```

Rules:

- **Bound ticket.** The ticket names the person (identity), the selector and
  the read scope Synaplan checked. The claim works once (`GETDEL`); after it,
  the app may upload into that pickup until it reports the result or the 10
  minutes run out. It never chooses whose Synaplan receives the files. This
  is an instance operation constrained by a Synaplan-issued ticket, like
  claiming a copy — inside Wave 6 §0 #5, no new scheme (`02` §4).
- **Two checks.** Synaplan refuses a selector outside the read scope before it
  issues a ticket; the app re-checks against its own copy of the scope and
  reads as the platform user, so cloud permissions apply too.
- **Listing first.** Only versions Synaplan lacks are uploaded; files already
  held from this cloud in that version (searchable or picked up before) are
  reused. A held copy matches only by (instance, remote id, version) — a file
  with the same id from another cloud is uploaded, never reused (C19).
- **Inline, then background.** The app fulfils inside the notify request for
  up to 25 s and continues in a background job for the rest. The step waits
  up to **10 minutes** and shows progress on its card ("Reading files from
  Nextcloud… 12 of 52").
- **Partial is honest.** "Read 40 of 52 files from Nextcloud › Invoices; 12
  were larger than 100 MB and were left out." The next steps run with the 40
  files; the run says what it skipped (U8).
- **Unreachable cloud.** Pickups need Synaplan to reach the cloud, like copies.
  Watch runs do not (§2). A pull mode for both is v2 (CF6.2).
- **Organisation login works.** The upload rides on the ticket, so a chat in
  Synaplan web can read from OpenCloud without a stored key for that person.
  One shared account: absent (there is no person to read as).

Wire shapes (ids and versions are the cloud's strings, at most 255
characters, compared exactly as sent):

| Call | Request | Answer |
| ---- | ------- | ------ |
| `claim` | — | `200 {id, external_id, mode: list \| fetch, selector: {folder_id?, folder_path?, pattern?, changed_since?, recursive}, read_scope: {mode, folders: [{id, path}]}, limits: {max_files: 50, max_bytes: 209715200, max_file_bytes: 104857600}, expires_at}`; errors as for copy claims (`404`, `403 wrong_instance`, `409 already_claimed`, `410 expired`) |
| `listing` | `{items: [{id, version, name, folder, size, mime}]}` (≤ 500 per call, repeatable) | `{upload: [id…], reuse: [{id, file_id}], skip: [{id, reason: too_large \| type_not_read \| over_limit}]}` — `reuse` names copies of the same (instance, id, version) the person already has |
| `files` | multipart `file` + `id`, `version` — one file per call | `200 {file_id}`; an id not in the last `upload` list → `409` |
| `result` | `{status: done \| partial \| failed, listed, sent, skipped: [{id, name, reason}], error_code?}` | `200 {state}`; idempotent |

Uploads into a pickup need the instance credential of the instance that
claimed it, while the pickup is `claimed` and not expired — no second token.
`mode: list` ends after `listing` (no files, the step gets the names);
`mode: fetch` continues with `files`.

---

## 5. What Synaplan may read

Reading a person's cloud files is a permission the person controls **in the
cloud**, where the files are.

| Setting (cloud personal settings) | Synaplan may read |
| --------------------------------- | ----------------- |
| **Only what I watch** (default; `mode: watched`) | Watched folders and files |
| **Also folders I allow** (`mode: allowed`) | Plus folders added with right-click → **Let Synaplan read this folder** (listed in personal settings, removable there) |
| **All my files** (`mode: all`) | Everything the person can open; shown with a warning sentence |

- The app is authoritative and enforces it on every pickup. It reports changes
  to Synaplan (`PUT /api/v1/platform-links/read-scope`, instance call:
  `{external_id, mode, folders: [{id, path}]}`) so Synaplan can pre-check,
  tell the planner what is readable, and show it.
- Synaplan shows it on the cloud's Apps card: "Synaplan may read: the folders
  you watch, Invoices/2026, Specs."
- Admin cap in each app: "Synaplan may read at most: watched folders / folders
  people allow / all their files". Default: folders people allow.
- A refused request names the way out: "Synaplan may not read Invoices/2026 in
  your Nextcloud. Open the folder in Nextcloud and choose Let Synaplan read
  this folder." + **Open in Nextcloud** (private link).

---

## 6. Processing on Synaplan — one attachment model

Attached files reach every step through paths that exist today:

| Step | How it gets the files | Evidence |
| ---- | --------------------- | -------- |
| Text steps (`summarize`, `translate`, `chat`, `extract_text`) | `MessagePreProcessor` extracts every attachment (Tika, Vision, Whisper); text already extracted at upload is reused | `backend/src/Service/Message/MessagePreProcessor.php` |
| `file_analysis` | Reads the attached files (OCR, vision, document Q&A) | `FileAnalysisRunner` |
| **File work** (`code_run`) | The run's attachments are mounted by name into the sandbox; `params.inputFileIds` can name them explicitly; results written to `/out/` come back as Synaplan files | `CodeRunRunner` (`inputFilesFromIds`, message-attachment fallback, artefact ingest) |
| `document_combine`, `document_generation` | Use the files in the conversation | `DocumentCombineRunner`, `DocumentGenerationRunner` |
| Custom tools, MCP tools | Receive file content or links per their contract, with their usual read / write class | Tools track |
| `save_to_folder` | Takes attachments from earlier steps (`$nX.file`) and a folder channel; linked clouds join the folder channels (CF3) | `SaveToFolderRunner` |

**File work in watch runs** — what the sandbox gives automations:

- Images and tools are Secure Compute's pinned images: Python 3.12 with
  pandas, numpy, matplotlib, openpyxl, python-docx, python-pptx, pypdf,
  pillow; Node 22 with xlsx, docx, pdf-lib, sharp. LibreOffice is Compute v2.
  No network inside a run (egress off), no package installation, quotas per
  person (`COMPUTE_RUNS`, CPU seconds per day).
- File work exists only where the operator turned Compute on; otherwise the
  planner never offers it and the dialog never mentions it (U11).
- **Unattended file work needs consent.** Compute's policy is "unattended
  default approve". A watch task whose steps include file work shows one
  question when it is created: "This task does file work. Let it run without
  asking each time?" **Yes** sets the task's existing "allow unattended" flag
  (`SavedTask::BALLOWUNATTENDED`); **Ask me each time** leaves every run waiting
  in Approvals. Websites stay blocked either way.
- **Scripts read whatever is mounted.** A pinned file-work step must process
  every input file it finds, never a file name from the run it was tested on.
  The pinning step checks this and rejects a script that names a test file
  (test: replay the pinned task on a differently named file).
- **One working folder, one run at a time.** A watch whose file work keeps a
  persistent working folder (`useWorkspace`, Compute's
  `COMPUTE.WORKSPACES_ENABLED`) runs its files one after another, never in
  parallel (the sidecar would answer `workspace_busy`).

Results go back to the cloud through `save_to_folder` with the platform
target ("next to the original", or the watched folder for a multi-file run)
and the copy protocol (`03`).

---

## 7. Try it on a file, then automate

Instructions are not guessed once and then run blind on every file. The
"Run my instruction" preset follows the pattern Synaplan already has for
"Schedule this" (a chat turn saved as a task with pinned steps):

1. In the cloud, the watch dialog's **Run my instruction** asks for the
   instruction (typed, or a Shortcut) and offers **Try it on a file** (newest
   file in the folder preselected, any file pickable). With **New files
   together** or **The whole folder** it becomes **Try it on files** and takes
   several files, up to the run limit.
2. The app uploads that file with the person's own credential (the person is
   in the cloud UI, so this needs no pickup and works when Synaplan cannot
   reach the cloud) and asks Synaplan for a trial chat
   (`POST /api/v1/cloud/trials`: file, instruction, watched folder). Synaplan
   opens in a new tab on that chat, the file attached and the instruction
   sent. The person sees the real result — the answer, the file-work card,
   the produced files — and may refine it.
3. Under the result: **Do this for every new file in “Invoices”** (shown when
   the chat's files came from a cloud folder). It creates the watch and its
   task with exactly the steps of this turn
   (`POST /api/v1/cloud/watches/from-message/{id}`), asks what each run should
   get (§2) and the unattended file-work question when it applies (§6), and
   shows the watch sentence to confirm.
4. The app hears `watches_changed`; the folder in the cloud carries the eyes.

The ready-made presets (Translate, Summarize and mail me) ship as templates,
so they start watching at once; **Try it on a file** is offered for them too.
The same "Do this for every new file" button appears under any chat whose
attachments came from one cloud folder — a person can start from Synaplan as
well.

---

## 8. Limits

| Limit | Default | When exceeded |
| ----- | ------- | ------------- |
| Files per run (all three choices) | 50 | New files together: a second run takes the rest. Whole folder: refused before the run — "Incoming has 312 files; a run takes at most 50. Narrow the file types, or keep the folder searchable instead." |
| Bytes per run | 200 MB | Same handling |
| Bytes per file | 100 MB | File left out, named in the run |
| File work input | What Compute accepts per run | Checked before the run, refused with a sentence |
| Pickup wait | 10 minutes | Step fails or continues partial (§4) |
| Pickups per person | 20 per hour | "Synaplan already read many files from Nextcloud this hour. Try again at 10:00." |

Questions across a large folder belong to **Keep searchable** (the folder
becomes a knowledge folder and the AI searches it) — the limits steer there.

---

## 9. Security

- **Cloud files are untrusted input.** Watch tasks replay pinned steps, so
  text inside a file cannot add a step. `folder_fetch` reads only inside the
  read scope. The sandbox has no network. Anything that sends data out of
  Synaplan keeps its write class.
- The pickup ticket is single-use, expires after 10 minutes, belongs to one
  instance, one person and one checked selector (C16).
- Picked-up files are working copies (hidden, 7-day retention) unless the
  person keeps them; they never enter Incoming.
- Every pickup is a ledger row and an audit event under the person: what was
  asked, what was read, what was left out.

---

## 10. Failure copy

| Case | Sentence (one, with what did not happen) | Way out |
| ---- | ---------------------------------------- | ------- |
| Outside the read scope | "Synaplan may not read Invoices/2026 in your Nextcloud. Nothing was read." | "Open the folder in Nextcloud and choose Let Synaplan read this folder." |
| Folder not found | "Nextcloud has no folder Invoices/2026 that you can open. Nothing was read." | Check the name; **Open in Nextcloud** |
| Nothing matched | "No PDF in Invoices/2026 changed in the last 7 days. The next steps did not run." | — |
| Cloud unreachable | "Synaplan could not reach files.example.org. Nothing was read." | "Attach the files in the chat, or ask your administrator to let Synaplan reach Nextcloud." |
| Partial | "Read 40 of 52 files; 12 were larger than 100 MB and were left out." | — |
| Too many files | "Incoming has 312 files; a run takes at most 50. Nothing was read." | Narrow the types, or Keep searchable |
| File work needs approval | "Waiting for your approval to run file work on contract.docx." | Approvals inbox (existing) |
| File work off on this installation | — (the step and the dialog option are absent) | — |
| Pinned script names the test file (C17) | "This step uses the name of the file you tried it on (invoice-0912.pdf), so it would fail on other files. Change it to work on whatever files a run gets." | The Steps editor opens at that step |

---

## 11. API and schema

| Method | Path | Auth | Purpose |
| ------ | ---- | ---- | ------- |
| `POST` | `/api/v1/platform-links/pickups/{id}/claim` | instance | Read the ticket: platform uid, selector, scope, limits, mode (§4 shapes) |
| `POST` | `/api/v1/platform-links/pickups/{id}/listing` | instance (the claiming one) | Send the listing; answer = ids to upload, reuse, skip |
| `POST` | `/api/v1/platform-links/pickups/{id}/files` | instance (the claiming one, while claimed) | Upload one file into the pickup |
| `POST` | `/api/v1/platform-links/pickups/{id}/result` | instance (the claiming one) | Close: status, counts, skipped with reasons |
| `PUT` | `/api/v1/platform-links/read-scope` | instance | Report a person's read scope |
| `POST` | `/api/v1/cloud/watches/{id}/snapshots` | person / background key, `cloud:watch` | Whole-folder listing; answer = versions to upload |
| `POST` | `/api/v1/cloud/watches/{id}/snapshots/{sid}/close` | person / background key, `cloud:watch` | Start the whole-folder run |
| `POST` | `/api/v1/cloud/trials` | person, `cloud:watch` | Create the trial chat for "Try it on a file": uploaded file, instruction, watched folder; returns the chat URL |
| `POST` | `/api/v1/cloud/watches/from-message/{id}` | session only (API keys get `403`, `02` §4.1) | "Do this for every new file": watch + task from that turn's pinned steps |

| Change | Detail |
| ------ | ------ |
| `Capability::FolderFetch` (`folder_fetch`) | New runner beside `UrlFetchRunner` / `McpFetchRunner`; read class; hidden when no folder channel is connected |
| `SavedTaskRunner::run()` | Accepts the run's files and attaches them to the run message before processing |
| `BCLOUDPICKUPS` (schema ask) | `BID`, `BOWNERID`, `BIDENTITYID` / `BCONNECTIONID`, `BRUNID`, `BMESSAGEID`, `BMODE` (`list` / `fetch`), `BSELECTOR` (JSON), `BSTATE` (`queued` / `claimed` / `done` / `partial` / `failed`), `BLISTED`, `BSENT`, `BSKIPPED` (JSON), `BERRORCODE`, `BCREATED`, `BFINISHED` |
| `BCLOUDWATCHES` (schema ask, `04` §E.2) | created by CF5.1 with `BFILESET` (`new_file` / `together` / `whole_folder`), `BWINDOWSEC`, `BMAXFILES`; CF5.7 adds no column |
| `BEXTERNALIDENTITIES` (schema ask, `02` §9) | `BREADSCOPE` (JSON mirror of the app's setting) is added by CF2.2 with the other identity columns; CF3.6 only writes it |
