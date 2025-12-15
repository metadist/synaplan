# Synaplan - AI-Powered Knowledge Management System

AI-powered knowledge management with chat, document processing, and RAG (Retrieval-Augmented Generation).

## 🚀 Quick Start

### Prerequisites

- Docker & Docker Compose
- Git

### Installation

```bash
git clone <repository-url>
cd synaplan-dev
```

Run the first-install script for your platform (it verifies Docker, lets you pick your AI provider, and handles all bootstrapping):

```bash
# Linux / macOS (WSL): 
./_1st_install_linux.sh

# Windows (PowerShell or cmd):
_1st_install_win.bat
```

After the initial install, subsequent restarts only need:

```bash
docker compose up -d
```

**What happens automatically:**
- ✅ Creates `.env` from `.env.example` (Docker Compose variables)
- ✅ Creates `backend/.env` and `frontend/.env` (app-specific configs)
- ✅ Installs dependencies (Composer, npm)
- ✅ Generates JWT keypair for authentication
- ✅ Creates database schema (migrations)
- ✅ Loads test users and fixtures (if database is empty)
- ✅ Pulls the local `gpt-oss:20b` chat model and `bge-m3` embedding model with live progress (unless disabled)
- ✅ Ensures the schema + fixtures are applied (runs `doctrine:schema:update` + fixtures once)
- ✅ Starts all services
- ✅ **System ready in ~40 seconds!**

**First startup takes ~40 seconds** because:
- Database initialization: ~5s
- Schema creation: ~2s
- Fixtures loading: ~3s
- Cache warming: ~2s
- Total: ~40s (one-time setup)

**Subsequent restarts take ~15 seconds** (no fixtures needed).

**AI Model Download Behavior:**

`./_1st_install_linux.sh` (or `_1st_install_win.bat`) guides you through one of two options:

1. **Local Ollama** – downloads `gpt-oss:20b` (chat) + `bge-m3` (vector) so the stack runs fully offline (needs ~24 GB VRAM).
2. **Groq Cloud (recommended)** – prompts for your free `GROQ_API_KEY`, writes it to `backend/.env`, switches all defaults to Groq’s `llama-3.3-70b-versatile`, and skips the heavy local downloads.

Progress (downloads or schema work) streams directly in the script output, so you always know what’s happening.

**Option 1: Default Auto Download (Recommended)**
```bash
./_1st_install_linux.sh      # or _1st_install_win.bat
```
- ⚡ **Fast startup** for services (~40s) while downloads (if Ollama) or migrations (if Groq) run in the background
- 📦 **Progress shown live** in the script (tailing `backend` logs)
- ✅ **AI chat + RAG ready** as soon as the selected provider is configured
- 💡 **Best for**: Development/prod setups that either have local GPU (option 1) or prefer Groq’s hosted models (option 2)

**Option 2: On-demand Downloads**
```bash
AUTO_DOWNLOAD_MODELS=false docker compose up -d
```
- Pulling on-demand keeps UI usable immediately, but the first chat/search that needs a model will block until the download finishes.
- 📥 **Models pulled on first use** (login UI stays available meanwhile)
- 🔁 **Useful** for limited bandwidth / CI runners
- 📝 Monitor on-demand pulls with `docker compose logs -f backend | grep "[Background]"`

## 🌐 Access

| Service | URL | Description |
|---------|-----|-------------|
| Frontend | http://localhost:5173 | Vue.js Web App |
| Backend API | http://localhost:8000 | Symfony REST API |
| phpMyAdmin | http://localhost:8082 | Database Management |
| MailHog | http://localhost:8025 | Email Testing |
| Ollama | http://localhost:11435 | AI Models API |

## 👤 Test Users

| Email | Password | Level |
|-------|----------|-------|
| admin@synaplan.com | admin123 | BUSINESS |
| demo@synaplan.com | demo123 | PRO |
| test@example.com | test123 | NEW |

## 🧠 RAG System

The system includes a full RAG (Retrieval-Augmented Generation) pipeline:

- **Upload**: Multi-level processing (Extract Only, Extract + Vectorize, Full Analysis)
- **Extraction**: Tika (documents), Tesseract OCR (images), Whisper (audio)
- **Vectorization**: bge-m3 embeddings (1024 dimensions) via Ollama
- **Storage**: Native MariaDB VECTOR type with VEC_DISTANCE_COSINE similarity search
- **Search**: Semantic search UI with configurable thresholds and group filtering
- **Sharing**: Private by default, public sharing with optional expiry

## 🎙️ Audio Transcription

Audio files are automatically transcribed using **Whisper.cpp** when uploaded:

- **Supported formats**: mp3, wav, ogg, m4a, opus, flac, webm, aac, wma
- **Automatic conversion**: FFmpeg converts all audio to optimal format (16kHz mono WAV)
- **Models**: tiny, base (default), small, medium, large - configurable via `.env`
- **Setup**: 
  - **Docker**: Pre-installed, download models on first run
  - **Local**: Install [whisper.cpp](https://github.com/ggerganov/whisper.cpp) and FFmpeg, configure paths in `.env`

**Environment variables** (see `.env.example`):
```bash
WHISPER_BINARY=/usr/local/bin/whisper    # Whisper.cpp binary path
WHISPER_MODELS_PATH=/var/www/backend/var/whisper  # Model storage
WHISPER_DEFAULT_MODEL=base               # tiny|base|small|medium|large
WHISPER_ENABLED=true                     # Enable/disable transcription
FFMPEG_BINARY=/usr/bin/ffmpeg           # FFmpeg for audio conversion
```

If Whisper is unavailable, audio processing is skipped gracefully (no errors).

## 📱 WhatsApp Business API Integration

SynaPlan integrates with **Meta's official WhatsApp Business API** for bidirectional messaging.

### Setup:
1. **Create WhatsApp Business Account**: [Meta Business Suite](https://business.facebook.com/)
2. **Get Credentials**: Access Token, Phone Number ID, Business Account ID
3. **Set Environment Variables**:
```bash
WHATSAPP_ACCESS_TOKEN=your_access_token
WHATSAPP_PHONE_NUMBER_ID=your_phone_number_id
WHATSAPP_BUSINESS_ACCOUNT_ID=your_business_account_id
WHATSAPP_WEBHOOK_VERIFY_TOKEN=your_verify_token
WHATSAPP_ENABLED=true
```
4. **Configure Webhook in Meta**:
   - Callback URL: `https://your-domain.com/api/v1/webhooks/whatsapp`
   - Verify Token: Same as `WHATSAPP_WEBHOOK_VERIFY_TOKEN`
   - Subscribe to: `messages`

### Phone Verification (Required):
Users must verify their phone number via WhatsApp to unlock full features:
- **ANONYMOUS** (not verified): 10 messages, 2 images (very limited)
- **NEW** (verified): 50 messages, 5 images, 2 videos
- **PRO/TEAM/BUSINESS**: Full subscription limits

Verification Flow:
1. User enters phone number in web interface
2. 6-digit code sent via WhatsApp
3. User confirms code
4. Phone linked to account → full access
5. User can remove link anytime

### Supported Features:
- ✅ Text Messages (send & receive)
- ✅ Media Messages (images, audio, video, documents)
- ✅ Audio Transcription (via Whisper.cpp)
- ✅ Phone Verification System
- ✅ Full AI Pipeline (PreProcessor → Classifier → Handler)
- ✅ Rate Limiting per subscription level
- ✅ Message status tracking

### Message Flow:
```
WhatsApp User → Meta Webhook → /api/v1/webhooks/whatsapp
  → Message Entity → PreProcessor (files, audio transcription)
  → Classifier (sorting, tool detection) → InferenceRouter
  → AI Handler (Chat/RAG/Tools) → Response → WhatsApp
```

## 📧 Email Channel Integration

SynaPlan supports email-based AI conversations with smart chat context management.

### Email Addresses:
- **General**: `smart@synaplan.net` - Creates general chat conversation
- **Keyword-based**: `smart+keyword@synaplan.net` - Creates dedicated chat context
  - Example: `smart+project@synaplan.net` for project discussions
  - Example: `smart+support@synaplan.net` for support tickets

### Features:
- ✅ **Automatic User Detection**: Registered users get their own rate limits
- ✅ **Anonymous Email Support**: Unknown senders get ANONYMOUS limits
- ✅ **Chat Context**: Email threads become chat conversations
- ✅ **Spam Protection**: 
  - Max 10 emails/hour per unknown address
  - Automatic blacklisting for spammers
- ✅ **Email Threading**: Replies stay in the same chat context
- ✅ **Unified Rate Limits**: Same limits across Email, WhatsApp, Web

### How It Works:
```
User sends email to smart@synaplan.net
  → System checks if email is registered user
  → If yes: Use user's rate limits
  → If no: Create anonymous user with ANONYMOUS limits
  → Parse keyword from recipient (smart+keyword@)
  → Find or create chat context
  → Process through AI pipeline
  → Send response via email (TODO: requires SMTP)
```

### Rate Limits (Unified):
- **Registered User Email** = User's subscription limits
- **Unknown Email** = ANONYMOUS limits (10 messages total)
- **Spam Detection**: Auto-blacklist after 10 emails/hour

## 🔌 External Channel Integration (Generic)

The API also supports other external channels via webhooks authenticated with API keys:

### Setup:
1. **Create API Key**: `POST /api/v1/apikeys` (requires JWT login)
   ```json
   { "name": "Email Integration", "scopes": ["webhooks:*"] }
   ```
   Returns: `sk_abc123...` (store securely - shown only once!)

2. **Use Webhooks**: Send messages via API key authentication
   - Header: `X-API-Key: sk_abc123...` or
   - Query: `?api_key=sk_abc123...`

### Endpoints:
- **Email**: `POST /api/v1/webhooks/email`
- **WhatsApp**: `POST /api/v1/webhooks/whatsapp`
- **Generic**: `POST /api/v1/webhooks/generic`

Example (Email):
```bash
curl -X POST https://your-domain.com/api/v1/webhooks/email \
  -H "X-API-Key: sk_your_key" \
  -H "Content-Type: application/json" \
  -d '{
    "from": "user@example.com",
    "subject": "Question",
    "body": "Hello, how can I help?"
  }'
```

**Response**: AI-generated reply based on message content

### API Key Management:
- `GET /api/v1/apikeys` - List keys
- `POST /api/v1/apikeys` - Create key
- `PATCH /api/v1/apikeys/{id}` - Update (activate/deactivate)
- `DELETE /api/v1/apikeys/{id}` - Revoke key

## 📁 Project Structure

```
synaplan-dev/
├── _devextras/          # Development extras
├── _docker/             # Docker configurations
│   ├── backend/         # Backend Dockerfile & scripts
│   └── frontend/        # Frontend Dockerfile & nginx
├── backend/             # Symfony Backend (PHP 8.3)
├── frontend/            # Vue.js Frontend
└── docker-compose.yml   # Main orchestration
```

## ⚙️ Environment Configuration

Environment files are auto-generated on first start:
- `backend/.env.local` (auto-created by backend container, only if not exists)
- `frontend/.env.docker` (auto-created by frontend container)

**Note:** `.env.local` is never overwritten. To reset: delete the file and restart container.

Example files provided:
- `backend/.env.docker.example` (reference)
- `frontend/.env.docker.example` (reference)

### Required Configuration for Production

**`SYNAPLAN_URL`** (backend/.env): The publicly accessible URL where Synaplan is hosted
- Development: `http://localhost:8000`
- Production: `https://app.synaplan.com`
- Used for: Widget embed code generation, public URLs, CORS configuration

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

## 🤖 AI Models

- **gpt-oss:20b (Ollama)** – Pulled automatically by the first-install script (or any `docker compose up -d` with `AUTO_DOWNLOAD_MODELS=true`) so local chat is ready without extra steps. Progress prints as `[Background] [gpt-oss:20b] ...`.
- **bge-m3 (Ollama)** – Pulled alongside `gpt-oss:20b` during the first-install script so vector search works immediately; progress lines look like `[Background] [bge-m3] ...`.
- **All cloud models (Groq, OpenAI, etc.)** – Instantly available once their respective API keys are set.

Disable the auto download by running:
```bash
AUTO_DOWNLOAD_MODELS=false docker compose up -d
```

## ✨ Features

- ✅ **AI Chat**: Multiple providers (Ollama, OpenAI, Anthropic, Groq, Gemini)
- ✅ **Embeddable Chat Widget**: Add AI chat to any website with a single script tag
- ✅ **RAG System**: Semantic search with MariaDB VECTOR + bge-m3 embeddings (1024 dim)
- ✅ **Document Processing**: PDF, Word, Excel, Images (Tika + OCR)
- ✅ **Audio Transcription**: Whisper.cpp integration
- ✅ **File Management**: Upload, share (public/private), organize with expiry
- ✅ **App Modes**: Easy mode (simplified) and Advanced mode (full features)
- ✅ **Security**: Private files by default, secure sharing with tokens
- ✅ **Multi-user**: Role-based access with JWT authentication
- ✅ **Responsive UI**: Vue.js 3 + TypeScript + Tailwind CSS

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
  import SynaplanWidget from 'https://app.synaplan.com/widget.js'

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
