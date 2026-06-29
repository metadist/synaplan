# Step 1: Local Development Setup

## Current Environment (Verified)

| Component | Value |
|-----------|-------|
| Nextcloud | v34.0.0 dev (git channel) |
| Install Type | Direct on host (not Docker) |
| Location | `/wwwroot/nextcloud/server/` |
| URL | `http://localhost/nextcloud/server/` |
| Web Server | Apache on port 80 |
| PHP | 8.2+ |
| Database | MySQL on `127.0.0.1:3306`, db: `nextcloud`, user: `nextcloud` |
| Data Dir | `/wwwroot/nextcloud/server/data` |
| Apps Dir | `/wwwroot/nextcloud/server/apps/` (31 bundled apps) |
| Admin Login | admin / admin |
| Synaplan | Docker Compose at `http://localhost:8000` |

## 1. Create Custom Apps Directory

Nextcloud's default `apps/` directory contains bundled apps and should not be used for custom development. We need a separate `custom_apps/` directory.

```bash
# Create the custom_apps directory
mkdir -p /wwwroot/nextcloud/server/custom_apps
chown www-data:www-data /wwwroot/nextcloud/server/custom_apps
```

### Register in Nextcloud Config

Edit `/wwwroot/nextcloud/server/config/config.php` and add:

```php
'apps_paths' => [
    [
        'path' => '/wwwroot/nextcloud/server/apps',
        'url' => '/apps',
        'writable' => false,
    ],
    [
        'path' => '/wwwroot/nextcloud/server/custom_apps',
        'url' => '/custom_apps',
        'writable' => true,
    ],
],
```

## 2. Create the App Source Repository

```bash
# Create the synaplan-nextcloud repo
mkdir -p /wwwroot/synaplan-nextcloud
cd /wwwroot/synaplan-nextcloud
git init
```

## 3. Symlink for Development

During development, symlink the app into Nextcloud's custom_apps:

```bash
ln -s /wwwroot/synaplan-nextcloud /wwwroot/nextcloud/server/custom_apps/synaplan_integration
```

This way, changes in the source repo are immediately visible to Nextcloud without copying.

## 4. Enable the App

Once the app skeleton is in place:

```bash
# Via occ command
cd /wwwroot/nextcloud/server
sudo -u www-data php occ app:enable synaplan_integration

# Or via the Nextcloud admin UI:
# Settings → Apps → "Your apps" → Enable "Synaplan Integration"
```

## 5. Connectivity: Nextcloud → Synaplan

Since both services run on the same host:

| From | To | URL |
|------|----|-----|
| Nextcloud PHP → Synaplan API | HTTP | `http://localhost:8000` |
| Browser → Nextcloud | HTTP | `http://localhost/nextcloud/server/` |
| Browser → Synaplan | HTTP | `http://localhost:8000` or `http://localhost:5173` (dev) |

**Important**: Nextcloud's PHP backend makes server-side HTTP requests to Synaplan. The browser never calls Synaplan directly — all requests are proxied through Nextcloud's backend. This avoids CORS issues entirely.

## 6. Trusted Domains

Current config already includes `localhost` and `127.0.0.1` as trusted domains. No changes needed.

## 7. Development Tools

| Tool | URL | Purpose |
|------|-----|---------|
| Nextcloud | `http://localhost/nextcloud/server/` | Test the app |
| Synaplan Swagger UI | `http://localhost:8000/api/doc` | Test API endpoints |
| phpMyAdmin | `http://localhost:8082` | Inspect Synaplan DB |
| Nextcloud occ | `php /wwwroot/nextcloud/server/occ` | CLI management |

## 8. Create a Synaplan API Key

For the Nextcloud app to communicate with Synaplan, we need an API key:

```bash
# Option 1: Via Synaplan UI
# Log into Synaplan → Settings → API Keys → Create

# Option 2: Direct SQL (for dev/testing)
docker compose exec backend php bin/console app:apikey:create \
  --user=1 --name="Nextcloud Integration" --scopes='["nextcloud:*"]'
```

The API key will look like `sk_...` and should be configured in the Nextcloud app's admin settings.

## 9. Frontend Build Setup

The app's Vue.js frontend needs Node.js for building:

```bash
cd /wwwroot/synaplan-nextcloud

# Initialize package.json with Nextcloud build tooling
npm init -y
npm install --save-dev \
  @nextcloud/webpack-vue-config \
  @nextcloud/vue \
  @nextcloud/axios \
  @nextcloud/router \
  @nextcloud/initial-state \
  @nextcloud/l10n \
  vue \
  vue-loader
```

**Build command:**
```bash
npm run build    # Production build → js/
npm run dev      # Watch mode for development
```

## 10. Quick Verification Checklist

After setup, verify:

- [ ] `http://localhost/nextcloud/server/` loads and you can log in
- [ ] `http://localhost:8000/api/doc` shows Swagger UI
- [ ] `http://localhost:8000/api/health` returns healthy status
- [ ] Custom apps directory exists and is registered in config
- [ ] Symlink from `custom_apps/synaplan_integration` → source repo
- [ ] Synaplan API key created and accessible
- [ ] `occ app:list` shows `synaplan_integration` (after skeleton is created)
