# Catalog — 24 open-source consumers of the Synaplan API

**Status:** Inventory 2026-09-17. A row is not a promise to build a
plugin this quarter. BI1 ships a card for every **shipped** row; the
rest are documented stubs (docs + snippet, no teaser button).

Auth column uses only the three schemes in the master plan §0 row 5.

| # | Consumer | Group | Face | Auth | State | Home |
| - | -------- | ----- | ---- | ---- | ----- | ---- |
| 1 | **Nextcloud** | Files | native `/api/v1` | key today; handshake S2 | **Shipped** (summarize, translate, RAG, chat, model picker) | `synaplan-nextcloud` |
| 2 | **OpenCloud** | Files | native + Go sidecar | OIDC RFC 8693 | Early (summarize / translate) | `synaplan-opencloud` |
| 3 | **ownCloud Online** | Files | native `/api/v1` | key / provision | Shipped in partner repo | `synaplan-owncloud-online` |
| 4 | **Collabora Online** | Weboffice | OpenAI chat + tools | per-user gateway key | Planned | [`20260902-collabora-integration/`](../20260902-collabora-integration/STATUS.md) |
| 5 | **OnlyOffice** | Weboffice | OpenAI chat (AI plugin) | key | Catalog / later | — |
| 6 | **Outlook** | Mail | native `/api/v1` | platform-links + Office dialog | **Shipped** | `Synamail` |
| 7 | **Thunderbird** | Mail | same Vue app | MailExtension | Planned | `Synamail/docs/THUNDERBIRD_INTEGRATION.md` |
| 8 | **Word** | Office desktop | native `/api/v1` | Office dialog (Synamail auth) | Planned | Synaoffice estimate |
| 9 | **Excel** | Office desktop | native `/api/v1` | same | Planned | Synaoffice; sheet → chart / RAG |
| 10 | **PowerPoint** | Office desktop | native `/api/v1` | same | Planned | Synaoffice |
| 11 | **OX App Suite** | openDesk mail | OpenAI or native | OIDC (Nubus) | Catalog / later | openDesk mail |
| 12 | **Claude Code** | Editors | Anthropic `/v1/messages` | key | **Works today** | `docs/ANTHROPIC_COMPATIBLE_API.md` |
| 13 | **VS Code** | Editors | OpenAI and/or Messages | key | Wave 6 BI3 | [`02_developer_clients.md`](./02_developer_clients.md) |
| 14 | **Cursor** | Editors | same as VS Code | key | Wave 6 BI3 | same |
| 15 | **Neovim** | Editors | same | key | Wave 6 BI4 | [neovim.io](https://neovim.io/) |
| 16 | **Continue / Aider / Zed** | Editors | OpenAI or Anthropic | key | Docs snippet only | community tools |
| 17 | **Element (Matrix)** | openDesk chat | STT sessions + chat | bot key + room invite | Flagship OD-B / OD-C | [`04_opendesk_audio_transcriber.md`](./04_opendesk_audio_transcriber.md) |
| 18 | **Jitsi** | openDesk meetings | OpenAI-compatible STT | service key | Flagship OD-A | same |
| 19 | **Nextcloud Talk** | NC-only comms | STT + chat | key | Catalog row; **not** openDesk v1 | NC Talk live_transcription is Vosk today |
| 20 | **WordPress** | CMS | OpenAI chat + RAG | key / application password | Planned (new plugin repo) | — |
| 21 | **OpenProject** | openDesk PM | OpenAI or MCP | OIDC | Catalog / later | openDesk |
| 22 | **XWiki** | openDesk wiki | OpenAI chat | OIDC | Catalog / later | openDesk |
| 23 | **GitLab / Forgejo** | Dev | OpenAI or MCP | key | Docs snippet | — |
| 24 | **Mattermost / Rocket.Chat / Zulip / Discourse / Moodle / Seafile** | Community | OpenAI chat | key | Docs snippet | pick one after WordPress |

Also already Synaplan-native (listed on the page as “already in this
product”, not as Connect cards): chat widget, WhatsApp, inbound email,
MCP servers, Desktop pairing, Synaform, Synafastbill.

---

## How a new row gets in

1. Name the face (Messages / OpenAI chat / STT / native).
2. Name the auth scheme (one of three).
3. Name the user journey and where the result is found in ten seconds.
4. Add the card as **docs-only** until an adapter repo exists.
5. Never show a dead **Connect** on a docs-only row (U11).

---

## Priority for adapters (after BI1)

| Order | Why |
| ----- | --- |
| 1. Jitsi (OD-A) | openDesk meetings; STT API already exists; no E2EE wall |
| 2. VS Code + Cursor + Neovim | “own Synaplan like Claude Code” |
| 3. Element voice messages (OD-B) | Same bot, async, high value |
| 4. Word (Synaoffice) | Synamail auth is paid for |
| 5. WordPress | CMS volume; OpenAI face is enough for v1 |
| 6. Element Call (OD-C) | Harder (visible participant, E2EE) |
| 7. Excel | After Word; same repo |
| 8. Thunderbird | After Synaoffice spike, or in parallel in Synamail |
