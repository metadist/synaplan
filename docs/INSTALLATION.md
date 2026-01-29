# Installation Guide

Complete installation instructions for Synaplan.

## Prerequisites

- **Docker** & **Docker Compose** (v2.0+)
- **Git**
- 8GB RAM minimum (16GB recommended for local AI)
- 20GB disk space (Standard) or 10GB (Minimal)

## Quick Install

```bash
git clone <repository-url>
cd synaplan
docker compose up -d
```

That's it! Visit http://localhost:5173 after ~2 minutes.

---

## Installation Modes

### Standard Install (Recommended)

Full-featured installation with local AI models and audio transcription.

| Component | Size | Description |
|-----------|------|-------------|
| Base services | ~5 GB | Backend, frontend, database, Tika |
| Ollama models | ~4 GB | Local AI (gpt-oss:20b, bge-m3) |
| **Total** | **~9 GB** | Everything included |

```bash
# Option 1: Docker Compose directly
docker compose up -d

# Option 2: Guided install script (Linux/macOS/WSL2)
./_1st_install_linux.sh
```

**What's included:**
- Full web app and REST API
- Document processing (Apache Tika)
- MariaDB database with VECTOR support
- Local Ollama AI models
- Whisper audio transcription
- Cloud AI support (Groq, OpenAI, Anthropic, Gemini)
- Dev tools (phpMyAdmin, MailHog)

### Minimal Install (Cloud AI Only)

Fastest way to start—uses cloud AI providers, skips large local models.

| Component | Size | Description |
|-----------|------|-------------|
| Base services | ~5 GB | Backend, frontend, database, Tika |
| **Total** | **~5 GB** | No local AI models |

```bash
# Start minimal stack
docker compose -f docker-compose-minimal.yml up -d

# Add your API key (get free key at https://console.groq.com)
echo "GROQ_API_KEY=your_key_here" >> backend/.env

# Restart to apply
docker compose restart backend
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

## Install Script Options

The `_1st_install_linux.sh` script offers two AI configurations:

### Option 1: Local Ollama (Offline Capable)

- Downloads `gpt-oss:20b` (~12GB) for chat
- Downloads `bge-m3` (~1.5GB) for embeddings
- Requires ~24GB VRAM for full performance
- Works completely offline after setup

### Option 2: Groq Cloud (Recommended)

- Prompts for free `GROQ_API_KEY`
- Uses `llama-3.3-70b-versatile` for chat
- Only downloads `bge-m3` for local embeddings
- Fastest setup, excellent performance

---

## What Happens Automatically

On first start, the system:

1. Creates `backend/.env` from template
2. Installs dependencies (Composer, npm)
3. Generates JWT keypair for authentication
4. Creates database schema
5. Loads test fixtures (if database is empty)
6. Pulls required AI models
7. Starts all services

**First startup:** ~1-2 minutes  
**Subsequent restarts:** ~15-30 seconds

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

### Port conflicts
Default ports: 5173 (frontend), 8000 (backend), 3306 (database)

Edit `docker-compose.yml` to change ports if needed.

---

## Next Steps

- [Configuration Guide](CONFIGURATION.md) - API keys, environment variables
- [Features Overview](FEATURES.md) - What Synaplan can do
- [Development Guide](DEVELOPMENT.md) - Contributing and testing
