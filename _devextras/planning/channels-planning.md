# Channels — capability inventory and planning

**Status:** Investigation snapshot 2026-08-21, updated the same day after the channel sprint shipped on this branch (see §6). **Branch:** `cursor/channel-management-planning-3d6f`.

This document summarizes what the channel system can do TODAY — which channels can enrich prompts (IN) and which can receive results (OUT) — and what is missing to close the two headline gaps: writing meetings/reminders into connected calendars, and richer Microsoft 365 actions. The authoritative work breakdown for the M365 gaps lives in [`saved-task-workflows/10_m365_actions_and_destinations.md`](./saved-task-workflows/10_m365_actions_and_destinations.md) (Phase M); this file is the cross-channel overview.

---

## 1. Two channel systems, not one

1. **Conversation transports** — where a prompt arrives and the reply returns: Web chat, Widget, WhatsApp (Meta webhook in / Cloud API out), Email (`POST /api/v1/webhooks/email` in / SMTP reply out), the Anthropic-compatible Messages Gateway, and Synaplan acting as an **MCP server** for external hosts (`McpController`).
2. **Connected systems** (`BCONNECTIONS`, UI `/channels/connections`) — external accounts the multitask planner uses as data sources or delivery sinks. `PlannerChannelCatalog` maps each connection to a planner channel of kind `folder` / `calendar` / `mail` with a stable slug, injected into the planner prompt as `[CHANNELLIST]`. Registered connection types (`Connection::TYPES`): `generic`, `mailbox`, `mcp`, `webhook`, `caldav`, `m365`, `dropbox`, `webdav` — of which `generic`, `mcp`, `webhook` produce **no** planner channel (`kindForType` → null).

---

## 2. Capability matrix — connected systems

Verified 2026-08-21; test evidence in §5.

| Channel | IN — enrich prompt | OUT — save/send results | Status |
| ------- | ------------------ | ----------------------- | ------ |
| **Microsoft 365** | ✅ Live mail search + top-hit body fetch (`GraphMailboxSearcher`, `GraphClient`, delegated `Mail.Read`) | ❌ No calendar write, no send-as-user, no OneDrive | Mail-read only; OAuth scopes stop at `Mail.Read` (`MicrosoftOAuthConfig::SCOPES`) |
| **IMAP mailbox** (Email Automation accounts) | ✅ Live search (`ImapMailboxSearcher`, merged with M365 in `EmailSearchRunner`) | ➖ Department forwarding only (automation tool) | Search working |
| **Dropbox** | ❌ No file read/search | ✅ File upload via `save_to_folder` (`DropboxDestinationProvider`) | Working (tested live with a connected Dropbox) |
| **WebDAV / Nextcloud** | ❌ | ✅ File upload via `save_to_folder` (`WebDavDestinationProvider`) | Working |
| **CalDAV calendar** | ❌ No availability read | ⚠️ Event write implemented (`CalDavDestinationProvider`, idempotent UIDs) but only reachable via manual `POST /api/v1/files/{id}/send` with `destination=caldav` — the chat planner never triggers it | Provider done, chat wiring missing |
| **MCP client** (external servers, `/channels/mcp`) | ✅ `mcp_fetch` — read-only tool calls into prompts (`McpFetchRunner`) | ❌ Mutating tools deliberately refused (v1 pull-only) | Working; gated by `MCP.CLIENT_ENABLED` + `MULTITASK.MCP_FETCH_ENABLED` + topic meta `tool_mcp` |
| **email_me** (account owner's inbox) | ❌ | ✅ Mails assembled artifacts to the owner (`EmailMeRunner`, `InternalEmailService`) | Working |
| **Share link** | ❌ | ✅ Public download link (`ShareLinkDestinationProvider`) | Working |

## 3. Capability matrix — conversation transports

| Transport | IN | OUT | Status |
| --------- | -- | --- | ------ |
| Web chat / Widget | ✅ | ✅ SSE stream | Working |
| WhatsApp | ✅ webhook (`WebhookController`) | ✅ text/media/TTS via Cloud API (`WhatsAppService`) | Working (admin `WHATSAPP_*` config) |
| Email (smart@ webhook) | ✅ | ✅ SMTP reply | Working |
| Messages Gateway (API) | ✅ | ✅ | Working, flag-gated (`MESSAGES_GATEWAY.*`) |
| Synaplan as MCP server | ✅ external hosts call `synaplan_chat`, RAG, memories, file ingest | ✅ tool results | Working (dedicated `mcp` firewall) |

---

## 4. The two headline gaps and what closes them

### 4.1 Calendar entries and reminders do not land in a connected calendar

Today, "create a meeting reminder … and put it into my Outlook" produces a **downloadable `.ics` file** attached to the reply (`CalendarEventRunner` → `CalendarEventService::buildIcs`) — nothing is written to Outlook or CalDAV. This is deliberate and locked by characterization (`u1_outlook_calendar_write` in `UtterancePlanCharacterizationTest`). Two contributing facts:

- The Microsoft consent requests only `offline_access, openid, email, profile, User.Read, Mail.Read` — no `Calendars.*`, so Graph calendar write is not even permissioned.
- `CalendarEventRunner` ignores `params.channel`, even though `PlannerChannelCatalog` advertises connected CalDAV calendars to the planner with capability `calendar_event`. The planner can *say* "use the calendar channel"; the runner never delivers.
- The generated `.ics` carries no `VALARM`; there is no Microsoft To Do / Graph reminder integration. "Reminder" phrasing maps to a plain event.

**Closing steps (Phase M, in `10_m365_actions_and_destinations.md`):**

- **M2** — scope tiers + incremental consent ("Upgrade access" UX). Prerequisite for all Graph write work.
- **M4** — Graph calendar **read** (`Calendars.Read`), dedup contract shared with CalDAV.
- **M5** — Graph calendar **write**: U1 creates a real event with `webLink` in the reply, idempotent re-runs.
- **M6** — planner channel `outlook`: wires `calendar_event` output to that delivery.
- **Quick win independent of Graph:** wire `calendar_event` → existing CalDAV connections in the chat pipeline (the `CalDavDestinationProvider` is finished; only the runner/delivery hookup is missing). Not currently a numbered Phase M step.
- **New backlog item (not on any roadmap yet):** reminders/alarms — `VALARM` in generated `.ics` and/or Graph reminder APIs.

### 4.2 Remaining M365 / destination roadmap

- **M7** — U4 "into Outlook" for documents = `email_me` mapping + honest copy ("sent to your inbox").
- **M8** — OpenCloud write spike, then `DestinationProvider` adapter.
- **M9** — OneDrive file drop (`Files.ReadWrite`, channel `onedrive`), optional this phase.
- `outbound_webhook` for Saved Tasks is referenced in `SavedTaskService` only — no `Capability`, no runner (stub).

Shipped so far (2026-08-18): **M0, M1, M3a–M3d, M10** (Dropbox pulled forward). Email search (`MULTITASK.EMAIL_SEARCH_ENABLED`) is seeded ON.

---

## 5. Verification evidence (2026-08-21, live dev stack)

- 54 targeted backend tests / 263 assertions green: `CalendarEventRunner`, `CalendarEventService`, `CalDavClient`/`CalDavDestinationProvider`, `CalendarEmailChainTest`, `EmailSearchRunner` (IMAP+M365 merge, per-source degradation), `GraphClient`/`GraphMailboxSearcher`, `PlannerChannelCatalog`, `DropboxDestination`, `SaveToFolder`.
- `UtterancePlanCharacterizationTest` green — today's plan for "put it into my Outlook" is `.ics` + reply, no delivery node.
- Live `BCONFIG` (fresh seed): `MULTITASK.ROUTING_ENABLED=1`, `MULTITASK.EMAIL_SEARCH_ENABLED=1`, `MULTITASK.MCP_FETCH_ENABLED=1`, `MCP.CLIENT_ENABLED=1`. No `M365`/`DROPBOX` rows until an operator enters OAuth app credentials (setup guides: `M365SetupGuide.vue`, `DropboxSetupGuide.vue`).

**Manual acceptance probes on a configured instance:**

1. Connect an M365 account (Channels → Connections), ask "What is the latest mail from `{sender}`, summarize it" → works today.
2. Ask "Create a meeting for tomorrow 10am and put it into my Outlook" → since the §6 sprint: creates the event in the connected Outlook calendar (account must be consented with the expanded scopes) AND returns the `.ics` download; on a pre-expansion connection it degrades to the download with a reconnect hint.
3. `POST /api/v1/files/{id}/send` with `destination=caldav` + `connection_id` → events land in the CalDAV calendar, deduplicated.

---

## 6. Channel sprint 2026-08-21 — what shipped on this branch

The gaps in §4 were closed on this branch (product-owner request: "enable all missing channels now in this sprint"):

| Gap | Shipped as |
| --- | ---------- |
| Jira/Confluence via MCP | Quick-start presets in *Channels → MCP servers* (`McpServersConfiguration.vue`); reads (search pages, summaries, list tickets) via existing `mcp_fetch` |
| MCP write actions | New `mcp_action` capability + `McpActionRunner`: create Confluence pages / Jira tickets via prompt. Per-server **allow write actions** opt-in (`BMCPSERVERS.BALLOWWRITE`, default off); destructive tools always refused; flag `MULTITASK.MCP_ACTION_ENABLED` seeded ON |
| M365 scopes | `MicrosoftOAuthConfig::SCOPES` += `Calendars.ReadWrite`, `Mail.Send`. Pre-expansion connections degrade honestly (scope check on `Connection::getScopes()`) and work again after a reconnect |
| Outlook calendar write (M4–M6) | `GraphClient::createEvent` (idempotent via `transactionId`, 409 = already delivered) + `OutlookCalendarDestinationProvider` (`m365_calendar`) + planner channel `outlook` (m365 connections now expose a mail AND a calendar channel via `PlannerChannelCatalog::channelsForConnection`) |
| Chat → calendar wiring | `CalendarEventRunner` accepts `params.channel` and delivers through `RequestedCalendarDelivery` — CalDAV **and** Outlook; `.ics` download stays the fallback; failures degrade to the download, never sink the node |
| Email write via M365 | `M365MailSender` (Graph `sendMail`, lands in the user's Sent items); `email_me` prefers it and falls back to system SMTP |
| File-send destinations | `POST /files/{id}/send` now documents `dropbox` and accepts `m365_calendar` |

Characterization: `planner_system_prompt.txt` re-recorded (rules 7/9e + examples); `utterance_plans.json` U1 now carries `params.channel = "outlook"` (the M6 review artifact the M1 lock anticipated).

Still open after this sprint: reminders/alarms (`VALARM` / Graph reminders — still not on any roadmap), M2 incremental-consent UX ("Upgrade access"), M7 copy work, M8 OpenCloud, M9 OneDrive, `outbound_webhook`.
