# Status — Collabora Integration

Plan of record: `00_master_plan.md`. Sibling plan for the Synaplan side of
office documents: `../20260902-office-docs/`.

## Epics

| Epic / step | Branch / repo | State | Notes |
| ----------- | ------------- | ----- | ----- |
| 0.1 — WOPI host endpoints | `feat/wopi-host` | planned | Needs office-docs A0 (sidecar) and a public editor hostname per cluster |
| 0.2 — Editor view + "Open in editor" | `feat/office-editor-view` | planned | `useCollaboraFrame.ts` is the single postMessage seam |
| 1.1 — Tool-calling-transparent `/v1/chat/completions` | office-docs Phase T (T1–T7) | done in `feat/office-tools-v1` (uncommitted) | `ToolCallingChatProviderInterface` + `OpenAiGatewayToolLoop` exist. Collabora editor tools work after T3; MCP + web search after T4. Do not re-add them here. |
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

## Versions and contracts we depend on

Fill in when Epic 0 starts; re-verify on every Collabora bump.

| Item | Value |
| ---- | ----- |
| Collabora image tag + digest | — |
| postMessage IDs used | `App_LoadingStatus`, `Host_PostmessageReady`, `UI_Close`, `Doc_ModifiedStatus`, `Action_Save_Resp`, `Send_UNO_Command`, `Action_InsertGraphic`, `Insert_Button` |
| WOPI `CheckFileInfo` keys used | `UserPrivateInfo.AIProviderURL`, `.AIProviderAPIKey`, `.AIProviderModel`, `PostMessageOrigin`, `LastModifiedTime` |
| `coolwsd.xml` keys used | `ai.enabled`, `ai.api_url`, `ai.api_key`, `ai.model`, `ai.allow_user_settings`, `aliasgroup1`, `net.post_allow.host`, `lok_allow`, `experimental_features` |
| `cool.js` surface used | `cool.callRemote`, `cool.document.on*` |
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
- Synaplan: `OpenAICompatibleController` (`/v1/chat/completions`, `/v1/models`)
  forwards only model/temperature/max_tokens/stream and string content — no
  `tools`; `ChatProviderInterface` has no tool calling;
  `AI/Messages/Tools/GatewayToolLoop.php` (Anthropic gateway) has the loop
  pattern; MCP connector layer exists (`AI/Messages/Mcp/*`).
