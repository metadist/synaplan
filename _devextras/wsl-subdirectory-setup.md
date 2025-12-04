# WSL Subdirectory Setup Guide

Run Synaplan under `http://localhost/synaplan/...` on WSL without breaking the Docker setup.

## URL Structure

| Component | URL |
|-----------|-----|
| Frontend  | `http://localhost/synaplan/frontend/` |
| Backend API | `http://localhost/synaplan/backend/` |
| Uploaded files | `http://localhost/synaplan/backend/up/...` |

---

## 1. Apache Virtual Host Configuration

Create `/etc/apache2/sites-available/synaplan-wsl.conf`:

```apache
<VirtualHost *:80>
    ServerName localhost

    # ─────────────────────────────────────────────────────────────
    # Backend API: /synaplan/backend → Symfony via PHP-FPM
    # ─────────────────────────────────────────────────────────────
    Alias /synaplan/backend /wwwroot/synaplan/backend/public

    <Directory /wwwroot/synaplan/backend/public>
        AllowOverride All
        Require all granted
        FallbackResource /synaplan/backend/index.php
        
        # Enable .htaccess processing
        Options -Indexes +FollowSymLinks
    </Directory>

    # PHP-FPM handler for backend
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
    </FilesMatch>

    # ─────────────────────────────────────────────────────────────
    # Frontend (Production Build): /synaplan/frontend → static files
    # ─────────────────────────────────────────────────────────────
    Alias /synaplan/frontend /wwwroot/synaplan/frontend/dist

    <Directory /wwwroot/synaplan/frontend/dist>
        AllowOverride None
        Require all granted
        Options -Indexes
        
        # SPA fallback: serve index.html for client-side routes
        FallbackResource /synaplan/frontend/index.html
    </Directory>

    # ─────────────────────────────────────────────────────────────
    # Logging
    # ─────────────────────────────────────────────────────────────
    ErrorLog ${APACHE_LOG_DIR}/synaplan-error.log
    CustomLog ${APACHE_LOG_DIR}/synaplan-access.log combined
</VirtualHost>
```

Enable the site:

```bash
sudo a2ensite synaplan-wsl.conf
sudo a2enmod rewrite proxy_fcgi setenvif alias
sudo systemctl reload apache2
```

---

## 2. Backend `.env` Configuration

Copy `.env.example` to `.env` and set these values for WSL subdirectory:

```bash
cd /wwwroot/synaplan/backend
cp .env.example .env
```

**Key settings to change:**

```ini
APP_ENV=dev
APP_DEBUG=true

# CRITICAL: Full subdirectory paths
APP_URL=http://localhost/synaplan/backend
FRONTEND_URL=http://localhost/synaplan/frontend

# Database (adjust credentials as needed)
DATABASE_WRITE_URL=mysql://synaplan:password@127.0.0.1:3306/synaplan?serverVersion=11.8&charset=utf8mb4
DATABASE_READ_URL=mysql://synaplan:password@127.0.0.1:3306/synaplan?serverVersion=11.8&charset=utf8mb4

# Messenger (use Redis if available, or Doctrine)
MESSENGER_TRANSPORT_DSN=redis://127.0.0.1:6379

# AI Services (adjust hosts as needed)
OLLAMA_BASE_URL=http://127.0.0.1:11434
TIKA_BASE_URL=http://127.0.0.1:9998
AI_DEFAULT_PROVIDER=ollama
AUTO_DOWNLOAD_MODELS=false

# Disable mailer for local dev
MAILER_DSN=null://null
```

---

## 3. Frontend `.env` Configuration

Copy `.env.example` to `.env`:

```bash
cd /wwwroot/synaplan/frontend
cp .env.example .env
```

**Key settings for WSL subdirectory:**

```ini
# API endpoint (backend URL)
VITE_API_BASE_URL=http://localhost/synaplan/backend

# CRITICAL: Base path for Vue router and asset loading
# Must include trailing slash!
VITE_BASE_PATH=/synaplan/frontend/

# Optional dev settings
VITE_SHOW_ERROR_STACK=true
VITE_AUTO_LOGIN_DEV=false
```

---

## 4. Build & Install Commands

### Backend Setup

```bash
cd /wwwroot/synaplan/backend

# Install dependencies
composer install

# Generate JWT keys
php bin/console lexik:jwt:generate-keypair --skip-if-exists

# Run migrations
php bin/console doctrine:migrations:migrate --no-interaction

# Optional: Load demo data
php bin/console doctrine:fixtures:load --no-interaction

# Clear cache
php bin/console cache:clear

# Setup messenger transports
php bin/console messenger:setup-transports --no-interaction

# Fix permissions
sudo chown -R www-data:www-data var public/up
sudo chmod -R 775 var public/up
```

### Frontend Setup

```bash
cd /wwwroot/synaplan/frontend

# Install dependencies
npm install

# Build for production (REQUIRED for Apache to serve static files)
npm run build
```

The build process reads `VITE_BASE_PATH` and bakes it into the compiled assets. **You must rebuild after changing `VITE_BASE_PATH`.**

---

## 5. Development Mode (Vite Dev Server)

For hot-reload during development, use Vite's dev server instead of the Apache static files:

### Option A: Proxy through Apache (recommended)

Add this to your Apache config before the static frontend alias:

```apache
# Development: Proxy to Vite dev server
# Comment out the static Alias/Directory blocks above when using this
ProxyPass /synaplan/frontend http://127.0.0.1:5173/synaplan/frontend
ProxyPassReverse /synaplan/frontend http://127.0.0.1:5173/synaplan/frontend
```

Then run Vite:

```bash
cd /wwwroot/synaplan/frontend
npm run dev -- --host 0.0.0.0 --port 5173 --base /synaplan/frontend/
```

### Option B: Direct Vite access

Access Vite directly at `http://localhost:5173/synaplan/frontend/`:

```bash
npm run dev -- --host 0.0.0.0 --port 5173 --base /synaplan/frontend/
```

Note: CORS is already permissive (`allow_origin: ['*']`) so cross-origin API calls work.

---

## 6. Messenger Worker (Background Jobs)

Run the messenger worker in a terminal or via systemd:

```bash
cd /wwwroot/synaplan/backend
php bin/console messenger:consume async_ai_high async_extract async_index -vv
```

For systemd setup, see the main readme's "Systemd worker" section.

---

## 7. Verification Checklist

1. **Backend health check:**
   ```bash
   curl -s http://localhost/synaplan/backend/api/health | jq .
   ```

2. **Frontend loads:**
   Open `http://localhost/synaplan/frontend/` in browser

3. **API calls work:**
   Open browser DevTools → Network tab → verify API calls go to `/synaplan/backend/api/...`

4. **Login works:**
   Use demo credentials: `admin@synaplan.com` / `admin123`

---

## 8. Troubleshooting

### "404 Not Found" on frontend routes

The SPA fallback isn't working. Check:
- `FallbackResource /synaplan/frontend/index.html` is set
- Frontend was built with correct `VITE_BASE_PATH`
- Apache `mod_rewrite` is enabled

### API calls return CORS errors

Shouldn't happen with current config, but verify:
- `backend/config/packages/nelmio_cors.yaml` has `allow_origin: ['*']`
- Clear Symfony cache: `php bin/console cache:clear`

### "Class not found" errors

```bash
cd /wwwroot/synaplan/backend
composer dump-autoload
php bin/console cache:clear
```

### Assets load with wrong path

Rebuild frontend with correct base path:
```bash
cd /wwwroot/synaplan/frontend
# Verify .env has VITE_BASE_PATH=/synaplan/frontend/
npm run build
```

---

## 9. Switching Between Docker and WSL

The configuration is isolated per environment:

| Environment | Backend .env | Frontend .env |
|-------------|--------------|---------------|
| Docker | `APP_URL=http://localhost:8000` | `VITE_API_BASE_URL=http://localhost:8000`, `VITE_BASE_PATH=/` |
| WSL subdirectory | `APP_URL=http://localhost/synaplan/backend` | `VITE_API_BASE_URL=http://localhost/synaplan/backend`, `VITE_BASE_PATH=/synaplan/frontend/` |

The codebase doesn't need changes—only the `.env` files differ. Keep separate `.env` files or switch values when changing environments.

---

## Quick Reference

```bash
# Backend URL
http://localhost/synaplan/backend/api/health

# Frontend URL  
http://localhost/synaplan/frontend/

# Start Vite dev server
cd /wwwroot/synaplan/frontend && npm run dev -- --host 0.0.0.0 --base /synaplan/frontend/

# Build frontend for production
cd /wwwroot/synaplan/frontend && npm run build

# Run messenger worker
cd /wwwroot/synaplan/backend && php bin/console messenger:consume async_ai_high async_extract async_index -vv
```

