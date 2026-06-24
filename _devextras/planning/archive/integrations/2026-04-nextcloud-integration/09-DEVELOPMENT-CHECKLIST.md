# Step 9: Development Checklist

Step-by-step workflow for building the Synaplan Nextcloud Integration. Each step has a clear deliverable, verification method, and quality gates.

## Quality Standards (Every Step)

Before any commit:

1. **PHP**: `composer run lint` (php-cs-fixer dry-run, PSR-12)
2. **PHP**: `composer run test` (PHPUnit)
3. **JS/TS**: `npm run lint` (ESLint)
4. **JS/TS**: `npm run build` (Vite production build succeeds)
5. **Manual**: Check Nextcloud log for errors

Tests are written **alongside the code**, not after.

---

## Pre-Development Setup

### 1. Prepare Nextcloud for custom app development

```bash
mkdir -p /wwwroot/nextcloud/server/custom_apps
```

Register in `/wwwroot/nextcloud/server/config/config.php`:

```php
'apps_paths' => [
    ['path' => '/wwwroot/nextcloud/server/apps', 'url' => '/apps', 'writable' => false],
    ['path' => '/wwwroot/nextcloud/server/custom_apps', 'url' => '/custom_apps', 'writable' => true],
],
```

Verify: Nextcloud loads without errors.

### 2. Create the repository

```bash
mkdir -p /wwwroot/synaplan-nextcloud
cd /wwwroot/synaplan-nextcloud
git init
```

Symlink:

```bash
ln -s /wwwroot/synaplan-nextcloud \
  /wwwroot/nextcloud/server/custom_apps/synaplan_integration
```

### 3. Repository scaffolding (open source best practices)

Create these files first:

- `LICENSE` (AGPL-3.0)
- `.gitignore`
- `.editorconfig`
- `README.md` (install + usage)
- `CONTRIBUTING.md` (brief)
- `CHANGELOG.md`
- `composer.json` (with lint/test scripts)
- `package.json` (with lint/build scripts)
- `Makefile` (convenience commands)

### 4. Create Synaplan API key

Via Synaplan UI or CLI. Test with:

```bash
curl -H "X-API-Key: sk_YOUR_KEY" http://localhost:8000/api/health
```

---

## Phase 1: App Skeleton & Settings

### Step 1.1: App manifest + Application class

Files:
- `appinfo/info.xml`
- `lib/AppInfo/Application.php`
- `composer.json` (autoload PSR-4)

Quality:
- [ ] `composer run lint` passes
- [ ] `php occ app:enable synaplan_integration` succeeds

### Step 1.2: SynaplanClient service + unit tests

Files:
- `lib/Service/SynaplanClient.php`
- `tests/Unit/Service/SynaplanClientTest.php`

Quality:
- [ ] `composer run lint` passes
- [ ] `composer run test` passes (mocked HTTP)

### Step 1.3: Settings backend

Files:
- `lib/Settings/SynaplanAdmin.php`
- `lib/Controller/SettingsController.php`
- `appinfo/routes.php`
- `templates/settings/admin.php`
- `tests/Unit/Controller/SettingsControllerTest.php`

Quality:
- [ ] `composer run lint` passes
- [ ] `composer run test` passes
- [ ] Admin → Settings shows "Synaplan" section

### Step 1.4: Settings frontend

Files:
- `vite.config.ts`
- `src/settings.ts`
- `src/components/AdminSettings.vue`

Quality:
- [ ] `npm run lint` passes
- [ ] `npm run build` succeeds
- [ ] Settings page renders, saves, test connection works

---

## Quick Commands Reference

```bash
# PHP
composer run lint          # Check style
composer run lint:fix      # Auto-fix style
composer run test          # PHPUnit

# Frontend
npm run lint              # ESLint
npm run lint:fix          # ESLint auto-fix
npm run build             # Vite production
npm run dev               # Vite watch

# All-in-one
make lint                 # PHP + JS lint
make test                 # PHP + JS tests
make build                # Frontend build

# Nextcloud
cd /wwwroot/nextcloud/server
php occ app:enable synaplan_integration
php occ app:disable synaplan_integration
php occ app:list | grep synaplan
tail -f data/nextcloud.log | python3 -m json.tool
```

---

## Troubleshooting

| Issue | Cause | Fix |
|-------|-------|-----|
| App not visible | `info.xml` error | Check NC log, `php occ app:list` |
| "Class not found" | Autoloader stale | `composer dump-autoload` |
| Settings page blank | JS not built | `npm run build`, check `js/` exists |
| "Test Connection" fails | Wrong URL/key | Verify `http://localhost:8000/api/health` |
| CSRF errors | Not using `@nextcloud/axios` | Use it — auto-includes CSRF token |
| SSE not streaming | PHP timeout | Increase `max_execution_time` |
