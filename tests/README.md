# Synaplan E2E Tests

Small Playwright test suite for the Synaplan app. Current focus: simple **smoke tests** that run locally against a running instance.

Tests and coverage will be expanded over time.

---

## Setup

From the `tests/` project folder:

```bash
npm install
npx playwright install
```

The app itself must be running in parallel (e.g., `http://localhost:5137`).

Optional local configuration via `.env.local`:

```env
BASE_URL=http://localhost:5137
AUTH_USER=admin@synaplan.com
AUTH_PASS=admin123
API_TOKEN=your-api-token-here
```

`BASE_URL` overrides the default value from `playwright.config.ts`.

---

## Run tests

All tests:

```bash
npm run test
```

Run only selected tests by tag/ID in the test title, e.g., `@smoke` or `id=004`:

```bash
npx playwright test -g "@smoke"
# or
npx playwright test -g "id=004"
```

More handy scripts:

```bash
npm run test:ui   # Playwright UI
npm run report    # open the latest HTML report
npm run trace     # view traces
```

---

## Structure (quick overview)

```txt
tests/
├─ e2e/              # specs (e.g., chat.spec.ts, login.spec.ts)
├─ reports/          # HTML & JUnit reports
├─ test-results/     # traces, screenshots, videos
├─ playwright.config.ts
├─ package.json
└─ .env.local        # local settings (not in repo)
```

The layout is intentionally simple: specs up top, helpers/selectors in their own files, no unnecessary magic.
