# Goal 1 — Synaplan files into the cloud

Plan of record: [`00_master_plan.md`](./00_master_plan.md) (decisions 11–12,
37, 43).
The other direction — Synaplan reading files from the cloud for a run — is
[`07_files_into_runs.md`](./07_files_into_runs.md).

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
(`BPLATFORMINSTANCES.BSECRETHASH`), so it cannot prove a call to the app with
that secret. It signs every notification with its own **notify key**
instead: an Ed25519 key pair made with the existing `FederationSigner` and
kept encrypted in `BCONFIG` (`EncryptionService`), the way
`FederationIdentityStore` keeps the federation key. The app gets the public
half with its instance credential (`GET instances/self` → `notify_keys`). A
notification carries only an id; the app verifies it locally before it calls
Synaplan, then fetches everything with its own credential.

```text
 Synaplan                                           Cloud app (inbound endpoint)
 ────────                                           ─────────────────────────────
 Copy to cloud → ledger row (queued), ticket in Redis (5 min, single use)
 POST <delivery URL> {type: "delivery", id,
   instance_id, ts, kid, sig} ────────────────────► check sig, instance, ts, seen ids
                                                    (any check fails → 401, no call back)
                                                    POST /api/v1/platform-links/deliveries/{id}/claim
                                         ◄───────── (instance credential)
 GETDEL ticket → claim answer (§4: platform uid,
   name, mime, size, target, on_conflict,
   one-time download path) ────────────────────────►
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
  file." A sweep sets rows still `queued` after 10 minutes to
  `BSTATE = failed` with `BERRORCODE = unconfirmed` (C8). A late result
  callback may turn exactly such a row into `delivered` and tells the person;
  no other `failed` row changes again.
- **Single use** — the ticket is read with `GETDEL` (same as link codes,
  `RedisService::getAndDelete()`), expires after 5 minutes, and only its own
  instance may claim it (C4).
- **Wire format.** `POST <delivery URL>` with `Content-Type:
  application/json` and the body `{type, id, instance_id, ts, kid, sig}`:
  `type` one of the four below; `id` the ledger id as a string; `instance_id`
  the receiving instance's id (`BINSTANCEID`); `ts` Unix seconds (integer);
  `kid` = `FederationSigner::fingerprint()` of the public key; `sig` made by
  `FederationSigner::signBody()` over `FederationSigner::canonical()` of the
  other five fields. Keys and signatures are `ed25519:` plus unpadded
  base64url, as the signer writes them. Every app verifies with the shared
  test vectors `backend/tests/Fixtures/cloud-apps-contract/notify-vectors.json`
  (valid, wrong key, wrong instance, stale, tampered field) in its own
  language — PHP for Nextcloud and ownCloud.online, Go for OpenCloud — so a
  canonical-JSON difference fails a unit test, not production. The app
  answers: for `delivery`, `200` with the result body (the body of the
  result call below) when it finished the copy inside the request, else
  `202` and the result call later; for `pickup`, `202` (the pickup calls
  carry the outcome, `07` §4); for `watches_changed` and `identity_revoked`,
  `204`; when any check fails, `401`.
- **Signed, and checked before any call back.** The app
  answers `401` and calls nobody unless the signature verifies with a key from
  its cached `notify_keys`, `instance_id` is its own, `ts` is within five
  minutes of its clock and `(type, id)` is not among the ids it saw in the
  last ten minutes. Rejected requests are rate-limited per source address and
  summed up in one log line per minute, not logged one by one. The app
  refreshes its keys on its own schedule — hourly, on admin save, at start —
  never because a request names an unknown `kid`; otherwise a forger could
  trigger exactly the calls this rule prevents. Rotation
  (`app:platform-links:rotate-notify-key`) lists the new key at once, signs
  with it from the next hour on and drops the old key after 24 hours (C20).
- **One endpoint per app** receives every Synaplan notification:
  `{type: delivery | pickup | watches_changed | identity_revoked, id}`. All
  four types are signed and checked the same way; the app then fetches with
  its own credential. A `pickup` is this protocol in reverse: Synaplan asks
  for files and the app uploads them into the ticket
  ([`07`](./07_files_into_runs.md) §4).
- **Two origins per instance.** The registered **host** (`BHOST`) is the
  address people's browsers use: redirect URIs and **Open in …** links live
  on it. The **delivery URL** (`BDELIVERYURL`, set by the app through `PATCH
  instances/self`) is the address Synaplan's servers call. They may differ —
  inside Docker, and in production setups where Synaplan reaches the cloud
  through an internal name.
- **Delivery-origin rule.** Synaplan calls only the delivery URL, and accepts
  it when one of these holds:
  1. https, and its hostname equals the registered host's hostname (any
     port), and `SsrfGuard` passes;
  2. https or http, and its hostname is in the instance's approved hostnames
     (`BAPPROVEDHOSTS`, set by a Synaplan admin in the approval dialog,
     `02` §6.2) — private addresses allowed, loopback and link-local never;
  3. development only (`RedirectUriPolicy::isDev()`: `APP_ENV` is `dev` or
     `test`): http or https to a hostname in the shared dev list
     `localhost`, `127.0.0.1`, `::1`, `nextcloud`, `owncloud`, `cloud-fixture`,
     `host.docker.internal` — one constant used by `RedirectUriPolicy`,
     registration and the notifier, so the three never disagree.
  Self-signed TLS is never accepted; development uses http to the app's
  service port (`10` §0).
- **Reachability probe.** When the delivery URL is saved, Synaplan sends one
  unsigned `POST {type: "ping"}` with a 5-second budget; any HTTP answer
  (the app answers `401` to an unsigned body) counts as reachable, a
  connection or TLS error as not. The result is stored (`BREACHABLE`) and
  shown on the instance row as "Synaplan can reach this cloud: yes / no".
- **Paths, not addresses.** Every location Synaplan hands to an app for a
  server-to-server call — the download in a claim answer, the upload target of
  a pickup — is a **path** (`/api/v1/…`) that the app joins to the Synaplan
  address it is configured with (Nextcloud's `synaplan_url`). Synaplan never
  builds such a URL from `APP_URL`, which inside Docker is not reachable from
  the cloud.

---

## 3. Where the file lands

| Target | Used by | Folder | Name |
| ------ | ------- | ------ | ---- |
| **Kind folder** (default) | Ways 1 and 3 | `Synaplan/Documents`, `Synaplan/Images`, `Synaplan/Audio`, `Synaplan/Video`, `Synaplan/Calendar` in the person's home — the mapping the apps already use (`MediaController::KIND_FOLDERS` in Nextcloud, `kindFolder()` in OpenCloud) | Original name |
| **My Synaplan folder** | Ways 1 and 3 when the person chose one | The folder picked in the cloud's personal settings ("Save files from Synaplan to") with the native folder picker; kind sub-folders below it | Original name |
| **Next to the original** | Way 4 | Parent folder of the watched file (`files[0].folder_id` of the run payload, `04` §C.2), resolved by folder id in the watch owner's view | Task-defined, e.g. `<stem>.en.<ext>` |
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
| `PlatformDestinationProvider` (`id(): 'platform'`) | `backend/src/Service/Destination/` beside `WebDavDestinationProvider` | Params: `identity_id`; optional `target` = `{kind: kind_folder \| personal_folder \| next_to \| folder_id, ref?: string}` (`ref` is the remote folder id for `next_to` and `folder_id`); `on_conflict` = `rename` (default) \| `replace_own` |
| `DestinationResult::pending()` | `backend/src/Service/Destination/DestinationResult.php` | Only `platform` returns it; other providers unchanged |
| Send response | `FileSendController` (existing shape `{success, destination, reference, context}`) | Delivered: `200 {success: true, destination: "platform", reference: "<remote path>", context: {delivery_id, state: "delivered", open_url}}`. Pending: `202 {success: true, destination: "platform", reference: null, context: {delivery_id, state: "pending"}}`. Failure: the existing error answer with a `DestinationFailureCode` (§6) |
| `DestinationFailureCode` | `backend/src/Service/Destination/DestinationFailureCode.php` | Gains `forbidden` and `unconfirmed` (§6) |
| Copy ledger `BCLOUDDELIVERIES` | new table (schema ask recorded) | `BID`, `BOWNERID`, `BFILEID`, `BIDENTITYID`, `BTARGETKIND`, `BTARGETREF`, `BONCONFLICT`, `BSTATE` (`queued` / `delivered` / `failed`), `BREMOTEPATH`, `BREMOTEID`, `BOPENURL`, `BERRORCODE`, `BRUNID` (nullable), `BWATCHID` (nullable), `BCREATED`, `BFINISHED`. Raw idempotent SQL (Galera rule 2) |
| Claim and result endpoints | `PlatformLinkController` family | Instance credential; scope tests prove they cannot reach anything else (C5). Shapes in the table below |
| Send menu | `frontend/src/components/files/FilePushMenu.vue` | Linked clouds whose identity is `active`, whose app reports `deliveries` and whose instance is reachable, listed by app name and host above WebDAV connections; the line under each says where it lands ("→ Synaplan/Documents"). A cloud that fails any of the three is not listed (C21); its Apps card says why |
| Result toast | same | "Copied to Nextcloud › Synaplan/Documents/Q3 plan.docx" + **Open in Nextcloud** (private link from `open_url`) |
| "Sent to" on the file | Library file detail | Ledger rows for that file: where, when, state; answers U7 "where did it go" |
| Task step | `SaveToFolderRunner`, `RequestedFolderDelivery` | Accept a `platform` target; `target: {kind: "next_to", ref: {from: "trigger", field: "files.0.folder_id"}}` gives "next to the original" |
| Wording | `files.push.*` in five locales | `en` "Send to cloud" → "Copy to cloud" to match the other four locales |

| Method | Path | Auth | Request → answer |
| ------ | ---- | ---- | ---------------- |
| `POST` | `/api/v1/platform-links/deliveries/{id}/claim` | instance | — → `200 {id, external_id, name, mime, size, target: {kind, ref}, on_conflict, download_path, expires_at}`; `download_path` is a one-time path valid 5 minutes. Errors: `404 unknown_delivery`, `403 wrong_instance`, `409 already_claimed`, `410 expired`; more than 120 failed claims a minute per instance → `429` |
| `GET` | `{download_path}` | instance | — → the file bytes once; a second call `410` |
| `POST` | `/api/v1/platform-links/deliveries/{id}/result` | instance | `{status: delivered \| failed, remote_path?, remote_id?, open_url?, error_code?}` → `200 {state}`; idempotent — the same result twice answers the same state |

---

## 5. App pieces

| Piece | Nextcloud | ownCloud.online | OpenCloud |
| ----- | --------- | --------------- | --------- |
| Inbound endpoint | Public route `api/v1/inbound` (`#[PublicPage]`, `#[NoCSRFRequired]`, `#[AnonRateLimit]`), registered as delivery URL through `instances/self`; verifies every notification before anything else (§2) | Same pattern; the per-address limit as a counter in the app's cache | `/api/synaplan/inbound` behind an unprotected proxy route (CF-S3); the same checks in the Go service |
| Claim + download + write | `IRootFolder::getUserFolder($uid)` (pattern of `MediaController::save`) | Same | CS3 upload as the user via service account / impersonation (CF-S3); the binary-save code replaces the text-only `SaveToSpace` |
| Notify the person | Notifications app: "Synaplan copied Q3 plan.docx to Synaplan/Documents" with a link (CF-S5) | Notifications (CF-S4) | User notifications (CF-S1) |
| Personal setting "Save files from Synaplan to" | Personal settings + file picker | New personal settings page (CF1.1) | `accountExtension` (CF-S1) |
| **New → From Synaplan…** | New-file menu entry → picker of the person's Synaplan files (`GET /api/v1/files`, Generated first) → write into the open folder | New-file menu plugin (CF-S4) | Create-menu extension (CF-S1) |
| Save to Files in the app chat | Every generated kind (documents, audio, calendar added) | Same | Binary save (images, audio, Office) |
| Own-write guard (for CF5) | Remember remote ids written for Synaplan for 10 minutes; ignore their events | Same | Same |
| `identity_revoked` | Delete the person's stored key; the next action shows the Connect card (or "turned off") | Same | Drop the cached token state; the next action shows the consent card |
| Delivery URL | Admin field "Address Synaplan uses to reach this Nextcloud" (empty = the URL of `api/v1/inbound` on Nextcloud's own address; in the dev stack `http://nextcloud/index.php/apps/synaplan_integration/api/v1/inbound`, because the empty default resolves to `localhost:8081`, which the backend cannot reach) | Same field (dev: `http://owncloud/index.php/apps/oco_synaplan/api/v1/inbound`) | `SYNAPLAN_DELIVERY_URL` on the Go service (`http://host.docker.internal:9106/api/synaplan/inbound` in dev) |

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

- A notification carries no data, only an id, and is signed with Synaplan's
  notify key. The app checks it before it calls Synaplan (§2): a forged,
  replayed, stale or misaddressed notification ends at the inbound endpoint
  with `401` — no claim, no download, no log line per request (C20).
- The claim works once, within 5 minutes, only for its instance (C4). Failed
  claims (unknown, used or expired ids) are counted per instance; above 120
  in a minute Synaplan answers `429`, so a faulty app cannot hammer it
  either.
- The app writes only into the storage of the platform user the identity
  names, and refuses when that user or identity is unknown locally.
- The one-time download path is valid for 5 minutes and answers once.
- 100 MB cap, same as the WebDAV destination.
- Each copy is a ledger row and an audit event under the owner.

---

## 9. Not in v1

- **Pull mode** for clouds Synaplan cannot reach: the app would poll
  `GET /api/v1/platform-links/deliveries?state=queued` from a background job.
  CF6.2. Way 2 covers these clouds meanwhile.
- Automatic mirroring of every generated file into the cloud.
- Choosing a cloud folder from inside Synaplan.
