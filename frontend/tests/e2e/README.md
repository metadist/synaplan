# Playwright E2E Tests

## Quick Start

1. **Start the application** (see [root README](../../README.md)):
   ```bash
   docker compose up -d
   ```

2. **Install Node.js 18+** (if not installed):

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt-get install -y nodejs
```

3. **Setup Playwright**:

   ```bash
   cd frontend
   npm install
   npx playwright install --with-deps
   ```

   **Note:** `npm install` only installs the npm package `@playwright/test`. The browser binaries (Chromium, Firefox, WebKit) must be downloaded separately with `npx playwright install`. The `--with-deps` flag also installs the required system dependencies.

   **Alternative (using Makefile):**
   ```bash
   make -C frontend deps-host
   cd frontend
   npx playwright install --with-deps
   ```

4. **Run tests**:
   ```bash
   cd frontend
   npm run test:e2e  # Chromium by default
   ```

   Pass `--grep "keyword"` to either the npm script or the Playwright CLI to limit the scope.

**Note:** Chromium only locally, Firefox added in CI.

The `grep` line in `frontend/tests/e2e/playwright.config.ts` is commented out (`// grep: /@smoke/,`), so `npm run test:e2e` runs every file in `tests/e2e` unless you pass `--grep` or re-enable that filter.

## Configuration

Optional: Create `frontend/tests/e2e/.env.local` for local test credentials:

```bash
BASE_URL=http://localhost:5173
AUTH_USER=admin@synaplan.com
AUTH_PASS=admin123
```

Some tests require local Ollama models.

## Commands

```bash
# Run all E2E tests (Chromium only)
npm run test:e2e

# Run both browsers from Playwright config
npx playwright test --config=tests/e2e/playwright.config.ts

# Run a specific test file
npx playwright test tests/login.spec.ts --config=tests/e2e/playwright.config.ts --project=chromium

# Run tests that match a pattern (grep)
npx playwright test --config=tests/e2e/playwright.config.ts --project=chromium --grep "registration"

# Browse the suite interactively
npm run test:e2e:ui

# View HTML report and trace
npm run test:e2e:report
```

Slow-motion runs are configured by setting `launchOptions.slowMo` in `tests/e2e/playwright.config.ts`. It waits for the given time in ms after each playwright action.

```bash
npx playwright test --config=tests/e2e/playwright.config.ts --project=chromium
```

```bash
npx playwright test --config=tests/e2e/playwright.config.ts --ui
```


## Troubleshooting

- **"npm: command not found"** → install Node.js (see step 2 above)
- **EACCES errors** → delete `node_modules`/`package-lock.json` and reinstall (`npm install`)
- **"Connection refused"** → start services (`docker compose up -d`)
