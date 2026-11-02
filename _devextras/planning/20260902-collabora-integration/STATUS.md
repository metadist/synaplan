# Status — Collabora Integration

Plan of record: `00_master_plan.md`. Sibling plan for the Synaplan side of
office documents: `../2026-archive/20260902-office-docs/`.

Checked 2026-11-02 before Epic 0.1. Phase T and the convert sidecar are on
`main` (office-docs #1685). The local `collabora/code:26.04.5.2.1` service
was defined and not running; a separate Nextcloud CODE (`26.04.3.2.1`) is
already bound to host port 9980, so the Synaplan editor uses `127.0.0.1:9981`.
The Vultr openDesk Collabora is a partner installation (Epic 4). It cannot
call a WOPI host on this machine, and changing its alias group would affect
that suite, so it is not the test bed for Epic 0.

## Epics

| Epic / step | Branch / repo | State | Notes |
| ----------- | ------------- | ----- | ----- |
| 0.1 — WOPI host endpoints | `feat/wopi-host` | in review | CheckFileInfo, GetFile, PutFile, locks, edit-session. Editor flag is `officeEditorEnabled` (both URLs set). Discovery is fetched from `OFFICE_CONVERT_URL` and rewritten onto `OFFICE_PUBLIC_URL`. |
| 0.2 — Editor view + "Open in editor" | `feat/office-editor-view` | planned | `useCollaboraFrame.ts` is the single postMessage seam |
| 1.1 — Tool-calling-transparent `/v1/chat/completions` | office-docs Phase T (T1–T7) | done (#1685) | `ToolCallingChatProviderInterface` + `OpenAiGatewayToolLoop` are on `main`. Do not re-add them here. |
| 1.2 — Sidebar provisioning (WOPI `UserPrivateInfo`, `coolwsd.xml`, per user) | `feat/collabora-ai-provisioning` | planned | Per-user gateway API key minted lazily |
| 1.3 — Knowledge / memories / metering tag through the gateway | — | planned | Each flag-gated, default off |
| 2.1 — `synaplan-collabora` repo + build + auth | new repo | planned | Extension framework is experimental (manifest 0.1) |
| 2.2 — Writer / Calc / Impress adapters (`cool.callRemote`) | new repo | planned | Nightly integration test against a real CODE container |
| 2.3 — Synaplan panel UI | new repo | planned | Same component hosted in Epic 0's editor view (2.4 fallback) |
| 2.4 — postMessage fallback | `synaplan` | planned | `Send_UNO_Command`, `Action_InsertGraphic`; no selection read |
| 3.1 — MCP verification spike | — | planned | Go/no-go recorded here |
| 3.2 — Collabora MCP connector | — | planned | |
| 3.3 — `DocumentEdit` runner + task template | — | planned | |
| 4.1 — Provider provisioning per platform | integration repos | planned | Nextcloud first (`richdocuments` `UserPrivateInfo` hook) |
| 4.2 — Extension distribution | `synaplan-collabora` | planned | |
| 4.3 — Platform-native AI hooks | integration repos | planned | Nextcloud Task Processing provider |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-09-02 | Ride Collabora 26.04's built-in AI Assistant first (Synaplan = OpenAI-compatible provider); own extension second; never patch Collabora. |
| 2026-09-02 | The Synaplan WOPI host ("Open in editor") belongs to this plan as Epic 0; the office-docs plan only links to it. |
| 2026-09-02 | Tool calling in chat providers is one shared building block for office-docs Phase B and Epic 1.1; whichever lands first records it in both STATUS files. |
| 2026-11-02 | Dev editor is the pinned CODE sidecar on `127.0.0.1:9981`, not the Nextcloud container on `:9980` and not the Vultr openDesk Collabora. A WOPI save does not append a document revision: the previous binary hash stops matching, so the next chat edit re-imports. |

## Versions and contracts we depend on

Re-verify on every Collabora bump.

| Item | Value |
| ---- | ----- |
| Collabora image tag + digest | `collabora/code:26.04.5.2.1` `sha256:c7415b963e32eb0986a562583f154d36d3d7a461e1ddfb660a95e87094328acb` |
| postMessage IDs used | `App_LoadingStatus`, `Host_PostmessageReady`, `UI_Close`, `Doc_ModifiedStatus`, `Action_Save_Resp`, `Send_UNO_Command`, `Action_InsertGraphic`, `Insert_Button` (0.2 has not wired them yet) |
| WOPI `CheckFileInfo` keys used | `BaseFileName`, `Size`, `OwnerId`, `UserId`, `UserFriendlyName`, `UserCanWrite`, `LastModifiedTime`, `PostMessageOrigin`, `EnableInsertRemoteImage`, `SupportsLocks`, `SupportsGetLock`, `SupportsUpdate`, `UserCanNotWriteRelative`. `UserPrivateInfo` waits for Epic 1. |
| `coolwsd.xml` keys used | `aliasgroup1=http://backend:80`, `ssl.enable=false`, `ssl.termination=false`, `net.frame_ancestors` for the dev frontend origin |
| `cool.js` surface used | `cool.callRemote`, `cool.document.on*` (Epic 2, not called yet) |
| MCP endpoint | — (Epic 3 spike) |

## Investigation baseline (2026-09-02)

- Collabora Online / CODE 26.04 (July 2026): built-in AI Assistant sidebar
  for Writer/Calc/Impress against any OpenAI-compatible `/v1/chat/completions`
  (+ `/v1/models`), document actions via model tool calling; configured in
  `coolwsd.xml <ai>`, WOPI `UserPrivateInfo`, or per user; off by default.
  Also an MCP endpoint for external clients. Upstream issue #15997 reports a
  tool/permission loop with some OpenAI-compatible proxies.
- iframe-hosted extension framework: `browser/dist/extensions/<id>/manifest.json`
  (0.1), `cool.js` with `cool.callRemote` (JS-UNO in the kit) and document
  event hooks; gated by `experimental_features`.
- postMessage API: `Insert_Button` (classic toolbar only), `Send_UNO_Command`
  (e.g. `.uno:InsertText`), `Action_InsertGraphic` (`lok_allow`),
  `CallPythonScript` (pyuno packages required); cannot read the selection.
- Synaplan on 2026-09-02 had no `tools` on `/v1/chat/completions`. That gap
  closed in #1685. The WOPI host was still absent on 2026-11-02.
