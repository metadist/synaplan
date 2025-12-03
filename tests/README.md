# Playwright Smoke Test Setup

Minimal-robustes Playwright-Smoke-Setup für laufende Web-App.

## Setup

```bash
# Node.js 18+ erforderlich
node --version

# Dependencies installieren
npm install

# Playwright Browser installieren
npx playwright install chromium
```

## Environment Variables

Kopiere `.env.example` zu `.env` und passe an:

```bash
cp .env.example .env
```

Wichtige ENV-Variablen:
- `BASE_URL`: BaseURL für Tests (Default: `http://localhost:5137`)
- `AUTH_USER`: Username für Login-Tests
- `AUTH_PASS`: Password für Login-Tests
- `API_TOKEN`: Optional: Token für API-Tests

## Lokaler Run

```bash
# Alle Tests
npm test

# Nur Smoke-Tests
npm run test:smoke

# Mit custom BASE_URL
BASE_URL=http://localhost:5137 npm run test:smoke
```

## Test-Entwicklung

```bash
# Codegen: Generiert Test-Code während du interagierst
npm run codegen

# UI Mode: Visueller Test-Runner mit Live-Preview & Debug
npm run test:ui

# Einzelnen Test ausführen
npx playwright test tests/e2e/smoke/01_login.spec.ts

# Headed mode (Browser sichtbar)
npx playwright test --headed
```

## Reports

```bash
# HTML-Report öffnen
npm run report

# Trace anzeigen
npm run trace
```

## Selektoren-Guideline

Bevorzugt `[data-testid]` Attribute verwenden für robuste Selektoren.

Passe Selektoren in `tests/utils/selectors.ts` an deine App an.

## CI/CD

GitHub Actions Workflow läuft:
- On push to main
- 3× täglich (6:00, 12:00, 18:00 UTC)

Setze Secrets in GitHub:
- `BASE_URL`
- `AUTH_USER`
- `AUTH_PASS`
- `API_TOKEN`

## Troubleshooting

**Timeouts:**
- Erhöhe `timeout` in `playwright.config.ts`
- Prüfe ob App läuft auf `BASE_URL`

**Flaky Tests:**
- Nutze `waitForIdle()` statt `sleep()`
- Prüfe Selektoren in `selectors.ts`

**Langsame CI:**
- Reduziere `workers` in `playwright.config.ts` (z.B. auf 2)

