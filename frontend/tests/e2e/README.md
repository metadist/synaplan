# Playwright E2E Tests

## Quick Start

1. **Start the application** (see [root README](../../README.md)):
   ```bash
   docker compose up -d
   ```

Node.js 18+ (if not2. **Install Node.js 18+** (if not installed):

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

4. **Run tests**:
   You may change the grep command in playwright.config.ts
   ```bash
   cd frontend
   npm run test:e2e  # Only Chromium locally (faster)
   ```

**Note:** Locally runs Chromium only. CI tests both Chromium and Firefox.

## Configuration

Optional: Create `frontend/tests/e2e/.env.local`:

```bash
BASE_URL=http://localhost:5173
AUTH_USER=admin@synaplan.com
AUTH_PASS=admin123
```

For some Tests you need to run local ollama models

## Commands

```bash
# Run all tests (Chromium only)
npm run test:e2e

# Run both browsers
npx playwright test --config=tests/e2e/playwright.config.ts

# Run specific test
npx playwright test tests/login.spec.ts --config=tests/e2e/playwright.config.ts --project=chromium

# View HTML report
npm run test:e2e:report
```

## Troubleshooting

**"npm: command not found"** → Install Node.js (see step 2 above)

**EACCES errors** → Fix permissions:

```bash
cd frontend
rm -rf node_modules package-lock.json
npm install
```

**"Connection refused"** → Ensure app is running: `docker compose up -d`
