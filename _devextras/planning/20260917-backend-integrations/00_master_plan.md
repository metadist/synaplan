# Backend integrations — master plan

**Status:** Draft 2026-09-17. Do not start product code until every row
in §0 is agreed. If a row is rejected, update this file in the same
change as the alternative.
**Owner surface:** Developer & devices → **Integrations** (new page).
No new rail item.
**Class:** the page is `ota-candidate`. Adapters live in sibling or new
repos (`backend-only` from this repo’s point of view).
**Related:** live roadmap §6–§7; catalog [`01_catalog.md`](./01_catalog.md);
editors [`02_developer_clients.md`](./02_developer_clients.md); office
[`03_office_and_mail.md`](./03_office_and_mail.md); openDesk STT
[`04_opendesk_audio_transcriber.md`](./04_opendesk_audio_transcriber.md).

---

## 0. Decision checklist (tick before any code)

| # | Decision | Proposed default | Agree? |
| - | -------- | ---------------- | ------ |
| 1 | Wave 6 adapters **call** Synaplan over HTTP. They are not in-process `plugins/` unless a later STATUS row says so. | Locked | |
| 2 | One **Integrations** page is the web home. Coding clients, API keys, MCP, Desktop pairing stay as they are; this page links to them and adds Connect forms. | Locked | |
| 3 | Three public faces stay: Anthropic Messages, OpenAI Chat Completions, OpenAI audio (one-shot + sessions). Native `/api/v1` stays for first-party apps (Nextcloud, Synamail). | Locked | |
| 4 | Model selection is a picker on the Connect form (chat / STT / embeddings), written into the snippet. Never a raw `BID`. | Locked | |
| 5 | Auth: API key for editors and bots; platform-links handshake or OIDC for hosted apps that already have a user session. Do not invent a fourth scheme. | Locked | |
| 6 | Default-off FeatureModule `integrations` for any new Synaplan-side bot or transcriber route. Existing OpenAI / Messages gateways stay on their own flags. | Locked | |
| 7 | openDesk v1 transcriber = **Jitsi meetings**. Element voice messages = v1.1. Element Call live captions = v2. Nextcloud Talk is a catalog row only. | Locked | |
| 8 | Editor clients are **new repos** (`synaplan-vscode`, `synaplan-nvim`). Cursor ships as a VS Code-compatible build or a thin extra. | Locked | |
| 9 | Word / Excel / PowerPoint = new **Synaoffice** repo (Synamail estimate). Thunderbird = Synamail host adapter. Do not fork Synamail. | Locked | |
| 10 | Primary copy: **Connect**, **Disconnect**, **Your Synaplan**, **model**. Banned on the page: MatrixRTC, LiveKit, JVB, `base_url`, whisper.cpp. | Locked | |
| 11 | Every Connect card states who can see the key, what the app can do, and how to revoke. Disconnect is on the same row (U3). | Locked | |
| 12 | Five locales in the same PR as the page. Empty state is one sentence + **Connect an app**. | Locked | |
| 13 | Partner adapters keep their own CI. This repo only grows OpenAPI + the page + docs snippets. | Locked | |
| 14 | Do not enable Cloud STT for openDesk by default. Local whisper.cpp or the operator’s chosen SOUND2TEXT model. | Locked | |
| 15 | Consent before any live audio leaves the meeting. Stop is one click. Transcript lands in a place the user already opens (Element room or Nextcloud folder). | Locked | |

---

## 1. Why this track

Synaplan already speaks the protocols coding tools and office suites
expect. What we do **not** have is one place that says “here are the
twenty tools that talk to us, pick a model, copy the config.” People
discover Claude Code in `docs/ANTHROPIC_COMPATIBLE_API.md`, Nextcloud
in another repo, Outlook in Synamail, STT in the middle of the OpenAI
doc. That is a scavenger hunt.

Wave 6 is the collection. The flagship new adapter is the openDesk
audio transcriber. The flagship **in-repo** work is the Connect page
and the contract the adapters share.

---

## 2. What already exists (do not rebuild)

| Face | Path | Who uses it today |
| ---- | ---- | ----------------- |
| Anthropic Messages | `POST /v1/messages` | Claude Code, Desktop, Messages gateway |
| OpenAI chat | `POST /v1/chat/completions`, `GET /v1/models` | Generic SDKs; Collabora plan; assistant aliases |
| OpenAI audio | `POST /v1/audio/transcriptions` + `/sessions` | Documented; no partner consumer yet |
| Native chat / files / RAG | `/api/v1/*` OpenAPI | Nextcloud, OpenCloud, ownCloud Online, Synamail, widget |
| MCP | `/mcp` | Coding clients, Desktop |
| Platform-links | link-code + `/addin/connect` | Outlook; Nextcloud S1 |
| OIDC token exchange | RFC 8693 | OpenCloud |
| In-process plugins | `plugins/*/manifest.json` | hello_world, serper_search, castingdata — **not** Wave 6 |

STT is already metered (`AiFacade::transcribe`, local whisper.cpp is
free). Wave 6 must not add a second transcription choke point.

---

## 3. Target architecture

```
                    ┌─────────────────────────────┐
   Editor / bot     │  Anthropic / OpenAI faces   │
   Jitsi proxy      │  /v1/messages               │
   Element bot      │  /v1/chat/completions       │
                    │  /v1/audio/transcriptions*  │
                    └─────────────┬───────────────┘
                                  │ scoped API key
                    ┌─────────────▼───────────────┐
                    │  Synaplan (models, RAG,     │
   Nextcloud app    │  STT, audit, quotas)        │
   Synamail         │                             │
   OpenCloud        └──▲──────────▲───────────────┘
                       │          │
              native /api/v1   OIDC / platform-links
```

**One Connect form** writes:

- instance URL
- auth method (key / handshake / OIDC)
- model ids for the capabilities the adapter actually uses
- a snippet (env, `settings.json`, `coolwsd.xml`, Jicofo template, Matrix bot env)

The adapter never stores a second copy of the user’s cloud-provider
key. It stores a Synaplan key (or a token). Synaplan picks the
upstream.

---

## 4. Integrations page — journeys

**User-flow:** this file §4. Class: `ota-candidate`.

| Id | Journey |
| -- | ------- |
| J-BI-1 | **First connect.** Developer & devices → Integrations (empty: “Connect an app to this Synaplan.”). Opens **Claude Code**, picks chat model, copies three export lines, runs `claude`. A test prompt comes back. Disconnect removes the key from the list. |
| J-BI-2 | **Editor.** Same page → **VS Code**. Snippet lands in `settings.json` (`synaplan.baseUrl`, `synaplan.apiKey`, `synaplan.model`). Chat in the sidebar uses the picked model. |
| J-BI-3 | **Meeting.** Admin opens **openDesk meetings**, picks STT model + Nextcloud folder, copies the Jitsi template. Next meeting: Transcribe → captions → file in that folder + a line in the Element room. Stop ends it. |

Exit bullets (UX contract §6) apply to each.

Wireframe notes (copy before Vue):

- Catalog is a 2-up card grid on desktop, one column at 320 px.
- Each card: name, one sentence, **Connect** or **Connected · Disconnect**.
- Connected card shows the model name, not the provider id.
- Advanced (collapsed): raw endpoint list for people who already know.

---

## 5. Sprints

| Sprint | What | Repo | Depends |
| ------ | ---- | ---- | ------- |
| **BI1** | Integrations page, catalog cards for *shipped* consumers, Connect form for Claude Code + generic OpenAI, five locales | `synaplan` | §0 ticked; NV06 group exists |
| **BI2** | Docs page “Use your Synaplan from other apps”; snippets generated from the same source as the form | `synaplan-docs` | BI1 |
| **BI3** | VS Code extension (OpenAI-compatible + optional `/v1/messages`); Cursor install path | new `synaplan-vscode` | BI1 |
| **BI4** | Neovim plugin (`init.lua`, same faces) | new `synaplan-nvim` | BI1 |
| **BI5** | openDesk Jitsi transcriber (OD-A) | new `synaplan-opendesk-transcriber` + small Synaplan module | BI1; transcriber §0 |
| **BI6** | Element voice messages (OD-B) | same new repo | BI5 |
| **BI7** | Element Call captions (OD-C) | same new repo | BI6 |
| **BI8** | Synaoffice Word MVP spike (auth + selection rewrite) | new `Synaoffice` | Synamail auth doc |

Nextcloud S2, Collabora, Thunderbird, WordPress stay in their own
plans; they **gain a catalog card** in BI1 and a snippet in BI2.

---

## 6. Security and classification

- Keys on the Connect form are created with **scopes** (`messages:*`,
  `audio:transcribe`, `files:write` as needed). No unscoped “god key”
  for a meeting bot.
- Live audio is a write-class concern for privacy: consent, retention
  (default: transcript kept, audio discarded), audit row per session.
- Mobile: the Integrations page is `ota-candidate`. Native editor
  clients are not shipped through Apple. openDesk bots are
  `backend-only` from this repo.

---

## 7. Out of scope

- Replacing Claude Code, Continue, or Aider with a Synaplan-branded
  agent loop in this repo.
- Becoming a Matrix homeserver or a Jitsi operator.
- Nextcloud Talk as the openDesk path.
- In-process channel plugins (archived 2026-08-22 plan).
- Enabling Cloud STT on sovereign installs by surprise.
