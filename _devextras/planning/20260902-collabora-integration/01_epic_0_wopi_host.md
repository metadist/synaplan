# Epic 0 — Synaplan WOPI host and "Open in editor"

Status: planned
Depends on: office-docs A0 (`collabora/code` sidecar, `OFFICE_CONVERT_URL`)
Prerequisite from ops: a public HTTPS hostname per cluster for the editor
(e.g. `office.<domain>`) terminated at the existing edge and proxied to the
node-local `collabora` container. Conversion does not need it; the editor
does, because the browser loads the editor iframe from it.

Collabora talks to the file owner through WOPI; Synaplan becomes the WOPI
host. Reference implementation: Nextcloud `richdocuments`.

## Step 0.1 — WOPI endpoints (backend)

Branch: `feat/wopi-host`

- `Controller/WopiController.php`, routes under `/api/v1/wopi/files/{id}`
  (thin; logic in `Service/Office/Wopi/*`):
  - `GET` → `CheckFileInfo`: `BaseFileName`, `Size`, `OwnerId`, `UserId`,
    `UserFriendlyName`, `UserCanWrite`, `LastModifiedTime` (ISO 8601 with
    fractional seconds), `PostMessageOrigin` (frontend origin),
    `EnableInsertRemoteImage`, `UserPrivateInfo` (Epic 1 fills it).
  - `GET /contents` → file bytes.
  - `POST /contents` → `PutFile`: compare `X-COOL-WOPI-Timestamp` with the
    stored `LastModifiedTime`; on mismatch answer 409 with
    `{"COOLStatusCode":1010}`; else write atomically, update `File`
    size/mtime, dispatch office-docs A1 thumbnail, invalidate the A2 PDF
    cache, and (Phase B) mark revision provenance `binary`.
- `Service/Office/Wopi/WopiTokenService`: `access_token` query param,
  short-lived (minutes, refreshed by the frontend), scoped to **one file and
  one user**, signed with the existing token secret infrastructure. It is
  the only credential on WOPI requests — never the user's API key.
- `Service/Office/Wopi/WopiDiscovery`: fetch and cache
  `{OFFICE_PUBLIC_URL}/hosting/discovery`; map extension → `urlsrc`.
- `GET /api/v1/files/{id}/edit-session` → `{ editorUrl, accessToken,
  accessTokenTtl, wopiSrc }` with OpenAPI → Zod.
- Env: `OFFICE_PUBLIC_URL` (browser-facing Collabora URL). Compose (dev and
  `synaplan-platform`): `aliasgroup1=<synaplan origin regex>` so Collabora
  accepts our WOPI host; `--o:ssl.termination=true` behind the edge.
- Feature flag: `officeEditorEnabled` in `/api/v1/config/features` — true
  only when `OFFICE_PUBLIC_URL` is set and discovery succeeded.
- Tests: token issue/verify/expiry/scope, `CheckFileInfo` shape,
  `PutFile` conflict, discovery parsing from a fixture XML.

## Step 0.2 — Editor view (frontend)

Branch: `feat/office-editor-view`

- Route `/files/:id/edit` → `views/DocumentEditView.vue`: Synaplan header
  (back, filename, Download / Download as PDF from office-docs A2), then a
  full-height `<iframe>`; the editor is opened by POSTing a hidden form with
  `access_token` (and `access_token_ttl`) to `editorUrl?WOPISrc=…` — the
  WOPI convention.
- postMessage plumbing in `composables/useCollaboraFrame.ts`: wait for
  `App_LoadingStatus` `Document_Loaded`, send `Host_PostmessageReady`,
  handle `UI_Close`, `Doc_ModifiedStatus`, `Action_Save_Resp`. This is the
  one place that knows message IDs — Epics 1–2 reuse it.
- "Open in editor" entry in the office-docs A2 menu (chat badges, Files
  tiles) when `configStore.features.officeEditorEnabled` and the extension
  is in the discovery map.
- Mobile: hide the entry in native shells (WebView + WOPI + third-party
  cookies is its own project); mark the seam `MOBILE-APP SEAM`.
- i18n (5 locales): `files.openInEditor`, `editor.saving`,
  `editor.saveConflict`, `editor.close`.

## Acceptance

Open a generated `.docx` from chat in the editor, edit, save; the file in
Synaplan changes, thumbnail and PDF export refresh, and the chat edit loop
still works on the saved file (with the Phase B resync rule once it
exists). Two browser tabs on the same file co-edit.

## Security

- WOPI endpoints accept only a valid short-lived, file-scoped token; log
  every `PutFile` with user and file id.
- Restrict which origins Collabora serves (`aliasgroup`) and which hosts may
  call `convert-to` (`net.post_allow.host`).
- The node-internal `collabora:9980` is never published; the editor hostname
  is the only public surface.
