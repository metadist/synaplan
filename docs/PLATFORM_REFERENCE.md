# Platform reference

Everything that used to live in the main [README](../README.md): installation variants, the full feature list, AI providers, optional building blocks and developer commands. Start with the [README](../README.md#quickstart) if you just want to try Synaplan.

**On this page:** [More ways to install](#more-ways-to-install) · [Prerequisites](#prerequisites) · [Install options](#install-options) · [Access](#access) · [Features](#features) · [AI providers & models](#ai-providers--models) · [Building blocks](#lean-by-design-core-vs-optional-building-blocks) · [Realtime](#realtime--background-processing) · [Text-to-speech](#text-to-speech) · [Office documents](#office-documents) · [File work](#file-work) · [Common commands](#common-commands) · [Ecosystem](#the-synaplan-ecosystem) · [Project structure](#project-structure)

---

## More ways to install

The [quickstart](../README.md#quickstart) is a local checkout. The variants below are a published image, a machine with no route to the public internet, and a Linux server.

### On this machine

The [quickstart](../README.md#quickstart) is this path. Open **<http://localhost:5173>**. A status screen is up within seconds and lists every boot step, then switches to the app when it is ready. First start: 5–15 minutes. Later starts: seconds.

`make up` answers on `:5173` first, then downloads the chat services (database, Redis, backend) before memories, spoken answers, office files and the dev tools. `docker compose up -d` starts the same stack; `:5173` stays quiet until every image has been pulled.

1. Log in as `admin@synaplan.com` / `admin123`. The status screen shows this too.
2. Type your first chat message: it starts the Synaplan Demo (14 days, 40 messages a day via `web.synaplan.com`). Or paste one provider key (free: [Groq](https://console.groq.com)). You do not edit a config file for that.
3. Same notes in the terminal: `docker compose logs -f startup-notes`.

No cloud key? `COMPOSE_PROFILES=local-ai ENABLE_LOCAL_GPT_OSS=true make up` pulls a local chat model (`gpt-oss:20b`, ~14 GB). Chat starts when the download finishes.

### Published image, no git checkout

One file is enough. A `.env` beside it is optional.

```bash
mkdir synaplan && cd synaplan
curl -fsSL -o compose.yaml https://raw.githubusercontent.com/metadist/synaplan/main/deploy/quickstart/compose.yaml
docker compose up -d
```

Open **<http://127.0.0.1:8000>**. The first visit opens the setup: create the administrator, then click **Start with Synaplan Demo** or paste one provider key (free: [Groq](https://console.groq.com)), done. The first start pulls the images and takes a few minutes.

Need a smaller footprint (~3.2 GB instead of ~5.3 GB)? [`deploy/quickstart/compose.lite.yaml`](../deploy/quickstart/compose.lite.yaml) omits `tts`, `qdrant` (document search stays on MariaDB VECTOR; memories pause) and `centrifugo` while keeping the exact same volume names — so you can switch to `compose.yaml` later without losing a single chat or file.

**Docker GUI** (Portainer, Dockge, Synology Container Manager, …): create a stack, paste [`deploy/quickstart/compose.yaml`](../deploy/quickstart/compose.yaml) as its compose file, deploy. Any setting from [`deploy/quickstart/.env.example`](../deploy/quickstart/.env.example) goes into the stack's environment variables — nothing is required. When the GUI runs on another machine (a NAS or a server), add `SYNAPLAN_HTTP_BIND=0.0.0.0` and `SYNAPLAN_URL=http://<that machine's IP>:8000`; without them Synaplan only opens on that machine itself. Dockge marks the stack "exited" once the one-time `secrets-init` step has finished; that is expected, and Synaplan keeps running.

**Settings** go into `.env`, then `docker compose up -d` again (in a GUI: the stack's variables, then redeploy):

- `SYNAPLAN_VERSION` picks a release tag from the [releases page](https://github.com/metadist/synaplan/releases). Empty means the release this file was published with. Never `latest`.
- `SYNAPLAN_HTTP_BIND=0.0.0.0` plus `SYNAPLAN_URL=http://<this machine's IP>:8000` opens it to your network. `SYNAPLAN_URL` must be the address people type, or live chat stays disconnected. Over plain `http://` on a network address, browsers block the microphone and copying to the clipboard; chat and files work. For those two, use [Closed network](#closed-network) HTTPS.
- Provider keys, a bootstrap administrator and mail are listed in the example with one line each.

**Data** lives in named Docker volumes — the database, uploads, vectors and the generated secrets. Keep them together: a database without its `secrets` volume does not start. `docker compose down` keeps everything; `docker compose down -v` deletes it all.

**Change the release:** back up the volumes, set `SYNAPLAN_VERSION`, run `docker compose up -d` (or redeploy the stack in the GUI). Going back to an older tag after a newer one migrated the database means restoring that backup. The steps are in [Update a self-hosted deployment](UPDATE_SELFHOST.md#quickstart-volumes).

Office documents, local AI, file work, closed-network TLS and scripted backups use the full [`deploy/compose.yaml`](../deploy/compose.yaml) with [`deploy/selfhost.env.example`](../deploy/selfhost.env.example) instead.

### Closed network

Opt-in. The default stays `http://127.0.0.1:8000` on this machine.

Turn it on when the network has **no route to the public internet** and people open Synaplan by the machine's address. Chat needs `https://<address>/`. The machine creates the certificate. The browser warns once.

Any IPv4 address on that network works, including `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `100.64.0.0/10` and `169.254.0.0/16`, plus a block you assigned and do not announce.

```bash
cp deploy/selfhost.env.example deploy/.env
# Set SYNAPLAN_VERSION. Leave SYNAPLAN_HTTP_BIND at 127.0.0.1.
deploy/scripts/local-tls.sh 10.0.0.15
deploy/scripts/prepare.sh
docker compose --env-file deploy/.env -f deploy/compose.yaml pull
deploy/scripts/validate-release.sh
docker compose --env-file deploy/.env -f deploy/compose.yaml up -d
```

Colleagues open **https://10.0.0.15/**. Ports 80 and 443 must be free. The app itself stays on `127.0.0.1:8000`. A second interface is another argument: `deploy/scripts/local-tls.sh 10.0.0.15 192.168.1.20`. A public name keeps your own HTTPS proxy and leaves this off.

Details: [docs.synaplan.com/local-network](https://docs.synaplan.com/local-network) · [deploy/README.md](../deploy/README.md#local-network).

### On a server

The commands above are the development stack (source build, Vite, MailHog, phpMyAdmin) or the published image. For a Linux server, the installer writes `deploy/.env`, pins the latest release, and runs prepare → pull → validate → start → smoke-test. Secrets land in `deploy/data/secrets.env`.

```bash
curl -fsSL https://raw.githubusercontent.com/metadist/synaplan/main/install.sh | \
  bash -s -- --mode server --domain https://ai.example.com
```

The same steps by hand are in [Installation](INSTALLATION.md) and [deploy/README.md](../deploy/README.md). Flags: `bash install.sh --help`.

One-liner for the development stack, if you would rather not clone by hand:

```bash
curl -fsSL https://raw.githubusercontent.com/metadist/synaplan/main/install.sh | bash
```

### One-click deploy

<a href="https://repocloud.io/details/Synaplan/"><img src="https://d16t0pc4846x52.cloudfront.net/deploylobe.svg" alt="Deploy on RepoCloud" height="42"></a>
<a href="https://elest.io/open-source/synaplan"><img src="https://elest.io/images/logos/deploy-to-elestio-btn.png" alt="Deploy on Elestio" height="42"></a>

---

## Prerequisites

- **Docker** + **Docker Compose v2** (Docker Desktop on macOS/Windows, or Docker Engine + the Compose plugin on Linux)
- **Git** (the one-line installer also works with `curl` + `tar` when git is missing)
- **8 GB RAM** minimum (16 GB recommended once you add the `local-ai` profile)
- **~4 GB free disk** for the standard install (includes file work + spoken answers; +~1 GB for `local-ai`, +~14 GB if you also enable the local chat model)
- A few host ports must be free. The list, and how to change one without editing the Compose file, is in [Installation — Port conflicts](INSTALLATION.md#port-conflicts).

> **Apple Silicon (M1–M4) Macs — build the backend image, don't pull it.** The three-step start above already does this: `make up` builds the backend and worker locally from a multi-arch base image, so PHP/FrankenPHP runs **natively on `arm64`** with no emulation tax. That is by far the fastest setup, and it is the default — you don't have to do anything special. (The published `ghcr.io/metadist/synaplan` image is multi-arch too, so pulling it also runs natively.) The first local build takes a few minutes; every later start is a cache hit. phpMyAdmin is the official multi-arch image and runs natively on Apple Silicon. MailHog is still an amd64-only upstream image — if you keep it, enable **Docker Desktop → Settings → General → "Use Rosetta for x86/amd64 emulation on Apple Silicon"** (macOS 13+) so it emulates quickly.

---

## Install options

| Mode | Command | Size | Best For |
|------|---------|------|----------|
| **One-liner** | `curl -fsSL https://raw.githubusercontent.com/metadist/synaplan/main/install.sh \| bash` | ~4 GB | Easiest start — checks prerequisites, fetches, and starts the standard stack (`--mode server` available for production) |
| **Standard** | `make up` | ~4 GB | Local try-out: chat works after one click (Synaplan Demo or your own key), file work, spoken answers — status page first, then the rest |
| **Quickstart (lite)** | `deploy/quickstart/compose.lite.yaml` | ~3.2 GB | Published image, 6 runtime containers (no git clone, no `.env`; shares volumes with `compose.yaml`) |
| **+ local AI** | `COMPOSE_PROFILES=local-ai make up` | ~5 GB | Adds Ollama and the `bge-m3` embedding model on your own hardware (local chat model optional, +~14 GB) |
| **Production** | `install.sh --mode server` or `deploy/` compose + scripts | published image | Self-host on a Linux server — see [Installation](INSTALLATION.md) |
| **Kubernetes** | [synaplan-charts](https://github.com/metadist/synaplan-charts) | published image | Helm-based cluster deployments for partners and enterprises |

**Public sector & sovereign cloud (EU/CH):** [synaplan.eu](https://www.synaplan.eu/) covers openDesk, Nextcloud and OpenCloud deployments, on-premises or in an EU cloud.

A plain `docker compose up -d` starts the same stack but `:5173` stays silent until every image is pulled. `make up` answers within seconds, then finishes chat before it downloads the optional images.

No AI weights are downloaded by default, so the first boot is dominated by the Docker images and `npm ci`. `COMPOSE_PROFILES=local-ai` is the same switch a self-hosted install uses in `deploy/.env`, and it pulls the local embedding model (`bge-m3`, ~1 GB) in the background for RAG and semantic search; progress is shown in the app.

Prefer the shell to the UI for provider keys? Keys in `backend/.env` still work — the backend reads that file when the container starts and imports the key into the encrypted store on first use. Write the key before starting, or restart the containers afterwards:

```bash
echo "GROQ_API_KEY=your_key" >> backend/.env
make up
# already running? pick up the new key with:
# docker compose restart backend worker
```

---

## Access

Open **<http://localhost:5173>**. The API is at <http://localhost:8000>, the API docs at <http://localhost:8000/api/doc>. phpMyAdmin, MailHog, Qdrant, Tika, Ollama and TTS: [Installation — Development service URLs](INSTALLATION.md#development-service-urls).

**Default logins (local development only):**

| Email | Password | Level |
|-------|----------|-------|
| admin@synaplan.com | admin123 | ADMIN |
| demo@synaplan.com | demo123 | PRO |
| test@example.com | test123 | NEW (unverified) |

> ⚠️ These logins are public knowledge — anyone who can open your instance can use them. Change the admin password after your first login, and never expose an install that still uses them to a network others can reach. For a server, use [On a server](#on-a-server): the installer generates its own admin password.

---

## Features

- **AI Chat** — Ollama, OpenAI, Anthropic, Gemini, Groq, Mistral, xAI, TrustedTokens (DE), A2Agent (CN), HuggingFace ([provider list](#ai-providers--models))
- **Self-aware assistant** — Ask "What can you do here?" or type `/help`; the AI assistant answers from this installation's live capabilities, not a generic brochure
- **Multi-Task DAG Routing** — An AI planner decomposes complex requests into a directed task graph (extract → summarize → generate → reply), routes each step to the model that fits it, and streams live task cards while the steps execute — cheaper models for simple steps means fewer wasted tokens
- **RAG Search** — Semantic document search with MariaDB VECTOR or Qdrant
- **Chat Widget** — Embed on any website ([widget guide](https://docs.synaplan.com/widget))
- **Mobile Apps** — Chat, documents and voice on iPhone and Android, pointed at web.synaplan.com or at your own server ([App Store](https://apps.apple.com/app/id6784278288?ct=github-readme) · [Google Play](https://play.google.com/store/apps/details?id=com.synaplan.app&referrer=utm_source%3Dgithub-readme))
- **Synaplan Desktop** — Pair a computer and run skills on it. Open **Channels → Synaplan Desktop**. No installer yet ([synaplan-desktop](https://github.com/metadist/synaplan-desktop))
- **AI assistants** — Saved recipes (instructions, knowledge folders, tools, triggers) that you publish in versions ([assistants](https://docs.synaplan.com/assistants))
- **Tools & approvals** — One tool registry; write-class actions pause under **Approvals** ([tools](https://docs.synaplan.com/tools-and-approvals))
- **Users & groups** — Share folders, chats, assistants and tasks; **Admin → Users** ([people](https://docs.synaplan.com/people-and-groups))
- **File work** — Short Python or Node runs on *copies* of files you picked, in an isolated sidecar ([guide](COMPUTE.md))
- **Live Support** — Realtime WebSocket layer (Centrifugo + Redis): human takeover of widget chats, typing indicators, operator notifications ([realtime guide](REALTIME.md))
- **WhatsApp** — Meta Business API integration
- **Email** — AI-powered email responses, plus live mailbox search (IMAP and Microsoft 365)
- **Connections** — Microsoft 365, Dropbox, Nextcloud / ownCloud / WebDAV, CalDAV — read mail, file results, write calendar events ([connections guide](CONNECTIONS.md))
- **Saved Tasks** — Pin a multi-step plan and run it on demand or on a schedule, with a **Steps** editor and webhook trigger (**Manage → Automations → Saved Tasks**)
- **Watched pages** — Save a URL, compare it on a schedule, and mail the diff when it changes
- **Audio** — Whisper transcription (input) + optional [synaplan-tts](https://github.com/metadist/synaplan-tts) (output; five baked voices, UI language selects the voice)
- **Documents** — PDF, Word, Excel, images with OCR; the assistant can also build and revise Office files; optional Collabora CODE sidecar for thumbnails, PDF export, preview and combine ([office documents](https://docs.synaplan.com/office-documents))
- **AI Memories** — User profiling with Qdrant vector search
- **Feedback System** — Feedback capture and analysis powered by Qdrant
- **Plugins** — Non-invasive plugin system ([plugin guide](https://docs.synaplan.com/plugins))
- **MCP Server** *(early access)* — Connect AI clients (Claude, Cursor, …) over the Model Context Protocol; your RAG and memories become tools at `POST /mcp` ([MCP guide](https://docs.synaplan.com/mcp))
- **MCP Client** *(early access)* — Connect *your* MCP servers (Jira, Confluence, CRM, wiki, n8n, …) under **Manage → Connections → MCP Servers**. The planner pulls live data via `mcp_fetch` and, when you enable **allow write actions** on that server, can create tickets or pages via `mcp_action` — destructive tools stay refused. SSRF-guarded, per-topic opt-in. Seeded `BCONFIG` flags (`MCP.CLIENT_ENABLED`, `MULTITASK.MCP_FETCH_ENABLED`, `MULTITASK.MCP_ACTION_ENABLED`) turn this on; an explicit `0` row is the operator kill switch. See [docs/MULTITASK_DATA_NODES.md](MULTITASK_DATA_NODES.md)
- **Claude Code & Anthropic-compatible API** — Point Claude Code or any Anthropic-protocol client at your instance (`POST /v1/messages`); configure under **Manage → Developer & devices → Coding clients** ([guide](ANTHROPIC_COMPATIBLE_API.md))

---

## AI providers & models

Synaplan is provider-neutral: connect the providers you want in **Admin → AI infrastructure → Providers & keys** (keys are validated live and stored encrypted in the database, active without a restart), or set the env variables below in `backend/.env` — those are read at container start and imported into the encrypted store on first use. Each user picks a different model **per task** (chat, vision, image, video, audio, embeddings) — nothing is hardcoded.

A new OpenAI-compatible provider is one class. Names, icons, the key check and the outbound allowlist all read that class. The command is in [docs/PROVIDERS.md](PROVIDERS.md).

| Provider | Variable in `backend/.env` | Models |
|----------|---------------------------|--------|
| OpenAI | `OPENAI_API_KEY` | GPT-5.6 Sol / Terra / Luna, GPT-5.5 (+ Pro), GPT-5.4 (+ mini), GPT Image, Whisper, text-embedding-3 |
| Anthropic | `ANTHROPIC_API_KEY` | Claude Opus 5, Sonnet 5, Fable 5, Opus 4.8, Haiku 4.5 (chat + vision) |
| Google Gemini | `GOOGLE_GEMINI_API_KEY` | Gemini 3.x / 2.5 chat + vision, Nano Banana (incl. Pro / Lite), Veo 3.1, Gemini TTS |
| Groq | `GROQ_API_KEY` | Qwen 3.6 27B (chat + vision), GPT-OSS 20B/120B, Whisper Large v3 |
| Mistral 🇫🇷 | `MISTRAL_API_KEY` | Mistral Medium 3.5 (+ vision), Mistral Large 3, Mistral Large 4 (+ vision), Voxtral transcription + TTS |
| xAI | `XAI_API_KEY` | Grok 4.7 / 4.6 / 4.5 (+ vision, 500K context), Grok Imagine image + video (incl. Pro / 1.5 tiers) |
| [Meta](https://dev.meta.ai/) | `META_API_KEY` | Muse Spark 1.3, Muse Wonder (+ vision), Muse Image, Muse Voice Transcribe — Meta Model API |
| [Cerebras](https://cloud.cerebras.ai/) | `CEREBRAS_API_KEY` | Qwen 3.8 27B (+ vision), GPT OSS 120B — very fast inference (~2,000–3,000 tokens/s) |
| [TrustedTokens](https://trustedtokens.eu/) 🇩🇪 | `TRUSTEDTOKENS_API_KEY` | GLM 5.2 / 5.3 (+ Flash vision), Chimera, Qwen3.6 35B (+ vision), GPT OSS 120B — sovereign inference on German GPUs (TNG), zero data retention |
| [A2Agent](https://a2agent.me/) 🇨🇳 | `A2AGENT_API_KEY` | Qwen3.8 MAX / Flash (+ vision), DeepSeek V4 Pro / Flash, MiniMax M3 — Chinese frontier models via the A2Agent gateway |
| HuggingFace | `HUGGINGFACE_API_KEY` | Kimi K3.1 Pro / K3 / K2.5 / K2.6 / K2.7 Code (chat + vision) |
| TheHive | `THEHIVE_API_KEY` | Flux Schnell, SDXL |
| Higgsfield | `HIGGSFIELD_API_KEY` + `HIGGSFIELD_API_SECRET` | Soul, Reve, DoP, Kling 2.1 |
| Cloudflare Workers AI | `CLOUDFLARE_ACCOUNT_ID` + `CLOUDFLARE_API_TOKEN` | bge-m3 embeddings (also usable as embedding fallback) |
| Ollama 🇩🇪 self-hosted | `OLLAMA_BASE_URL` (no key) | Any local model — chat, vision, bge-m3 embeddings |

**Transparent pricing.** Every model carries its provider's own rate (USD per 1M tokens in/out, or per image / second / character for media) — no proprietary credit unit in between. The selector shows a Free / Low / Mid / High cost badge next to each model and on every answer, `GET /api/v1/config/models` returns `priceIn` / `priceOut`, and the Statistics page logs the real cost of each call. On the hosted instance at [web.synaplan.com](https://web.synaplan.com/) that same catalog is what your plan meters against; self-hosted with Ollama, the per-token cost is simply zero. Details: [Model pricing & cost transparency](https://docs.synaplan.com/faq).

> Model catalog changes (new models, retired generations, price updates) ship as seeders, so an existing install is repointed to a supported successor instead of silently keeping a dead model — a retirement records the successor and every install is switched off on the next deploy. See [docs/PRICING_MAINTENANCE.md](PRICING_MAINTENANCE.md).

---

## Lean by design: core vs optional building blocks

`make up` starts a complete platform, but the **core is deliberately small**: the app, its database and Redis. Everything else is a building block that adds one capability and costs RAM. Switch a block on when you need it and off when you don't — Synaplan keeps running either way and simply hides the matching feature. The boot status screen at <http://localhost:5173> lists the live on/off state of every block, and **Admin → System status** (`/admin/features`) does the same after login.

| Block | Gives you | Default | Switch |
|-------|-----------|---------|--------|
| **Core** — `frontend`, `backend`, `worker`, `scheduler`, `db` (MariaDB), `redis` | The app, its API, async jobs, scheduled jobs, storage, cache and queues | always on | — |
| **Ollama** (`ollama`) | Local AI on your hardware: `bge-m3` embeddings for document search, optional local chat (`ENABLE_LOCAL_GPT_OSS=true`) | **off** | `COMPOSE_PROFILES=local-ai make up` — the same switch as `deploy/.env` in production |
| **Qdrant** (`qdrant`) | Vector database for AI memories, feedback analysis and large-scale RAG | on | `docker compose stop qdrant` — document search itself runs on **MariaDB VECTOR** (the default `VECTOR_STORAGE_PROVIDER`), so RAG keeps working; memories pause |
| **Centrifugo** (`centrifugo`) | Live support: human takeover of widget chats, typing indicators, operator notifications ([realtime guide](REALTIME.md)) | on | `REALTIME_ENABLED=false make up` (then `docker compose stop centrifugo`) — the dashboard falls back to plain REST refreshes |
| **Apache Tika** (`tika`) | Text extraction from PDF, Word, Excel and 1000+ formats for RAG | on | `docker compose stop tika` — uploads then index plain text / OCR only |
| **Collabora CODE** (`collabora`) | Office files: thumbnails, “Download as PDF”, inline preview, “Combine as PDF” (~2 GB RAM) — [details](INSTALLATION.md#office-conversion-collabora) | **on** | `docker compose stop collabora` |
| **Docling** (`docling`) | Layout-aware extraction (tables, headings) in front of Tika — [module](https://docs.synaplan.com/modules/docling) | **off** | `docker compose --profile docling up -d` |
| **SearXNG** (`searxng`) | Self-hosted web search so queries stay on your network — [module](https://docs.synaplan.com/modules/searxng) | **off** | `docker compose --profile searxng up -d` |
| **File work** (`compute`) | Short Python / Node jobs on copies of files you pick — [details](INSTALLATION.md#file-work-secure-compute) | **on** | `COMPUTE_URL=disabled make up` |
| **Text-to-speech** (`tts`) | Spoken answers, five built-in voices — [details](#text-to-speech) | **on** | `docker compose stop tts` |
| **Keycloak** (`keycloak`) | SSO test realm for OIDC development ([configuration](CONFIGURATION.md)) | **off** | `docker compose --profile oidc up -d` |

**Keep a default-on block off across restarts.** `docker compose stop` is undone by the next `up -d`. To make a block opt-in permanently, give it a profile in a `docker-compose.override.yml` (not tracked by git) — plain `up -d` then skips it, `--profile optional` brings it back:

```yaml
services:
  qdrant:
    profiles: [optional]
```

Production follows the same rule set: [`deploy/compose.yaml`](../deploy/README.md) always starts web/worker/scheduler plus db/redis/centrifugo/tika/qdrant and spoken answers, with `office`, `local-ai` and `compute` as profiles (`COMPOSE_PROFILES=office,local-ai,compute` in `deploy/.env`). Kubernetes installs wire the same services via [synaplan-charts](https://github.com/metadist/synaplan-charts). The other dev-only containers (`phpmyadmin`, `mailhog`, `frontend-widgets`, `startup-notes`) never ship to production.

---

## Realtime & background processing

Both compose files also start four internal services (no host ports, no setup needed):

| Service | Role |
|---------|------|
| `redis` | Mandatory shared infrastructure: cache, sessions, locks, rate limits, message queues (Redis Streams), Centrifugo engine |
| `centrifugo` | WebSocket gateway for realtime features (live chat takeover, typing indicators, operator notifications) — browsers connect same-origin via `/connection/websocket` |
| `worker` | Symfony Messenger consumer that executes async jobs (AI processing, document indexing, widget crawling) |
| `scheduler` | Ticks every 60 s: saved tasks, media-job reaping, ephemeral-file cleanup, update check |

In a multi-node cluster all nodes share one Redis, so WebSocket events published on one node reach browsers connected to any other. Details: [docs/REALTIME.md](REALTIME.md).

---

## Text-to-speech

Voice output starts with `make up` — [synaplan-tts](https://github.com/metadist/synaplan-tts), image [`ghcr.io/metadist/synaplan-tts`](https://github.com/metadist/synaplan-tts/pkgs/container/synaplan-tts). The image already contains **five Piper voices** (English, German, Spanish, French, Turkish). Stop the `tts` container to hide the speaker control.

```bash
# Already running after `make up`. Standalone on another machine:
docker run -d --name synaplan-tts -p 127.0.0.1:10200:10200 ghcr.io/metadist/synaplan-tts:latest
```

The backend looks at `SYNAPLAN_TTS_URL` (compose default `http://tts:10200`).

**The UI language selects the voice.** Chat sends the active frontend locale (`en` / `de` / `es` / `fr` / `tr`); if the backend detects a different reply language, that wins. Piper then maps the short code to the matching baked voice (German UI → kerstin, Spanish → davefx, …). There is no separate voice picker. Add more Piper models by dropping `.onnx` + `.onnx.json` into the extra-voices volume — see [synaplan-tts README](https://github.com/metadist/synaplan-tts#adding-more-voices) and [docs.synaplan.com/tts](https://docs.synaplan.com/tts).

---

## Office documents

Thumbnails, PDF export, inline preview and “Combine as PDF” use Collabora CODE. `make up` starts it. Chat and DOCX / XLSX / PPTX generation still work if you stop it. The URL is `http://collabora:9980` in `backend/.env.example`. Setup: [Installation — Office conversion](INSTALLATION.md#office-conversion-collabora).

---

## File work

The assistant can run a short Python or Node program on copies of files you already picked. `make up` starts it. The dev token, and how to turn the sidecar off, are in [Installation — File work](INSTALLATION.md#file-work-secure-compute).

---

## Common commands

```bash
# Startup progress ("please wait..." notes + READY message)
docker compose logs -f startup-notes

# Logs
docker compose logs -f backend

# Restart
docker compose restart backend

# Reset database
docker compose down -v && make up

# Run tests
make test

# Code quality
make lint
```

---

## The Synaplan ecosystem

Everything below is the same platform, packaged for different homes. Pick what fits — nothing else is required.

| Project | What it is |
|---------|------------|
| **[synaplan](https://github.com/metadist/synaplan)** | The platform itself (this repo): backend, frontend, widget, plugins, dev stack, and the `deploy/` production contract with Elestio, AWS Marketplace, and Umbrel adapters |
| **[synaplan-charts](https://github.com/metadist/synaplan-charts)** | Helm charts for Kubernetes — for partners and enterprises running K8s clusters |
| **[Mobile apps](https://github.com/metadist/synaplan-apps)** | Native iOS and Android — [App Store](https://apps.apple.com/app/id6784278288?ct=github-readme) · [Google Play](https://play.google.com/store/apps/details?id=com.synaplan.app&referrer=utm_source%3Dgithub-readme) |
| **[synaplan-desktop](https://github.com/metadist/synaplan-desktop)** | Synaplan Desktop — pair a computer and run skills on it. No installer yet; build from source. |
| **[Synamail](https://github.com/metadist/Synamail)** | Outlook add-in (Web, new & classic, Mac) — Synaplan inside your mailbox |
| **[synaplan-nextcloud](https://github.com/metadist/synaplan-nextcloud)** / **[synaplan-opencloud](https://github.com/metadist/synaplan-opencloud)** | Apps for Nextcloud / OpenCloud — use those files as AI knowledge while the file store stays in charge (ownCloud works via the built-in WebDAV connection) |
| **[synaplan-tts](https://github.com/metadist/synaplan-tts)** | Optional self-hosted text-to-speech service for voice output |
| **[synaplan-base-php](https://github.com/metadist/synaplan-base-php)** | The base Docker image (FrankenPHP + gRPC + whisper.cpp) the platform builds on |
| **[synaplan-sortx](https://github.com/metadist/synaplan-sortx)** | Document-sorting plugin and local tool |
| **[synaplan-docs](https://github.com/metadist/synaplan-docs)** | Source of the public docs at docs.synaplan.com |
| **[synaplan-platform](https://github.com/metadist/synaplan-platform)** | Production deployment configs |

---

## Project structure

```
synaplan/
├── backend/        # Symfony PHP API
├── frontend/       # Vue.js SPA
├── docs/           # Developer documentation
├── deploy/         # Production self-host compose + lifecycle scripts
├── sidecars/       # Optional sidecars (secure compute)
├── _docker/        # Docker configs
└── plugins/        # Plugin system
```
