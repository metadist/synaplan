# Synaplan

AI-powered knowledge management with RAG, chat widgets, and multi-channel integration.

[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)

> **Live instance**: [web.synaplan.com](https://web.synaplan.com/)

![Synaplan Dashboard](docs/images/dashboard.png)

---

## Quick Start

```bash
git clone <repository-url>
cd synaplan
docker compose up -d
```

Open http://localhost:5173 — ready in ~2 minutes.

---

## Install Options

| Mode | Command | Size | Best For |
|------|---------|------|----------|
| **Standard** | `docker compose up -d` | ~9 GB | Full features, local AI |
| **Minimal** | `docker compose -f docker-compose-minimal.yml up -d` | ~5 GB | Cloud AI only (Groq/OpenAI) |

For minimal install, add your API key:
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
- **RAG Search** — Semantic document search with MariaDB VECTOR (or [Qdrant](https://github.com/metadist/synaplan-memories) as alternative)
- **Chat Widget** — Embed on any website
- **WhatsApp** — Meta Business API integration
- **Email** — AI-powered email responses
- **Audio** — Whisper transcription
- **Documents** — PDF, Word, Excel, images with OCR
- **AI Memories** — Optional user profiling (see below)

---

## Optional: AI Memories

> **Want the AI to remember user preferences and context across sessions?**

Install [**synaplan-memories**](https://github.com/metadist/synaplan-memories) — a separate Docker stack with a Rust microservice + Qdrant vector database.

```bash
# Clone and start the memories service
git clone https://github.com/metadist/synaplan-memories
cd synaplan-memories
docker compose up -d

# Then connect Synaplan to it (in synaplan/backend/.env)
QDRANT_SERVICE_URL=http://synaplan-qdrant-service:8090
QDRANT_SERVICE_API_KEY=your_secret_key
```

This is **completely optional** — Synaplan works fully without it. Licensed under [Apache-2.0](https://github.com/metadist/synaplan-memories/blob/main/LICENSE).

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
synaplan-dev/
├── _devextras/              # Development extras
├── _docker/                 # Docker configurations
│   ├── backend/             # Backend Dockerfile & scripts
│   └── frontend/            # Frontend Dockerfile & nginx
├── backend/                 # Symfony Backend (PHP 8.3)
├── frontend/                # Vue.js Frontend
├── docker-compose.yml       # Standard install (Ollama + Whisper, recommended)
└── docker-compose-minimal.yml # Minimal install (cloud AI only)
```

## ⚙️ Environment Configuration

Environment files are auto-generated on first start:
- `backend/.env` (created from `.env.example` by install script, stores API keys and server settings)

**Note:** `backend/.env` is never overwritten if it exists. To reset: delete the file and run the install script again.

Example files provided:
- `backend/.env.example` (reference)

### Required Configuration for Production

**`SYNAPLAN_URL`** (backend/.env): The publicly accessible URL where Synaplan is hosted
- Development: `http://localhost:8000`
- Production: `https://web.synaplan.com`
- Used for: Widget embed code generation, public URLs, CORS configuration

## 📚 Developer Documentation

For technical deep-dives and "vibe coding" guides, check the `_devextras/planning/` directory. These documents are kept up to date and cover:
- **Authentication**: Cookie-based & OIDC flows.
- **Plugins**: Scalable user plugin architecture.
- **Development**: Coding standards and quick commands.
- **Infrastructure**: WSL and Ubuntu setup guides.

## 🛠️ Development

```bash
# View logs
docker compose logs -f

# Restart services
docker compose restart backend
docker compose restart frontend

# Reset database (deletes all data!)
docker compose down -v
docker compose up -d

# Run migrations
docker compose exec backend php bin/console doctrine:migrations:migrate

# Install packages
docker compose exec backend composer require <package>
docker compose exec frontend npm install <package>
```

## ✅ Tests

For details on running the Playwright E2E tests (browser-based UI tests), see the dedicated README in the frontend:

- `frontend/tests/e2e/README.md`


## 🤖 AI Models

- **bge-m3 (Ollama)** – Always pulled during install (required for RAG/vector search). This is a small embedding model (~1.5GB).
- **gpt-oss:20b (Ollama)** – Only pulled if "Local Ollama" option selected during install. Large model (~12GB) for local chat without API keys.
- **All cloud models (Groq, OpenAI, etc.)** – Instantly available once their respective API keys are set. Groq is recommended (free tier, fast).

Disable the auto download by running:
```bash
AUTO_DOWNLOAD_MODELS=false docker compose up -d
```

## ✨ Features

- ✅ **AI Chat**: Multiple providers (Ollama, OpenAI, Anthropic, Groq, Gemini)
- ✅ **Embeddable Chat Widget**: Add AI chat to any website with a single script tag
- ✅ **RAG System**: Semantic search with MariaDB VECTOR + bge-m3 embeddings (1024 dim)
- ✅ **Optional Memories Backend (Qdrant)**: Connect [synaplan-memories](https://github.com/metadist/synaplan-memories) to enable user profiling / AI memories (separate Rust + Qdrant stack)
- ✅ **Document Processing**: PDF, Word, Excel, Images (Tika + OCR)
- ✅ **Audio Transcription**: Whisper.cpp integration
- ✅ **File Management**: Upload, share (public/private), organize with expiry
- ✅ **App Modes**: Easy mode (simplified) and Advanced mode (full features)
- ✅ **Security**: Private files by default, secure sharing with tokens
- ✅ **Multi-user**: Role-based access with JWT authentication
- ✅ **Responsive UI**: Vue.js 3 + TypeScript + Tailwind CSS

## 🧠 Optional: synaplan-memories (User Profiling / AI Memories)

Synaplan can run without an external vector DB for memories.

If you want AI “memories” (user profiling) backed by **Qdrant**, install:

- [metadist/synaplan-memories](https://github.com/metadist/synaplan-memories)

It contains a small Rust microservice + Qdrant and can be connected via:

- `QDRANT_SERVICE_URL`
- `QDRANT_SERVICE_API_KEY`

Benchmarking (Qdrant vs MariaDB VECTOR) is available here:

- [metadist/synaplan-vectordb-test](https://github.com/metadist/synaplan-vectordb-test)

## 💬 Embeddable Chat Widget

Synaplan includes a production-ready chat widget that can be embedded on any website:

### Features
- **ES Module with Code-Splitting**: Loads only what's needed, when needed
- **Lazy Loading**: Button loads first, chat loads on click
- **Automatic Configuration**: Fetches widget settings from server
- **Customizable**: Colors, icons, position, themes, auto-messages
- **Smart API Detection**: Automatically detects the correct API URL from script source
- **CORS-ready**: Designed to work across domains

### Usage Example
```html
<script type="module">
  import SynaplanWidget from 'https://web.synaplan.com/widget.js'

  SynaplanWidget.init({
    widgetId: 'wdg_abc123',
    position: 'bottom-right',
    primaryColor: '#007bff',
    lazy: true
  })
</script>
```

### Widget Management
- Create widgets in the web interface (Widgets section)
- Configure appearance, behavior, and limits
- Domain whitelisting for security
- Rate limiting per subscription level
- Copy embed code directly from UI

### Building Widgets (Development)
```bash
cd frontend
npm run build:widget    # Builds widget to dist-widget/
```

The widget build is automatically included in CI/CD and Docker images.

## 📄 License

See [LICENSE](LICENSE)
