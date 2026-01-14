# Playwright E2E Guide

This folder contains the Playwright suite (`tests/`) and config (`playwright.config.ts`). Run everything from the `frontend/` directory.

## Prerequisites
- Node.js 18+
- Dependencies installed via npm (no sudo)

## One-time setup
```bash
cd frontend
npm ci
npx playwright install --with-deps
```

## Environment
Defaults (see `playwright.config.ts`):
- `BASE_URL` defaults to `http://localhost:5173`
- `headless` runs in CI, headed locally
- Login tests are excluded by default via `grepInvert`

Provide env values via shell or `frontend/tests/e2e/.env` / `.env.local`:
- `BASE_URL` – app URL
- `AUTH_USER`, `AUTH_PASS` – credentials for auth flows
- Optional: feature flags or API tokens your backend expects

## Running tests
```bash
# From frontend/
npm run test:e2e  # runs suite except tests containing "login"

# Run a specific file
npx playwright test tests/login.spec.ts --config=tests/e2e/playwright.config.ts

# Include login tests explicitly
npx playwright test --grep login --config=tests/e2e/playwright.config.ts

# Headed debugging
npx playwright test --headed --config=tests/e2e/playwright.config.ts
```

## Reports and traces
Artifacts live in `frontend/tests/e2e/test-results` and `frontend/tests/e2e/reports`.
```bash
npm run report  # open HTML report
npm run trace   # open last trace
```

## Troubleshooting
- `playwright: not found`: reinstall deps (`npm ci`) and run `npx playwright install --with-deps`.
- `EACCES` in node_modules: ensure `node_modules` is owned by your user (no sudo npm).
- Timeouts: verify `BASE_URL` is reachable; increase `timeout` in `playwright.config.ts` if needed.
