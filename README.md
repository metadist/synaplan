# Synaplan

AI-powered knowledge management with RAG, chat widgets, and multi-channel integration.

[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)

> **Live instance**: [web.synaplan.com](https://web.synaplan.com/)

![Synaplan Dashboard](docs/images/model-select.png)

---

## Prerequisites

- **Docker** + **Docker Compose v2** (Docker Desktop on macOS/Windows, or Docker Engine + the Compose plugin on Linux)
- **Git**
- **8 GB RAM** minimum (16 GB recommended for the local-AI standard install)
- **~9 GB free disk** for the standard install (~5 GB for minimal)
- Free TCP ports `5173`, `8000`, `8082`, `8025`, `3307`, `6333`, `11435`

## Quick Start

```bash
git clone https://github.com/metadist/synaplan.git
cd synaplan
docker compose up -d
```

Open http://localhost:5173 — the **UI is ready in ~2 minutes**. With the standard install, local Ollama models (`gpt-oss:20b`, `bge-m3`, ~14 GB total) continue downloading in the background — chat that uses local AI will start working once that download finishes (`docker compose logs -f backend` shows progress). For the fastest first experience, use the [Minimal](#install-options) install below.

---

## Install Options

| Mode | Command | Size | Best For |
|------|---------|------|----------|
| **Standard** | `docker compose up -d` | ~9 GB | Full features, local AI |
| **Minimal** | `docker compose -f docker-compose-minimal.yml up -d` | ~5 GB | Cloud AI only (Groq/OpenAI) |

For the minimal install, set your API key **before** starting the stack so the first boot already sees it (avoids a restart). Get a free key at [console.groq.com](https://console.groq.com):

```bash
echo "GROQ_API_KEY=your_key" >> backend/.env
docker compose -f docker-compose-minimal.yml up -d
```

Already started without a key? Add it and restart the backend:
```bash
echo "GROQ_API_KEY=your_key" >> backend/.env && docker compose restart backend
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

- **AI Chat** — Ollama, OpenAI, Anthropic, Groq, Gemini
- **RAG Search** — Semantic document search with MariaDB VECTOR or Qdrant
- **Chat Widget** — Embed on any website
- **WhatsApp** — Meta Business API integration
- **Email** — AI-powered email responses
- **Audio** — Whisper transcription
- **Documents** — PDF, Word, Excel, images with OCR
- **AI Memories** — User profiling with Qdrant vector search
- **Feedback System** — False-positive detection and learning

---

## Qdrant Vector Database

Qdrant runs as an internal Docker service — no configuration needed. It powers AI memories, RAG document search, and the feedback system.

Starts automatically with `docker compose up -d`. Synaplan works fully without it (memories and vector search will be disabled).

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

| Guide | Description |
|-------|-------------|
| [Installation](docs/INSTALLATION.md) | Detailed setup instructions |
| [Configuration](docs/CONFIGURATION.md) | Environment variables, API keys |
| [Development](docs/DEVELOPMENT.md) | Commands, testing, architecture |
| [RAG System](docs/RAG.md) | Document search and processing |
| [Chat Widget](docs/WIDGET.md) | Embed chat on websites |
| [WhatsApp](docs/WHATSAPP.md) | Meta Business API setup |
| [Email](docs/EMAIL.md) | Email channel integration |

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
