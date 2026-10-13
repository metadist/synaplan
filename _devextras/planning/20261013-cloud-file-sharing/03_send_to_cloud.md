# Goal 1 — Synaplan files into the cloud

Plan of record: [`00_master_plan.md`](./00_master_plan.md) (decisions 11–12).

## 1. Four ways a file reaches the cloud

| # | Where the person is | What they do | Path | Works when Synaplan cannot reach the cloud |
| - | ------------------- | ------------ | ---- | ------------------------------------------ |
| 1 | Synaplan Library (Generated, Files, Workspace) | **Copy to cloud → Nextcloud · files.example.org** | Notify-then-fetch copy (§2) | No — honest failure, points to way 2 |
| 2 | Cloud Files app, inside a folder | **New → From Synaplan…**, picks files | The app downloads with the person's credential and writes into the open folder | **Yes** |
| 3 | The app's own chat (Nextcloud / ownCloud.online research chat, OpenCloud chat from CF1.5) | **Save to Files** on any generated result | Existing `media#save` (Nextcloud, ownCloud.online), extended to every kind; OpenCloud gains binary saves | **Yes** |
| 4 | A watched folder's task (CF5) | Nothing — the task's last step saves "next to the original" or into a chosen folder | Notify-then-fetch copy with a task target | No — the run fails with a sentence; the result stays in Synaplan → Generated |

Today: way 1 exists only through a **WebDAV folder** connection (base URL,
user name, app password; `WebDavDestinationProvider`). That stays as the path
for clouds without the app. Way 3 exists for images and video in Nextcloud and
ownCloud.online and for text in OpenCloud. Ways 2 and 4 are new.

---

## 2. Protocol — notify, then fetch

Synaplan stores only a hash of each instance secret
(`BPLATFORMINSTANCES.BSECRETHASH`), so it cannot sign a call to the app. It
does not need to: the call carries nothing but an id, and the app fetches
everything with its own credential.

```text
 Synaplan                                           Cloud app (inbound endpoint)
 ────────                                           ─────────────────────────────
 Copy to cloud → ledger row (queued), ticket in Redis (5 min, single use)
 POST <delivery URL> {type: "delivery", id} ──────► 
                                                    POST /api/v1/platform-links/deliveries/{id}/claim
                                         ◄───────── (instance credential)
 GETDEL ticket → {platform uid, name, mime, size,
   target, on_conflict, one-time download URL} ───►
                                                    download bytes, resolve the platform user,
                                                    write per target and conflict rule
                         ◄───────────────────────── 200 {status: delivered, remote_path, remote_id, open_url}
 ledger → delivered                                 (also POST …/deliveries/{id}/result — idempotent)
 DestinationResult::success(path, {open_url})
```

Rules:

- **Budget 30 s** for the notify request. Most copies finish inside it, so the
  person gets a synchronous answer. Slow writes finish through the result
  callback; the ledger is the truth.
- **New outcome `DestinationResult::pending()`** — used only by the
  `platform` provider when the budget runs out. The API answers 202; the UI
  says "Still copying to Nextcloud — you'll see it under *Sent to* on this
  file." A sweep fails rows still `queued` after 10 minutes with
  `unconfirmed` (C8). A late callback still records success and tells the
  person.
- **Single use** — the ticket is read with `GETDEL` (same as link codes,
  `RedisService::getAndDelete()`), expires after 5 minutes, and only its own
  instance may claim it (C4).
- **One endpoint per app** receives every Synaplan notification:
  `{type: delivery | watches_changed | identity_revoked, id}`. All three
  types work the same way — the app fetches with its own credential.
- **Reachability.** Synaplan calls only the delivery URL registered for the
  instance, on the instance's host, over https (local-dev exception as in
  `WebDavClient::allowsInsecureLocal()`). `SsrfGuard` applies unless a
  Synaplan admin approved the instance on a private address (decision 11).
  The instance row shows "Synaplan can reach this cloud: yes / no", tested when
  the URL is saved.

---

## 3. Where the file lands

| Target | Used by | Folder | Name |
| ------ | ------- | ------ | ---- |
| **Kind folder** (default) | Ways 1 and 3 | `Synaplan/Documents`, `Synaplan/Images`, `Synaplan/Audio`, `Synaplan/Video`, `Synaplan/Calendar` in the person's home — the mapping the apps already use (`MediaController::KIND_FOLDERS` in Nextcloud, `kindFolder()` in OpenCloud) | Original name |
| **My Synaplan folder** | Ways 1 and 3 when the person chose one | The folder picked in the cloud's personal settings ("Save files from Synaplan to") with the native folder picker; kind sub-folders below it | Original name |
| **Next to the original** | Way 4 | Parent folder of the watched file, resolved by folder id in the watch owner's view | Task-defined, e.g. `<stem>.en.<ext>` |
| **Chosen folder** | Way 4 | A folder id picked in the watch dialog (native picker) | Task-defined |

Conflicts:

- Default **rename**: `name (2).ext`, as `WebDavDestinationProvider` does.
- Task outputs **replace only files Synaplan created itself** for the same
  watch and source file (the ledger keeps the remote id). A person's own file
  with the same name is never overwritten; the new file is renamed instead.
- Synaplan never shows or asks for a typed path.

---

## 4. Synaplan pieces

| Piece | Where | Note |
| ----- | ----- | ---- |
| `PlatformDestinationProvider` (`id(): 'platform'`) | `backend/src/Service/Destination/` beside `WebDavDestinationProvider` | Params: `identity_id`, optional `target` (`kind_folder` / `personal_folder` / `next_to` / `folder_id`), `on_conflict` |
| `DestinationResult::pending()` | `backend/src/Service/Destination/DestinationResult.php` | Only `platform` returns it; other providers unchanged |
| Copy ledger `BCLOUDDELIVERIES` | new table (schema ask recorded) | `BID`, `BOWNERID`, `BFILEID`, `BIDENTITYID`, `BTARGETKIND`, `BTARGETREF`, `BONCONFLICT`, `BSTATE` (`queued` / `delivered` / `failed`), `BREMOTEPATH`, `BREMOTEID`, `BOPENURL`, `BERRORCODE`, `BRUNID` (nullable), `BWATCHID` (nullable), `BCREATED`, `BFINISHED`. Raw idempotent SQL (Galera rule 2) |
| Claim and result endpoints | `PlatformLinkController` family | Instance credential; scope tests prove they cannot reach anything else (C5) |
| Send menu | `frontend/src/components/files/FilePushMenu.vue` | Linked clouds listed by app name and host, above WebDAV connections; the line under each says where it lands ("→ Synaplan/Documents") |
| Result toast | same | "Copied to Nextcloud › Synaplan/Documents/Q3 plan.docx" + **Open in Nextcloud** (private link from `open_url`) |
| "Sent to" on the file | Library file detail | Ledger rows for that file: where, when, state; answers U7 "where did it go" |
| Task step | `SaveToFolderRunner`, `RequestedFolderDelivery` | Accept a `platform` target; `{from: "trigger", field: "file.folder_id"}` gives "next to the original" |
| Wording | `files.push.*` in five locales | `en` "Send to cloud" → "Copy to cloud" to match the other four locales |

---

## 5. App pieces

| Piece | Nextcloud | ownCloud.online | OpenCloud |
| ----- | --------- | --------------- | --------- |
| Inbound endpoint | Public route `api/v1/inbound` (`#[PublicPage]`, `#[NoCSRFRequired]`), registered as delivery URL through `instances/self` | Same pattern | `/api/synaplan/inbound` behind an unprotected proxy route (CF-S3) |
| Claim + download + write | `IRootFolder::getUserFolder($uid)` (pattern of `MediaController::save`) | Same | CS3 upload as the user via service account / impersonation (CF-S3); the binary-save code replaces the text-only `SaveToSpace` |
| Notify the person | Notifications app: "Synaplan copied Q3 plan.docx to Synaplan/Documents" with a link (CF-S5) | Notifications (CF-S4) | User notifications (CF-S1) |
| Personal setting "Save files from Synaplan to" | Personal settings + file picker | New personal settings page (CF1.1) | `accountExtension` (CF-S1) |
| **New → From Synaplan…** | New-file menu entry → picker of the person's Synaplan files (`GET /api/v1/files`, Generated first) → write into the open folder | New-file menu plugin (CF-S4) | Create-menu extension (CF-S1) |
| Save to Files in the app chat | Every generated kind (documents, audio, calendar added) | Same | Binary save (images, audio, Office) |
| Own-write guard (for CF5) | Remember remote ids written for Synaplan for 10 minutes; ignore their events | Same | Same |

---

## 6. Failure copy

One sentence, what did not happen, the recovery (U8). Mapped from
`DestinationFailureCode` plus `unconfirmed`.

| Code | Sentence | Recovery |
| ---- | -------- | -------- |
| `unreachable` | "Synaplan could not reach files.example.org. Nothing was copied." | "In Nextcloud, open the folder and use New → From Synaplan." |
| `unauthorized` | "Nextcloud no longer accepts Synaplan for your account. Nothing was copied." | "Connect again in Nextcloud → Settings → Synaplan." |
| `not_found` | "The folder for Synaplan files is gone in Nextcloud. Nothing was copied." | "Pick a folder in Nextcloud → Settings → Synaplan." |
| `quota_exceeded` | "Your Nextcloud storage is full. Nothing was copied." | "Free some space, then copy again." |
| `too_large` | "This file is larger than 100 MB. Nothing was copied." | "Download it and upload it to Nextcloud yourself." |
| `conflict` | "Twenty files with this name already exist. Nothing was copied." | "Rename the file in Synaplan and copy again." |
| `forbidden` (task target) | "You cannot add files to Incoming-DE. The translation was not saved there." | "It is in Synaplan under Generated; change the folder of this watch." |
| `unconfirmed` | "Nextcloud did not confirm the copy within 10 minutes." | "Check Synaplan/Documents in Nextcloud; copy again if it is missing." |

---

## 7. Ten-second findability (U2)

| Who | Finds the copy through |
| --- | ---------------------- |
| The person, right after copying | Toast with **Open in Nextcloud** |
| The person, later, in the cloud | The fixed folder `Synaplan/<Kind>` (or their chosen folder) + the cloud notification |
| The person, later, in Synaplan | **Sent to** on the file in the Library |
| A task's owner | The run detail names the remote file and links it |

---

## 8. Security

- The notify call carries no secret and no data. A forged notify makes the
  app ask Synaplan for an unknown id and get a 404.
- The claim works once, within 5 minutes, only for its instance (C4).
- The app writes only into the storage of the platform user the identity
  names, and refuses when that user or identity is unknown locally.
- The one-time download URL is valid for 5 minutes.
- 100 MB cap, same as the WebDAV destination.
- Each copy is a ledger row and an audit event under the owner.

---

## 9. Not in v1

- **Pull mode** for clouds Synaplan cannot reach: the app would poll
  `GET /api/v1/platform-links/deliveries?state=queued` from a background job.
  CF6.2. Way 2 covers these clouds meanwhile.
- Automatic mirroring of every generated file into the cloud.
- Choosing a cloud folder from inside Synaplan.
