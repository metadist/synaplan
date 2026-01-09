# Playwright Smoke Test Setup

Minimal robust Playwright smoke test setup for running web applications.

## Setup

```bash
# Node.js 18+ required
node --version

# Install dependencies
npm install

# Install Playwright browsers
npx playwright install chromium
```

## Environment Variables

Copy `.env.example` to `.env` and adjust:

```bash
cp .env.example .env
```

Important environment variables:
- `BASE_URL`: Base URL for tests (Default: `http://localhost:5173`)
- `AUTH_USER`: Username for login tests
- `AUTH_PASS`: Password for login tests
- `API_TOKEN`: Optional: Token for API tests

## Local Run

### With Docker (recommended)

```bash
# Start test environment with Docker
cd ..
docker compose -f docker-compose.test.yml up -d

# Wait until services are ready
docker compose -f docker-compose.test.yml ps

# Run tests
cd tests
AUTH_USER=admin@synaplan.com AUTH_PASS=admin123 npx playwright test

# Stop test environment
cd ..
docker compose -f docker-compose.test.yml down -v
```

### Without Docker

```bash
# All tests
npm test

# Smoke tests only
npm run test:smoke

# With custom BASE_URL
BASE_URL=http://localhost:5173 npm run test:smoke
```

## Test Development

```bash
# Codegen: Generate test code while you interact
npm run codegen

# UI Mode: Visual test runner with live preview & debug
npm run test:ui

# Run single test
npx playwright test tests/e2e/smoke/01_login.spec.ts

# Headed mode (browser visible)
npx playwright test --headed
```

## Reports

```bash
# Open HTML report
npm run report

# Show trace
npm run trace
```

## Selector Guidelines

Prefer using `[data-testid]` attributes for robust selectors.

Adapt selectors in `tests/utils/selectors.ts` to your app.

## CI/CD

GitHub Actions workflow runs:
- On push to main
- 3× daily (6:00, 12:00, 18:00 UTC)

Set secrets in GitHub:
- `BASE_URL`
- `AUTH_USER`
- `AUTH_PASS`
- `API_TOKEN`

## Troubleshooting

**Timeouts:**
- Increase `timeout` in `playwright.config.ts`
- Check if app is running on `BASE_URL`

**Flaky Tests:**
- Use `waitForIdle()` instead of `sleep()`
- Check selectors in `selectors.ts`

**Slow CI:**
- Reduce `workers` in `playwright.config.ts` (e.g., to 2)

