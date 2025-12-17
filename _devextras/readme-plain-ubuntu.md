# Synaplan plain Ubuntu setup

Short checklist for running the Symfony backend and Vite/Vue frontend directly on Ubuntu 22.04/24.04 without Docker. All commands are copy‑paste ready.

---

## 1. Base system packages
```bash
sudo apt update && sudo apt install -y ca-certificates curl gnupg lsb-release software-properties-common unzip git make build-essential pkg-config composer
```

## 2. PHP 8.3/8.4 + Apache (replacement for FrankenPHP)

### For PHP 8.3:
```bash
sudo add-apt-repository ppa:ondrej/php -y && sudo apt update
sudo apt install -y apache2 libapache2-mod-fcgid php8.3 php8.3-cli php8.3-fpm php8.3-common php8.3-mysql php8.3-xml php8.3-mbstring php8.3-intl php8.3-zip php8.3-gd php8.3-curl php8.3-bcmath php8.3-exif php8.3-pcntl php8.3-opcache php8.3-readline php8.3-soap php8.3-sodium php8.3-ffi php8.3-imagick php8.3-imap php8.3-redis
```

### For PHP 8.4 (Ubuntu 24.04+):
```bash
sudo apt install -y apache2 libapache2-mod-fcgid php8.4 php8.4-cli php8.4-fpm php8.4-common php8.4-mysql php8.4-xml php8.4-mbstring php8.4-intl php8.4-zip php8.4-gd php8.4-curl php8.4-bcmath php8.4-exif php8.4-pcntl php8.4-opcache php8.4-readline php8.4-soap php8.4-sodium php8.4-ffi php8.4-imagick php8.4-imap php8.4-redis
```

### Common dependencies (both versions):
```bash
sudo apt install -y ghostscript imagemagick libmagickwand-dev poppler-utils ffmpeg libavcodec-extra libavformat-dev libavutil-dev libswscale-dev libavfilter-dev libswresample-dev libx264-dev libx265-dev libvpx-dev libmp3lame-dev libopus-dev libsodium-dev libffi-dev libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libxml2-dev libonig-dev cmake gfortran libopenblas-dev liblapack-dev protobuf-compiler
```

### Enable Apache modules:
```bash
# For PHP 8.3:
sudo a2enmod proxy proxy_fcgi setenvif rewrite headers expires ssl alias && sudo a2enconf php8.3-fpm && sudo systemctl reload apache2

# For PHP 8.4:
sudo a2enmod proxy proxy_fcgi setenvif rewrite headers expires ssl alias && sudo a2enconf php8.4-fpm && sudo systemctl reload apache2
```

> **Important:** Note your PHP-FPM socket path - it differs by version:
> - PHP 8.3: `/run/php/php8.3-fpm.sock`
> - PHP 8.4: `/run/php/php8.4-fpm.sock`
> 
> Check your actual socket: `ls -la /run/php/`

### PHP configuration
Create `/etc/php/8.X/fpm/conf.d/99-synaplan.ini` (replace 8.X with your version):
```ini
upload_max_filesize=128M
post_max_size=128M
max_file_uploads=50
memory_limit=512M
```

Restart PHP-FPM:
```bash
sudo systemctl restart php8.4-fpm  # or php8.3-fpm
```

## 3. Node.js toolchain (frontend + Vite)
```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

Alternatively, use pnpm (faster):
```bash
npm install -g pnpm
```

## 4. Optional helpers
```bash
sudo apt install -y redis-server supervisor
```
*(Use Redis for Messenger transports if you don't want to rely on MariaDB queues; use Supervisor/systemd to keep workers alive.)*

## 5. Local Apache Tika (optional)
```bash
sudo apt install -y default-jre-headless
sudo mkdir -p /wwwroot/synaplan/services/tika && cd /wwwroot/synaplan/services/tika
curl -fL -o tika-server.jar https://dlcdn.apache.org/tika/2.9.2/tika-server-standard-2.9.2.jar \
  || curl -fL -o tika-server.jar https://archive.apache.org/dist/tika/2.9.2/tika-server-standard-2.9.2.jar
sha256sum tika-server.jar             # optional integrity check
nohup java -jar tika-server.jar --host 0.0.0.0 --port 9998 >/wwwroot/synaplan/services/tika/tika.log 2>&1 &
sleep 3 && curl -fsS http://127.0.0.1:9998/tika | head -n1   # should print the HTML banner
```
*Stop the background copy any time with `pkill -f tika-server.jar`. A "Please PUT" banner in the curl response confirms the server is ready.*

**Systemd autostart (optional)**
```bash
sudo tee /etc/systemd/system/tika.service >/dev/null <<'EOF'
[Unit]
Description=Apache Tika Server
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/wwwroot/synaplan/services/tika
ExecStart=/usr/bin/java -jar /wwwroot/synaplan/services/tika/tika-server.jar --host 0.0.0.0 --port 9998
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
sudo systemctl daemon-reload
sudo systemctl enable --now tika.service
sudo systemctl status --no-pager tika.service
```

## 6. (Optional) Whisper.cpp binary (for on-box transcription)
```bash
git clone https://github.com/ggerganov/whisper.cpp.git /tmp/whisper && cd /tmp/whisper && git checkout v1.5.4 && make -j"$(nproc)" && sudo cp main /usr/local/bin/whisper && sudo cp quantize /usr/local/bin/whisper-quantize
mkdir -p /wwwroot/synaplan/backend/var/whisper && curl -fL -o /wwwroot/synaplan/backend/var/whisper/ggml-base.bin https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-base.bin && chmod 644 /wwwroot/synaplan/backend/var/whisper/*.bin
```
*(Skip and set `WHISPER_ENABLED=false` if you prefer remote transcription.)*

## 7. Project checkout
```bash
sudo mkdir -p /wwwroot && cd /wwwroot
git clone <repo-url> synaplan && cd /wwwroot/synaplan
cp backend/.env.example backend/.env
cp frontend/.env.example frontend/.env
```

## 8. Ollama service + required models

Install the systemd-managed Ollama daemon:
```bash
curl -fsSL https://ollama.com/install.sh | sh
sudo systemctl enable --now ollama
sudo systemctl status --no-pager ollama
sudo ss -ltnp | grep 11434   # should show ollama on 127.0.0.1:11434
```

Pull the chat + embedding models:
```bash
ollama pull gpt-oss:20b
ollama pull bge-m3
ollama list
curl -fsS http://127.0.0.1:11434/api/tags | grep -E 'gpt-oss|bge-m3'
```

*If the backend lives on another host, run `sudo systemctl edit ollama`, set `Environment="OLLAMA_HOST=0.0.0.0"`, restart the service.*

---

## 9. Backend environment setup

### Complete backend `.env` template

Create/edit `/wwwroot/synaplan/backend/.env` with ALL required variables:

```ini
# =============================================================================
# REQUIRED SETTINGS
# =============================================================================

# -----------------------------------------------------------------------------
# Application
# -----------------------------------------------------------------------------
APP_ENV=dev
APP_DEBUG=true
APP_SECRET=change_this_to_a_random_string_in_production

# URLs (adjust for your setup - see Configuration Quick Reference below)
APP_URL=http://localhost/synaplan/backend
FRONTEND_URL=http://localhost/synaplan/frontend

# -----------------------------------------------------------------------------
# Database (REQUIRED)
# -----------------------------------------------------------------------------
DATABASE_WRITE_URL=mysql://synaplan:password@127.0.0.1:3306/synaplan?serverVersion=11.8&charset=utf8mb4
DATABASE_READ_URL=mysql://synaplan:password@127.0.0.1:3306/synaplan?serverVersion=11.8&charset=utf8mb4

# -----------------------------------------------------------------------------
# AI Services (REQUIRED - at minimum set OLLAMA_BASE_URL)
# -----------------------------------------------------------------------------
OLLAMA_BASE_URL=http://127.0.0.1:11434
AI_DEFAULT_PROVIDER=ollama
AUTO_DOWNLOAD_MODELS=false

# =============================================================================
# OPTIONAL SETTINGS
# =============================================================================

# -----------------------------------------------------------------------------
# Document Processing
# -----------------------------------------------------------------------------
TIKA_BASE_URL=http://127.0.0.1:9998

# -----------------------------------------------------------------------------
# Email (use null://null for dev)
# -----------------------------------------------------------------------------
MAILER_DSN=null://null

# -----------------------------------------------------------------------------
# reCAPTCHA (disabled for dev)
# -----------------------------------------------------------------------------
RECAPTCHA_ENABLED=false

# -----------------------------------------------------------------------------
# External AI APIs (optional)
# -----------------------------------------------------------------------------
# OPENAI_API_KEY=sk-...
# ANTHROPIC_API_KEY=sk-ant-...
# GROQ_API_KEY=gsk_...
# GOOGLE_GEMINI_API_KEY=...

# -----------------------------------------------------------------------------
# Social Login (optional)
# -----------------------------------------------------------------------------
# GOOGLE_CLIENT_ID=
# GOOGLE_CLIENT_SECRET=
# GITHUB_CLIENT_ID=
# GITHUB_CLIENT_SECRET=

# -----------------------------------------------------------------------------
# Whisper Transcription (optional)
# -----------------------------------------------------------------------------
WHISPER_ENABLED=false
WHISPER_BINARY=/usr/local/bin/whisper
WHISPER_MODELS_PATH=/wwwroot/synaplan/backend/var/whisper
WHISPER_DEFAULT_MODEL=base
```

---

## 10. Backend install & database prep

### Step 1: Install dependencies
```bash
cd /wwwroot/synaplan/backend
composer install --no-dev --optimize-autoloader
```

### Step 2: Create database and user

```sql
-- Run in MySQL/MariaDB as root:
CREATE DATABASE synaplan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'synaplan'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON synaplan.* TO 'synaplan'@'localhost';
FLUSH PRIVILEGES;
```

### Step 3: Create database schema

> **Note:** This project uses schema creation, not migrations. The migrations folder is empty.

```bash
cd /wwwroot/synaplan/backend

# Create schema from entities
php bin/console doctrine:schema:create --no-interaction

# Or update if tables partially exist
php bin/console doctrine:schema:update --force
```

### Step 4: Load initial data (fixtures)

Load the demo users, AI models, rate limits, and system configuration:

```bash
cd /wwwroot/synaplan/backend
php bin/console doctrine:fixtures:load --no-interaction
```

This creates the following demo users:

| Email | Password | Level | Status |
|-------|----------|-------|--------|
| `admin@synaplan.com` | `admin123` | ADMIN | Verified |
| `demo@synaplan.com` | `demo123` | PRO | Verified |
| `test@example.com` | `test123` | NEW | Unverified |

It also loads:
- **AI Models** - Ollama, Groq, OpenAI, Google, Anthropic model configurations
- **Rate Limits** - Usage limits for different user tiers
- **System Config** - Default system settings

> **⚠️ Warning:** Running fixtures again will **reset** all data. Use `--append` to add without clearing:
> ```bash
> php bin/console doctrine:fixtures:load --append --no-interaction
> ```

### Step 5: Clear cache and set permissions
```bash
php bin/console cache:clear
sudo chown -R www-data:www-data var
sudo chmod -R 775 var
```

---

## 11. Apache virtual host configuration

### WSL subdirectory setup (RECOMMENDED for localhost)

> **Important:** Do NOT create a separate VirtualHost with `ServerName localhost` - this will block other applications like PHPMyAdmin. Instead, add Synaplan routes to the default config.

**Option A: Modify existing 000-default.conf (recommended)**

```bash
sudo tee /etc/apache2/sites-available/000-default.conf >/dev/null <<'EOF'
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    DocumentRoot /wwwroot

    # ─────────────────────────────────────────────────────────────
    # Synaplan Backend API: /synaplan/backend → Symfony via PHP-FPM
    # ─────────────────────────────────────────────────────────────
    Alias /synaplan/backend /wwwroot/synaplan/backend/public

    <Directory /wwwroot/synaplan/backend/public>
        AllowOverride All
        Require all granted
        FallbackResource /synaplan/backend/index.php
        Options -Indexes +FollowSymLinks
    </Directory>

    # PHP-FPM handler (adjust socket path for your PHP version!)
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"
    </FilesMatch>

    # ─────────────────────────────────────────────────────────────
    # Synaplan Frontend: /synaplan/frontend → Vue SPA
    # ─────────────────────────────────────────────────────────────
    Alias /synaplan/frontend /wwwroot/synaplan/frontend/dist

    <Directory /wwwroot/synaplan/frontend/dist>
        AllowOverride None
        Require all granted
        Options -Indexes
        FallbackResource /synaplan/frontend/index.html
    </Directory>

    # ─────────────────────────────────────────────────────────────
    # Default DocumentRoot (for PHPMyAdmin, other apps)
    # ─────────────────────────────────────────────────────────────
    <Directory /wwwroot>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF

sudo systemctl reload apache2
```

> **PHP Version Note:** Change `php8.4-fpm.sock` to `php8.3-fpm.sock` if using PHP 8.3.

**Option B: Separate config file (may conflict with other sites)**

If you must use a separate file, ensure it doesn't have `ServerName localhost`:

```bash
# Copy the template and adjust
sudo cp /wwwroot/synaplan/_devextras/synaplan-wsl.conf /etc/apache2/sites-available/
# Edit to remove "ServerName localhost" line if present
sudo a2ensite synaplan-wsl.conf
sudo systemctl reload apache2
```

### Production setup (separate domains)
```apache
<VirtualHost *:80>
    ServerName api.example.com
    DocumentRoot /wwwroot/synaplan/backend/public

    <Directory /wwwroot/synaplan/backend/public>
        AllowOverride All
        Require all granted
        FallbackResource /index.php
    </Directory>

    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"
    </FilesMatch>

    ErrorLog ${APACHE_LOG_DIR}/synaplan-error.log
    CustomLog ${APACHE_LOG_DIR}/synaplan-access.log combined
</VirtualHost>
```

---

## 12. Frontend environment & build

### Frontend `.env` settings

Edit `/wwwroot/synaplan/frontend/.env`:

```ini
# API endpoint (must point to BACKEND, not frontend!)
VITE_API_BASE_URL=http://localhost/synaplan/backend

# Base path for assets and routing
VITE_BASE_PATH=/synaplan/frontend/

# Development settings
VITE_API_TIMEOUT=30000
VITE_RECAPTCHA_ENABLED=false
VITE_SHOW_ERROR_STACK=true
```

> ⚠️ **Common Mistake:** `VITE_API_BASE_URL` must point to the **backend** URL, not the frontend!

### Build frontend

```bash
cd /wwwroot/synaplan/frontend
npm install   # or: pnpm install
npm run build # or: pnpm build
```

### Verify build output
```bash
cat /wwwroot/synaplan/frontend/dist/index.html | head -20
# Should show paths like /synaplan/frontend/assets/...
```

---

## 13. Verify setup

### Test backend API
```bash
# Health check
curl -s http://localhost/synaplan/backend/api/health

# Auth providers (should return JSON)
curl -s http://localhost/synaplan/backend/api/v1/auth/providers

# If you see HTML error pages, check Apache logs:
tail -20 /var/log/apache2/error.log
```

### Test frontend
Open in browser: `http://localhost/synaplan/frontend/`

### Test login
```bash
# Using demo user (created by fixtures)
curl -X POST http://localhost/synaplan/backend/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@synaplan.com","password":"admin123"}'

# Should return: {"success":true,"token":"eyJ0...","refresh_token":"..."}
```

---

## 14. Background workers (Messenger)

### Systemd worker service

```bash
sudo tee /etc/systemd/system/synaplan-messenger.service >/dev/null <<'EOF'
[Unit]
Description=Synaplan Symfony Messenger Worker
After=network-online.target redis-server.service mariadb.service
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/wwwroot/synaplan/backend
ExecStart=/usr/bin/php bin/console messenger:consume async_ai_high async_extract async_index -vv --time-limit=3600 --memory-limit=512M
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now synaplan-messenger.service
sudo systemctl status synaplan-messenger.service
```

Tail logs: `journalctl -u synaplan-messenger.service -f`

---

## 15. Troubleshooting

### Common errors and solutions

| Error | Cause | Solution |
|-------|-------|----------|
| `Environment variable not found: "OLLAMA_BASE_URL"` | Missing AI config | Add `OLLAMA_BASE_URL=http://127.0.0.1:11434` to `.env` |
| `FCGI: attempt to connect to Unix domain socket failed` | Wrong PHP-FPM socket path | Check socket: `ls /run/php/` and update Apache config |
| `503 Service Unavailable` | PHP-FPM not running | `sudo systemctl start php8.4-fpm` |
| `405 Method Not Allowed` on POST | Apache redirect (trailing slash) | Ensure URLs match exactly, check for 301/302 redirects |
| PHPMyAdmin returns 403/404 | Synaplan VirtualHost blocking | Use Option A (merge into 000-default.conf) |
| Logo 404 errors | Hardcoded paths | Rebuild frontend after setting `VITE_BASE_PATH` |
| `Access denied for user` (database) | Wrong credentials | Check DATABASE_WRITE_URL in `.env` |
| `Table doesn't exist` | Schema not created | Run `php bin/console doctrine:schema:update --force` |

### Debug commands

```bash
# Check Symfony config
php bin/console about
php bin/console debug:router | head -30

# Check database connection
php bin/console doctrine:schema:validate

# Clear all caches
php bin/console cache:clear
sudo systemctl reload apache2
sudo systemctl restart php8.4-fpm

# Check Apache config syntax
sudo apache2ctl configtest

# View recent errors
tail -50 /var/log/apache2/error.log
tail -50 /wwwroot/synaplan/backend/var/log/dev.log
```

### JWT key regeneration

If JWT keys are corrupted or lost:
```bash
cd /wwwroot/synaplan/backend
rm -f config/jwt/*.pem
mkdir -p config/jwt
openssl genrsa -out config/jwt/private.pem 4096
openssl rsa -pubout -in config/jwt/private.pem -out config/jwt/public.pem
chmod 644 config/jwt/*.pem
sudo chown www-data:www-data config/jwt/*.pem
php bin/console cache:clear
```

---

## Configuration Quick Reference

| Environment | Backend `APP_URL` | Backend `FRONTEND_URL` | Frontend `VITE_API_BASE_URL` | Frontend `VITE_BASE_PATH` |
|-------------|-------------------|------------------------|------------------------------|---------------------------|
| **Docker** | `http://localhost:8000` | `http://localhost:5173` | `http://localhost:8000` | `/` |
| **Production** | `https://api.example.com` | `https://app.example.com` | `https://api.example.com` | `/` |
| **WSL subdirectory** | `http://localhost/synaplan/backend` | `http://localhost/synaplan/frontend` | `http://localhost/synaplan/backend` | `/synaplan/frontend/` |

### PHP-FPM Socket Paths

| PHP Version | Socket Path |
|-------------|-------------|
| PHP 8.3 | `/run/php/php8.3-fpm.sock` |
| PHP 8.4 | `/run/php/php8.4-fpm.sock` |

The codebase requires **no code changes** between environments—only `.env` file adjustments.

---

## External services

| Service | Default URL | Environment Variable |
|---------|-------------|---------------------|
| Ollama | `http://127.0.0.1:11434` | `OLLAMA_BASE_URL` |
| Apache Tika | `http://127.0.0.1:9998` | `TIKA_BASE_URL` |
| MariaDB/MySQL | `127.0.0.1:3306` | `DATABASE_WRITE_URL`, `DATABASE_READ_URL` |

---

That's all that is required to reproduce the Docker Compose setup on a plain Ubuntu host or WSL.
