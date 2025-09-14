# Synaplan — AI Communication Management Platform

Synaplan is an open-source platform to orchestrate conversations with multiple AI providers across channels (web, email, WhatsApp), with auditing, usage tracking, and vector search.

## 🚀 Dev Setup

### Prerequisites

- **docker compose ≥ v2.20**
- **npm (of Node.js)** (for frontend dependencies)

Image/PDF processing for file extraction uses Imagick and Poppler (`pdftoppm`). These are installed in the dev Docker image automatically. If you run without Docker, install: `php-imagick`, `imagemagick`, and `poppler-utils`.

for a dockerless installation, see below.

#### 1. Download source code
```bash
# Clone or download the repository
git clone https://github.com/orgaralf/synaplan.git synaplan/
cd synaplan
```

#### 2. Start the complete environment

```bash
docker compose up -d
```

**🎯 That's it!** The system automatically:
- Downloads and installs all dependencies (Composer + NPM)
- Downloads Whisper models (~3GB) for audio transcription
- Downloads Ollama AI models (llama3.2:3b, mistral:7b, codellama:7b)
- Starts all services with proper health checks

**First-time setup:** The initial download of models may take several minutes. Monitor progress with:
```bash
# Monitor Whisper model downloads
docker compose logs -f whisper-models

# Monitor Ollama model downloads  
docker compose logs -f ollama
```

**Subsequent starts:** All models are cached and startup is fast.

#### Rebuild after changing PHP extensions (e.g., Imagick)
```bash
docker compose build app
docker compose up -d app
```

#### 3. Set File Permissions
```bash
# Create upload directory and set permissions
mkdir -p public/up/
chmod 755 public/up/
```

#### 4. Configure Environment
Create a `.env` file in the project root directory with your API keys:

```env
# AI Service API Keys (configure at least one)
GROQ_API_KEY=your_groq_api_key_here
OPENAI_API_KEY=your_openai_api_key_here
GOOGLE_GEMINI_API_KEY=your_gemini_api_key_here
OLLAMA_URL=http://localhost:11434  # If using local Ollama

# Document Processing
# Point to Apache Tika server. Default (when using Docker) resolves to internal service 'tika:9998'.
# To use a remote Tika instance, set a full URL here (e.g., https://tika.mycorp.internal:9998)
TIKA_URL=https://your-remote-tika:9998
# Optional tuning; these have sensible defaults if omitted
TIKA_TIMEOUT_MS=20000
TIKA_RETRIES=2
TIKA_RETRY_BACKOFF_MS=500
TIKA_MIN_LENGTH=200
TIKA_MIN_ENTROPY=2.5
TIKA_OCR_SIGNAL_MIN=1
RASTERIZE_DPI=150
RASTERIZE_PAGE_CAP=5
RASTERIZE_TIMEOUT_MS=60000

# Database Configuration (if different from defaults)
DB_HOST=localhost
DB_NAME=synaplan
DB_USER=synaplan
DB_PASS=synaplan

# Other Configuration
DEBUG=false
```

**⚠️ CRITICAL SECURITY WARNING:** The `.env` file contains sensitive information and **MUST NOT** be accessible via web requests in production environments. Ensure your web server configuration blocks access to `.env` files:

**Recommended AI Service:** We recommend [Groq.com](https://groq.com) as a cost-effective, super-fast AI service for production use.

#### 5. Update Configuration Paths
If you're not installing in `/wwwroot/synaplan/public/`, update URLs in `app/inc/config/_confsys.php`:

```php
// Update these values to match your installation path
$devUrl = "http://localhost/your-path/public/";
$liveUrl = "https://your-domain.com/";
```

#### 6. Verify Installation
1. Point your browser to [http://localhost:8080](http://localhost:8080)
2. You should see a login page
3. Login with the default credentials:
   - **Username:** synaplan@synaplan.com
   - **Password:** synaplan

### Install on a standard Linux server (no Docker)

You can also deploy Synaplan on a regular Linux server using Apache, PHP 8.3, and MariaDB 11.7+ (required for vector search). This is ideal when you rely on 3rd‑party AI APIs (OpenAI, Groq, Gemini) instead of local models.

1. Install prerequisites
   - Apache (or any web server) configured to serve the `public/` directory as the document root
   - PHP 8.3 with extensions: `mysqli`, `mbstring`, `curl`, `json`, `zip`
   - MariaDB 11.7+ (for vector search features)
2. Deploy code
   - Place the repository on the server and point your vhost to the `public/` directory
3. Install PHP deps and frontend assets
   - `composer install` (if you encounter timeout issues, use `COMPOSER_PROCESS_TIMEOUT=1600 composer install`)
   - `cd public && npm ci && cd ..`
4. Database
   - Create a database (e.g., `synaplan`) and user
   - Import SQL files from `dev/db-loadfiles/` into the database
5. Environment configuration
   - Create a `.env` in the project root with your API keys and DB settings (see the `.env` example above)
6. File permissions
   - Ensure `public/up/` exists with writable permissions for the web server user
7. App URLs
   - Adjust `$devUrl` and `$liveUrl` in `app/inc/config/_confsys.php` to match your domains/paths
8. Test
   - Open your site (e.g., `https://your-domain/`) and log in with the default credentials above

### Features
- Multiple AI providers (OpenAI, Gemini, Groq, Ollama)
- Channels: Web widget, Gmail business, WhatsApp
- Vector search (MariaDB 11.7+), built-in RAG
- Local audio transcription via whisper.cpp
- Full message logging and usage tracking

### Architecture (brief)
```
app/
├─ director.php                  # Front-controller (includes frontend content)
└─ inc/
   ├─ config/                    # _confsys.php, _confdb.php, _confkeys.php, _confdefaults.php
   ├─ api/                       # ApiRouter.php, ApiAuthenticator.php, procedural endpoints (included by public/api.php)
   ├─ ai/                        # providers/, core/
   ├─ domain/                    # domain logic (e.g., files)
   ├─ mail/                      # email services
   ├─ support/                   # tools, helpers
   └─ _frontend.php              # legacy helpers used by UI

frontend/
├─ c_chat.php, c_login.php, c_prompts.php, c_inbound.php, ...

public/
├─ index.php                     # Web entry point (uses Composer + _coreincludes)
├─ api.php                       # API entry point
├─ widget.php / widgetloader.php # Embeddable widget endpoints
├─ assets/statics/
│  ├─ css/dashboard.css
│  ├─ js/ (chat.js, chathistory.js, speech.js, dashboard.js)
│  ├─ fa/ (css, webfonts, svgs)
│  └─ img/ (ai-logos/, etc.)
├─ up/                           # Uploaded files (served by web)
└─ webhookwa.php                 # WhatsApp handler

dev/
├─ docker/                       # Dockerfiles
└─ db-loadfiles/                 # SQL init data

cron/
└─ mailhandler.php               # CLI cron for Gmail processing
```
Configuration-driven AI selection via `$GLOBALS` and centralized key management in `app/inc/config/_confkeys.php`.

### API & Integrations
- REST endpoints, embeddable web widget, Gmail and WhatsApp integrations.

### Troubleshooting
- Vector search: ensure MariaDB 11.7+
- Uploads: check `public/up/` permissions
- AI calls: verify API keys in `.env` (project root)
- DB errors: verify credentials and service status
- **Whisper models:** If you need to re-download models, delete the volume and restart:
  ```bash
  docker compose down
  docker volume rm synaplan_whisper_models
  docker compose up -d
  ```
- **Ollama models:** If you need to re-download AI models, delete the volume and restart:
  ```bash
  docker compose down
  docker volume rm synaplan_ollama_data
  docker compose up -d
  ```
- **All dependencies:** If you need to reinstall Composer/NPM dependencies:
  ```bash
  docker compose down
  docker volume rm synaplan_vendor synaplan_node_modules
  docker compose up -d
  ```

#### Tika & File Processing
- To use local Tika in Docker: run `docker compose up -d` (service `tika` runs internally) and leave `TIKA_URL` unset or set to `http://tika:9998`.
- To use a remote Tika: set `TIKA_URL` in `.env` to the remote base URL. No code changes required.
- The app logs the resolved Tika endpoint at startup (sanitized). Check container logs if connectivity fails.
- Timeouts, retries, and quality thresholds are configurable via env (see Document Processing section above).
- Rendering/Rasterization: The container installs `imagick` and `poppler-utils (pdftoppm)` for PDF-to-PNG conversion used by the vision fallback.

### Cron (mail handler)
- Run once without starting services:
  ```bash
  docker compose run --rm app php cron/mailhandler.php --user=2 --debug
  ```
- Or exec into a running container:
  ```bash
  docker compose exec app php cron/mailhandler.php --user=2 --debug
  ```

### Contributing
PRs welcome for providers, channels, docs, and performance. Start from `app/Director.php` and `frontend/` components, and follow existing patterns.

### License
See "LICENSE": Apache 2.0 real open core, because we love it!


