# Installation Guide

Complete installation instructions for Synaplan.

## Prerequisites

- **Docker** & **Docker Compose** (v2.0+)
- **Git**
- 8GB RAM minimum (16GB recommended for local AI)
- ~9GB disk space (Standard) or ~5GB (Minimal). Add ~14GB if you enable the local
  chat model (`ENABLE_LOCAL_GPT_OSS=true`, see [Standard Install](#standard-install-recommended))

> **Apple Silicon (M1–M4) Macs:** the images are `linux/amd64` only and run under
> emulation on Apple Silicon. Enable **Docker Desktop → Settings → General → "Use
> Rosetta for x86/amd64 emulation on Apple Silicon"** (macOS 13+) for a much faster
> experience than the default QEMU. See [Troubleshooting](#slow-performance-on-apple-silicon-mac).

## Quick Install

```bash
git clone <repository-url>
cd synaplan
docker compose up -d
```

That's it! Visit http://localhost:5173 after ~2 minutes, log in as
`admin@synaplan.com` / `admin123`, and connect a provider under
**Admin → AI Providers** (see [Connect an AI Provider](#connect-an-ai-provider)).

---

## Installation Modes

### Standard Install (Recommended)

Full-featured installation with local AI models and audio transcription.

| Component | Size | Description |
|-----------|------|-------------|
| Base services | ~5 GB | Backend, frontend, worker, database, Redis, Centrifugo, Tika, Qdrant |
| Ollama embedding model | ~4 GB | `bge-m3` for RAG / semantic search |
| **Total** | **~9 GB** | Everything except the local chat model |

```bash
docker compose up -d
```

**Local chat model is opt-in.** The standard install downloads the embedding model
only. A local chat model (`gpt-oss:20b`, another ~14 GB, needs a GPU or a strong
CPU box) is pulled only when you ask for it:

```bash
ENABLE_LOCAL_GPT_OSS=true docker compose up -d
```

Until then — or while the download runs — chat needs a cloud provider key
(see [Connect an AI Provider](#connect-an-ai-provider)).

**What's included:**
- Full web app and REST API
- Document processing (Apache Tika)
- MariaDB database with VECTOR support
- Redis (cache, sessions, locks, message queues, realtime engine)
- Centrifugo WebSocket gateway (live chat takeover, realtime events)
- Background worker (Symfony Messenger consumer for async AI/indexing jobs)
- Local Ollama server (embedding model downloaded automatically, chat model opt-in)
- Whisper audio transcription
- Cloud AI support (Groq, OpenAI, Anthropic, Gemini, xAI, …)
- Qdrant vector database (AI memories, RAG, feedback)
- Dev tools (phpMyAdmin, MailHog)

### Minimal Install (Cloud AI Only)

Fastest way to start—uses cloud AI providers, skips large local models.

| Component | Size | Description |
|-----------|------|-------------|
| Base services | ~5 GB | Backend, frontend, worker, database, Redis, Centrifugo, Tika |
| **Total** | **~5 GB** | No local AI models (`AUTO_DOWNLOAD_MODELS=false`) |

```bash
# Start minimal stack
docker compose -f docker-compose-minimal.yml up -d
```

**Excluded (saves ~4 GB):**
- Ollama (local AI models)
- Whisper models (audio transcription)
- Local embedding models

**Upgrade to Standard later:**
```bash
docker compose -f docker-compose-minimal.yml down
docker compose up -d
```

---

## Connect an AI Provider

After `docker compose up -d`, log in as `admin@synaplan.com` / `admin123` and open
**Admin → AI Providers** (`http://localhost:5173/admin/setup`).

- Paste a cloud key (free tier: [console.groq.com](https://console.groq.com)) — it is
  tested live, stored encrypted in the database, and works immediately (no restart).
- Or run chat on local Ollama — start the stack with `ENABLE_LOCAL_GPT_OSS=true` and
  wait for the model download to finish (progress is shown in the app and in
  `docker compose logs -f backend`).
- Optional: put keys in `backend/.env` for scripted deploys. The backend reads `.env`
  at container start and imports the key into the encrypted store on first use, so
  restart the container after editing the file.

```bash
# Optional env bootstrap (UI is preferred)
echo "GROQ_API_KEY=your_key_here" >> backend/.env
docker compose restart backend worker
```

> An optional interactive helper lives at `_devextras/_1st_install_linux.sh`. It
> asks the same questions and then does exactly what this guide describes; the
> manual path above stays the reference.

---

## What Happens Automatically

On first start, the system:

1. Creates `backend/.env` from template
2. Installs dependencies (Composer, npm)
3. Generates JWT keypair for authentication
4. Creates database schema
5. Loads test fixtures (if database is empty)
6. Downloads the Ollama embedding model in the background — standard install only,
   and only while `AUTO_DOWNLOAD_MODELS=true` (the Minimal stack sets it to `false`)
7. Points chat at a provider that has a usable key, if the default one has none
8. Starts all services

**First startup:** ~1-2 minutes  
**Subsequent restarts:** ~15-30 seconds

## Default Login Credentials

After first startup, the following test accounts are available:

| Email | Password | Level |
|-------|----------|-------|
| admin@synaplan.com | admin123 | ADMIN |
| demo@synaplan.com | demo123 | PRO |
| test@example.com | test123 | NEW (unverified) |

Log in at http://localhost:5173 with any of these accounts. The admin account has full access to all settings.

---

## Line Endings (Windows Users)

This project enforces LF (Unix-style) line endings. If you cloned before `.gitattributes` was added:

```bash
git rm --cached -r .
git reset --hard
```

---

## Troubleshooting

### Services won't start
```bash
# Check logs
docker compose logs -f

# Restart everything
docker compose down
docker compose up -d
```

### Database connection issues
```bash
# Wait for MariaDB to be ready
docker compose logs db

# Reset database completely
docker compose down -v
docker compose up -d
```

### Model download stuck
```bash
# Check Ollama logs
docker compose logs ollama

# Manually pull model
docker compose exec ollama ollama pull bge-m3
```

### Slow performance on Apple Silicon (Mac)

Synaplan's container images are `linux/amd64` only, so on Apple Silicon (M1–M4)
they run under emulation. For the best experience:

- Enable **Docker Desktop → Settings → General → "Use Rosetta for x86/amd64
  emulation on Apple Silicon"** (macOS 13+). Rosetta is significantly faster than
  the default QEMU translation.
- The first `docker compose up -d` is slower because the backend image is built
  locally under emulation; subsequent restarts are fast.
- `docker compose` may print a platform-mismatch warning
  (`requested image's platform (linux/amd64) does not match ...`) — it is expected
  and safe to ignore.

### Port conflicts
Default host ports: 5173 (frontend), 8000 (backend), 3307 (database), 8082 (phpMyAdmin), 8025/1025 (MailHog), 6333 (Qdrant), 11435 (Ollama), 9999 (Tika)

Redis, Centrifugo, and the worker are internal-only services — they expose no host ports and cannot conflict.

Edit `docker-compose.yml` to change ports if needed.

### Worker / queue issues
Async jobs (AI processing, document indexing) run in the `worker` container:

```bash
# Worker logs — should show "messenger:consume" processing messages
docker compose logs -f worker

# Realtime gateway logs
docker compose logs -f centrifugo

# Redis connectivity
docker compose exec redis redis-cli ping   # expects PONG
```

---

## Next Steps

- [Configuration Guide](CONFIGURATION.md) - API keys, environment variables
- [Features Overview](FEATURES.md) - What Synaplan can do
- [Development Guide](DEVELOPMENT.md) - Contributing and testing
