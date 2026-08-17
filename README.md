# Synaplan

AI-powered knowledge management with RAG, chat widgets, and multi-channel integration.

[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)
[![Download on the App Store](https://img.shields.io/badge/App%20Store-Download-0D96F6?logo=apple&logoColor=white)](https://apps.apple.com/app/id6784278288?ct=github-readme)

> **Live instance**: [web.synaplan.com](https://web.synaplan.com/) &nbsp;|&nbsp; **iPhone app**: [App Store](https://apps.apple.com/app/id6784278288?ct=github-readme) &nbsp;|&nbsp; **Docs**: [docs.synaplan.com](https://docs.synaplan.com/) &nbsp;|&nbsp; **API**: [Swagger UI](https://web.synaplan.com/api/doc)

![A tour through Synaplan: chat with live cost tracking, one-key provider setup, per-task model choice, document search, media generation, the embeddable chat widget and white-label branding](docs/images/synaplan-tour.webp)

<p align="center"><a href="https://www.youtube.com/watch?v=WjO9mE43uec">▶ Watch the full demo on YouTube</a></p>

---

## Your first answer in three steps

```bash
git clone https://github.com/metadist/synaplan.git
cd synaplan
docker compose up -d
```

1. **Open <http://localhost:5173>.** The UI is ready in about two minutes.
2. **Log in** as `admin@synaplan.com` / `admin123`.
3. **Open Admin → AI Providers and paste one API key.** Free tier: [Groq](https://console.groq.com).

That third step is the whole setup. **You never touch a config file to connect an AI provider.**

### Key management, the short version

- **Paste it in the UI.** **Admin → AI Providers** (`/admin/setup`) lists every provider with a *Connected* badge and a free-tier hint.
- **Tested before it's saved.** The key is validated against the live provider API, so a typo fails immediately instead of at your first chat.
- **Encrypted at rest.** It lives encrypted in your own database, not in a plaintext file on disk.
- **Active instantly.** No restart and no rebuild — the next message already uses it.
- **Defaults repair themselves.** If the default chat model points at a provider you have no key for, Synaplan repoints it to one that works, so chat is never dead on a fresh install.
- **`.env` still works.** Keys already in `backend/.env` are imported into the encrypted store on first use, and a key you later save in the UI wins permanently.

**No cloud key at all?** Start with `ENABLE_LOCAL_GPT_OSS=true docker compose up -d` to pull a local chat model (`gpt-oss:20b`, ~14 GB, GPU or a strong CPU recommended). Chat begins working when the download finishes; `docker compose logs -f backend` shows progress.

---

## Take the tour

Click any screenshot to see it full size.

<table>
  <tr>
    <td width="25%" align="center">
      <a href="docs/images/tour/chat.webp"><img src="docs/images/tour/thumbs/chat.webp" alt="Chat with per-model cost tracking" width="100%"></a><br>
      <sub><b>Chat</b><br>Every answer shows what it cost</sub>
    </td>
    <td width="25%" align="center">
      <a href="docs/images/tour/provider-setup.webp"><img src="docs/images/tour/thumbs/provider-setup.webp" alt="AI provider setup with live key validation" width="100%"></a><br>
      <sub><b>Provider setup</b><br>One key, tested and encrypted</sub>
    </td>
    <td width="25%" align="center">
      <a href="docs/images/tour/model-selection.webp"><img src="docs/images/tour/thumbs/model-selection.webp" alt="Per-task model selection with cost badges" width="100%"></a><br>
      <sub><b>Model choice</b><br>A different model per task</sub>
    </td>
    <td width="25%" align="center">
      <a href="docs/images/tour/rag-search.webp"><img src="docs/images/tour/thumbs/rag-search.webp" alt="Semantic search across uploaded documents" width="100%"></a><br>
      <sub><b>RAG search</b><br>Semantic search over your files</sub>
    </td>
  </tr>
  <tr>
    <td width="25%" align="center">
      <a href="docs/images/tour/media-generation.webp"><img src="docs/images/tour/thumbs/media-generation.webp" alt="Gallery of AI-generated images and video" width="100%"></a><br>
      <sub><b>Media generation</b><br>Images, video and audio in chat</sub>
    </td>
    <td width="25%" align="center">
      <a href="docs/images/tour/chat-widget.webp"><img src="docs/images/tour/thumbs/chat-widget.webp" alt="Embed code for the chat widget" width="100%"></a><br>
      <sub><b>Chat widget</b><br>One snippet, any website</sub>
    </td>
    <td width="25%" align="center">
      <a href="docs/images/tour/system-prompts.webp"><img src="docs/images/tour/thumbs/system-prompts.webp" alt="System prompt editor" width="100%"></a><br>
      <sub><b>AI instructions</b><br>Your own system prompts</sub>
    </td>
    <td width="25%" align="center">
      <a href="docs/images/tour/file-manager.webp"><img src="docs/images/tour/thumbs/file-manager.webp" alt="File manager with folders and storage quota" width="100%"></a><br>
      <sub><b>Files</b><br>Uploads become knowledge</sub>
    </td>
  </tr>
  <tr>
    <td width="25%" align="center">
      <a href="docs/images/tour/plugins.webp"><img src="docs/images/tour/thumbs/plugins.webp" alt="Plugin view showing Synaform collections" width="100%"></a><br>
      <sub><b>Plugins</b><br>Extend without forking</sub>
    </td>
    <td width="25%" align="center">
      <a href="docs/images/tour/admin-panel.webp"><img src="docs/images/tour/thumbs/admin-panel.webp" alt="Admin panel with system info and user counts" width="100%"></a><br>
      <sub><b>Admin</b><br>Users, usage and health</sub>
    </td>
    <td width="25%" align="center">
      <a href="docs/images/tour/branding.webp"><img src="docs/images/tour/thumbs/branding.webp" alt="White-label branding settings" width="100%"></a><br>
      <sub><b>Branding</b><br>White-label the whole app</sub>
    </td>
    <td width="25%"></td>
  </tr>
</table>

<sub>Regenerate these assets after a UI change with <code>scripts/build-readme-tour.sh</code>.</sub>

---

## Prerequisites

- **Docker** + **Docker Compose v2** (Docker Desktop on macOS/Windows, or Docker Engine + the Compose plugin on Linux)
- **Git**
- **8 GB RAM** minimum (16 GB recommended for the local-AI standard install)
- **~9 GB free disk** for the standard install (~5 GB for minimal, +~14 GB if you enable the local chat model)
- Free TCP ports `5173`, `8000`, `8082`, `8025`, `3307`, `6333`, `11435`

> **Apple Silicon (M1–M4) Macs — build the backend image, don't pull it.** The three-step start above already does this: `docker compose up -d` builds the backend and worker locally from a multi-arch base image, so PHP/FrankenPHP runs **natively on `arm64`** with no emulation tax. That is by far the fastest setup, and it is the default — you don't have to do anything special. The pre-built `ghcr.io/metadist/synaplan` image published for production deployments is `linux/amd64` only, so pulling it instead means running the whole backend under emulation. The first local build takes a few minutes; every later start is a cache hit. Two optional dev tools (phpMyAdmin, MailHog) are still amd64-only upstream images — if you keep them, enable **Docker Desktop → Settings → General → "Use Rosetta for x86/amd64 emulation on Apple Silicon"** (macOS 13+) so those two emulate quickly.

---

## Install Options

| Mode | Command | Size | Best For |
|------|---------|------|----------|
| **Standard** | `docker compose up -d` | ~9 GB | Full features, local embeddings (local chat model optional, +~14 GB) |
| **Minimal** | `docker compose -f docker-compose-minimal.yml up -d` | ~5 GB | Cloud AI only (Groq/OpenAI) |

The standard install downloads the local embedding model (`bge-m3`, ~1 GB) in the background for RAG and semantic search; progress is shown in the app.

Prefer the shell to the UI for provider keys? Keys in `backend/.env` still work — the backend reads that file when the container starts and imports the key into the encrypted store on first use. Write the key before starting, or restart the containers afterwards:

```bash
echo "GROQ_API_KEY=your_key" >> backend/.env
docker compose -f docker-compose-minimal.yml up -d
# already running? pick up the new key with:
# docker compose restart backend worker
```

---

## Access

| Service | URL |
|---------|-----|
| App | http://localhost:5173 |
| API | http://localhost:8000 |
| API Docs | http://localhost:8000/api/doc |
| phpMyAdmin | http://localhost:8082 |
| MailHog | http://localhost:8025 |

**Default Login Credentials:**

| Email | Password | Level |
|-------|----------|-------|
| admin@synaplan.com | admin123 | ADMIN |
| demo@synaplan.com | demo123 | PRO |
| test@example.com | test123 | NEW (unverified) |

---

## Features

- **AI Chat** — Ollama, OpenAI, Anthropic, Gemini, Groq, Mistral, xAI, TrustedTokens (DE), HuggingFace ([provider list](#ai-providers--models))
- **Multi-Task Routing** — An AI planner decomposes complex requests into a task graph (extract → summarize → generate → reply) and streams live task cards while the steps execute
- **RAG Search** — Semantic document search with MariaDB VECTOR or Qdrant
- **Chat Widget** — Embed on any website ([widget guide](https://docs.synaplan.com/index.php/widget))
- **iPhone App** — Chat, documents and voice input on iOS, pointed at web.synaplan.com or at your own server ([App Store](https://apps.apple.com/app/id6784278288?ct=github-readme))
- **Live Support** — Realtime WebSocket layer (Centrifugo + Redis): human takeover of widget chats, typing indicators, operator notifications ([realtime guide](docs/REALTIME.md))
- **WhatsApp** — Meta Business API integration
- **Email** — AI-powered email responses
- **Audio** — Whisper transcription (input) + optional [synaplan-tts](https://github.com/metadist/synaplan-tts) (output)
- **Documents** — PDF, Word, Excel, images with OCR
- **AI Memories** — User profiling with Qdrant vector search
- **Feedback System** — Feedback capture and analysis powered by Qdrant
- **Plugins** — Non-invasive plugin system ([plugin guide](https://docs.synaplan.com/index.php/plugins))
- **MCP Server** *(early access)* — Connect AI clients (Claude, Cursor, …) over the Model Context Protocol; your RAG and memories become tools at `POST /mcp` ([MCP guide](https://docs.synaplan.com/index.php/mcp))
- **MCP Client** *(early access)* — Connect *your* MCP servers (CRM, wiki, n8n, …) under **Channels → MCP Servers**; the multi-task planner pulls live data from them via `mcp_fetch` DAG nodes — read-only, SSRF-guarded, per-topic opt-in. Enabled by seeded `BCONFIG` flags (`MCP.CLIENT_ENABLED`, `MULTITASK.MCP_FETCH_ENABLED` — `app:seed` sets them ON on deploy; an explicit `0` row is the operator kill switch). See [docs/MULTITASK_DATA_NODES.md](docs/MULTITASK_DATA_NODES.md)
- **Claude Code & Anthropic-compatible API** — Point Claude Code or any Anthropic-protocol client at your instance (`POST /v1/messages`); configure under **Channels → AI Agents** ([guide](docs/ANTHROPIC_COMPATIBLE_API.md))

---

## AI Providers & Models

Synaplan is provider-neutral: connect the providers you want in **Admin → AI Providers** (keys are validated live and stored encrypted in the database, active without a restart), or set the env variables below in `backend/.env` — those are read at container start and imported into the encrypted store on first use. Each user picks a different model **per task** (chat, vision, image, video, audio, embeddings) — nothing is hardcoded.

| Provider | Variable in `backend/.env` | Models |
|----------|---------------------------|--------|
| OpenAI | `OPENAI_API_KEY` | GPT-5.6 Sol / Terra / Luna, GPT-5.5 (+ Pro), GPT-5.4 (+ mini / nano), GPT Image, Whisper, text-embedding-3 |
| Anthropic | `ANTHROPIC_API_KEY` | Claude Opus 5, Sonnet 5, Fable 5, Opus 4.8, Haiku 4.5 (chat + vision) |
| Google Gemini | `GOOGLE_GEMINI_API_KEY` | Gemini 3.x / 2.5 chat + vision, Imagen 4, Nano Banana, Veo 3.1, Gemini TTS |
| Groq | `GROQ_API_KEY` | Llama 3.3 70B, Llama 3.1 8B Instant, Qwen3 32B, GPT-OSS 20B/120B, Whisper Large v3 |
| Mistral 🇫🇷 | `MISTRAL_API_KEY` | Mistral Medium 3.5 (+ vision), Mistral Large 3, Voxtral transcription + TTS |
| xAI | `XAI_API_KEY` | Grok 4.5 (+ vision, 500K context), Grok Imagine image + video (incl. Pro / 1.5 tiers), Grok TTS + STT |
| [TrustedTokens](https://trustedtokens.eu/) 🇩🇪 | `TRUSTEDTOKENS_API_KEY` | GLM 5.2, Qwen3.6 35B (+ vision), GPT OSS 120B — sovereign inference on German GPUs (TNG), zero data retention |
| HuggingFace | `HUGGINGFACE_API_KEY` | Kimi K2.5 / K2.6 / K2.7 Code (chat + vision) |
| TheHive | `THEHIVE_API_KEY` | Flux Schnell, SDXL |
| Higgsfield | `HIGGSFIELD_API_KEY` + `HIGGSFIELD_API_SECRET` | Soul, Reve, DoP, Kling 2.1 |
| Cloudflare Workers AI | `CLOUDFLARE_ACCOUNT_ID` + `CLOUDFLARE_API_TOKEN` | bge-m3 embeddings (also usable as embedding fallback) |
| Ollama 🇩🇪 self-hosted | `OLLAMA_BASE_URL` (no key) | Any local model — chat, vision, bge-m3 embeddings |

**Transparent pricing.** Every model carries its provider's own rate (USD per 1M tokens in/out, or per image / second / character for media) — no proprietary credit unit in between. The selector shows a Free / Low / Mid / High cost badge next to each model and on every answer, `GET /api/v1/config/models` returns `priceIn` / `priceOut`, and the Statistics page logs the real cost of each call. On the hosted instance at [web.synaplan.com](https://web.synaplan.com/) that same catalog is what your plan meters against; self-hosted with Ollama, the per-token cost is simply zero. Details: [Model pricing & cost transparency](https://docs.synaplan.com/index.php/faq).

> Model catalog changes (new models, retired generations, price updates) ship as seeders plus a migration, so an existing install is repointed to a supported successor instead of silently keeping a dead model. See [docs/PRICING_MAINTENANCE.md](docs/PRICING_MAINTENANCE.md).

---

## Qdrant Vector Database

Qdrant runs as an internal Docker service — no configuration needed. It powers AI memories, RAG document search, and the feedback system.

Starts automatically with `docker compose up -d`. Synaplan works fully without it (memories and vector search will be disabled).

---

## Realtime & Background Processing

Both compose files also start three internal services (no host ports, no setup needed):

| Service | Role |
|---------|------|
| `redis` | Mandatory shared infrastructure: cache, sessions, locks, rate limits, message queues (Redis Streams), Centrifugo engine |
| `centrifugo` | WebSocket gateway for realtime features (live chat takeover, typing indicators, operator notifications) — browsers connect same-origin via `/connection/websocket` |
| `worker` | Symfony Messenger consumer that executes async jobs (AI processing, document indexing, widget crawling) |

In a multi-node cluster all nodes share one Redis, so WebSocket events published on one node reach browsers connected to any other. Details: [docs/REALTIME.md](docs/REALTIME.md).

---

## Text-to-Speech (Optional)

For voice output, run [synaplan-tts](https://github.com/metadist/synaplan-tts) alongside Synaplan:

```bash
git clone https://github.com/metadist/synaplan-tts.git && cd synaplan-tts && docker compose up -d
```

---

## Common Commands

```bash
# Logs
docker compose logs -f backend

# Restart
docker compose restart backend

# Reset database
docker compose down -v && docker compose up -d

# Run tests
make test

# Code quality
make lint
```

---

## Documentation

User-facing & API docs live at **[docs.synaplan.com](https://docs.synaplan.com/)**. Source: [`metadist/synaplan-docs`](https://github.com/metadist/synaplan-docs).

In-repo guides (for developers working on this codebase):

| Guide | Description |
|-------|-------------|
| [Installation](docs/INSTALLATION.md) | Detailed setup instructions |
| [Configuration](docs/CONFIGURATION.md) | Environment variables, API keys |
| [AI Model Pricing](docs/PRICING_MAINTENANCE.md) | Model catalog, provider prices, retiring a model |
| [Development](docs/DEVELOPMENT.md) | Commands, testing, architecture |
| [Realtime / WebSockets](docs/REALTIME.md) | Centrifugo + Redis realtime layer, multi-node deployment |
| [RAG System](docs/RAG.md) | Document search and processing |
| [Chat Widget](docs/WIDGET.md) | Embed chat on websites |
| [WhatsApp](docs/WHATSAPP.md) | Meta Business API setup |
| [Email](docs/EMAIL.md) | Email channel integration |
| [Anthropic-compatible API](docs/ANTHROPIC_COMPATIBLE_API.md) | Claude Code / Messages API gateway (`POST /v1/messages`) |

## Related Repositories

| Repo | Purpose |
|------|---------|
| [synaplan](https://github.com/metadist/synaplan) | Main app (this repo) |
| [synaplan-docs](https://github.com/metadist/synaplan-docs) | Public docs site (docs.synaplan.com) |
| [synaplan-tts](https://github.com/metadist/synaplan-tts) | Optional Piper TTS service |
| [synaplan-sortx](https://github.com/metadist/synaplan-sortx) | Document-sorting plugin + local tool |
| [synaplan-charts](https://github.com/metadist/synaplan-charts) | Helm charts for Kubernetes |
| [synaplan-platform](https://github.com/metadist/synaplan-platform) | Production deployment configs |

---

## Project Structure

```
synaplan/
├── backend/        # Symfony PHP API
├── frontend/       # Vue.js SPA
├── docs/           # Documentation
├── _docker/        # Docker configs
└── plugins/        # Plugin system
```

---

## Contributing

See [AGENTS.md](AGENTS.md) for development guidelines and code standards.

---

## License

[Apache-2.0](LICENSE)
