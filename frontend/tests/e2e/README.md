# Playwright E2E Tests

## Quick Start (dev stack)

```bash
docker compose up -d   # Backend :8000, Frontend :5173, MailHog :8025/:1025
make build             # Build frontend + widget (needed for widget tests)
cd frontend
npm install
npx playwright install --with-deps
npm run test:e2e                        # E2E tests
npm run test:e2e -- -g "id=013"         # Single test
npm run test:e2e:ui                     # Playwright UI
```

## Switching between dev stack and test stack

The test stack gives you a **CI-like environment**: TestProvider, port 8001, tmpfs DB.

Both stacks share **MailHog ports** (8025/1025) — always stop one before starting the other.

### Dev → Test stack

```bash
docker compose down                                     # Stop dev stack
sudo rm -rf frontend/dist frontend/dist-widget          # Remove root-owned build artifacts (if permission error)
make test-stack-build                                   # Build + start test stack, waits until healthy (~30-60s)
```

Then run tests from the **frontend** directory with `BASE_URL=http://localhost:8001` (see [Test commands](#test-commands) below).

### Test → Dev stack

```bash
docker compose -f docker-compose.test.yml down          # Stop test stack (DB is tmpfs, always fresh)
docker compose up -d                                    # Start dev stack
```

### When do I need `make test-stack-build`?

| What changed               | Rebuild needed?                                              |
| -------------------------- | ------------------------------------------------------------ |
| Backend PHP code           | **No** — volume-mounted, live changes                        |
| Frontend / Widget code     | **Yes** — image COPYs `dist/` and `dist-widget/`             |
| Docker / Compose config    | **Yes** — image needs rebuild                                |
| Database schema / fixtures | **No** — just `down` + `up` (test DB is tmpfs, always fresh) |

Permission error on `frontend/dist/` (container creates it as root): `sudo rm -rf frontend/dist frontend/dist-widget` then re-run `make test-stack-build`.

## Test stack details

|               | Dev stack                       | Test stack                                     |
| ------------- | ------------------------------- | ---------------------------------------------- |
| **Start**     | `docker compose up -d`          | `make test-stack-build`                        |
| **Backend**   | http://localhost:8000           | http://localhost:8001                          |
| **Frontend**  | http://localhost:5173 (Vite)    | Served by backend (:8001)                      |
| **APP_ENV**   | `dev`                           | `test`                                         |
| **AI models** | Real providers (needs API keys) | **TestProvider** (model 900, all capabilities) |
| **DB**        | Persistent volume               | **tmpfs** (fresh on every `up`)                |
| **MailHog**   | :8025 / :1025                   | :8025 / :1025 (shared ports!)                  |
| **Login**     | admin@synaplan.com / admin123   | admin@synaplan.com / admin123                  |

Widget E2E tests use the page at `/widget-test.html`. Tests use `page.route()` to serve the fixture from disk.

### Selecting TestProvider in the dev stack

In the **dev stack** (localhost:5173 + 8000) the TestProvider is shown in **Settings → AI Models** when `APP_ENV=dev` (default in docker-compose). The test model (id 900) must exist in the database; load only the model fixtures once if you don’t see it (avoids duplicate-key errors from other fixtures when using `--append`):

```bash
docker compose exec backend php bin/console doctrine:fixtures:load --append --group=ModelFixtures
```

Then:

1. Open the app → **Settings** (sidebar) → **AI Models**.
2. For the chat model, select **"test-model" (Test Provider)** and save.
3. Run E2E tests with `npm run test:e2e` (no API keys needed; deterministic responses).

You can e.g. run smoke tests (id=003) locally against the test provider.

### TestProvider: CI-like environment (test stack)

- **Same as CI:** Start the test stack (`make test-stack-build`), then run tests with `BASE_URL=http://localhost:8001 npm run test:e2e`. Matches the CI environment (port 8001, TestProvider, tmpfs DB).
- **Switching stacks:** See [Switching between dev stack and test stack](#switching-between-dev-stack-and-test-stack) above.

## Multi-worker (parallel tests)

Tests run with multiple workers. Each worker has a dedicated user (worker 0: admin, workers 1–3: `e2e-worker-1@synaplan.com` …); after each test we clean only that user’s data so parallel runs don’t share state. UserFixtures include these E2E users. **Keep `workers` ≤ number of defined users (currently 4), or add more users in `credentials.ts` and UserFixtures.**

## Test commands

From the **frontend** directory:

| What                               | Command                                                                    |
| ---------------------------------- | -------------------------------------------------------------------------- |
| **Dev stack**: E2E tests           | `npm run test:e2e`                                                         |
| **Dev stack**: single test         | `npm run test:e2e -- -g "id=013"`                                          |
| **Test stack**: E2E tests          | `BASE_URL=http://localhost:8001 npm run test:e2e`                          |
| **Test stack**: CI-like (no @noci) | `BASE_URL=http://localhost:8001 npm run test:e2e -- --grep-invert "@noci"` |
| **Test stack**: single test        | `BASE_URL=http://localhost:8001 npm run test:e2e -- -g "id=020"`           |
| **WhatsApp tests**                 | `BASE_URL=http://localhost:8001 npm run test:e2e:whatsapp`                 |
| **Playwright UI**                  | `npm run test:e2e:ui`                                                      |

Everything after `--` is passed through to Playwright.

### WhatsApp tests (`@whatsapp`)

WhatsApp API smoke tests use the `@whatsapp` tag and are **excluded from the default `test:e2e` script** (like `@oidc` and `@plugin`). Run them explicitly:

```bash
BASE_URL=http://localhost:8001 npm run test:e2e:whatsapp
```

They require the WhatsApp stub server on `:3999` and the backend configured with `WHATSAPP_ENABLED=true` and `WHATSAPP_GRAPH_API_BASE_URL=http://whatsapp-stub:3999`. The stub starts automatically in `docker-compose.test.yml`.

### Email tests

Email smoke tests (`email.spec.ts`) run in the default `test:e2e` suite. They only need MailHog, which is included in both dev and test stacks.

## Reload fixtures (test stack)

Test DB is tmpfs — `down` + `up` gives a fresh DB with fixtures.  
If the stack is still running:

```bash
docker compose -f docker-compose.test.yml exec app_test rm -f /var/www/backend/var/.fixtures_loaded
docker compose -f docker-compose.test.yml restart app_test
```

## Overriding URLs

Pass environment variables directly, e.g. `BASE_URL=http://localhost:8001 npm run test:e2e`.

---

## OIDC prerequisites

The backend container reaches Keycloak via `host.docker.internal`. On Linux, add this to `/etc/hosts` (macOS/Windows have it by default):

```bash
echo "127.0.0.1 host.docker.internal" | sudo tee -a /etc/hosts
```

### OIDC button login (Keycloak)

```bash
# Start test stack with Keycloak
docker compose -f docker-compose.test.yml --profile oidc up -d
docker compose -f docker-compose.test.yml --profile oidc up --wait

# Run OIDC button tests
cd frontend
BASE_URL=http://localhost:8001 npm run test:e2e:oidc-button

# Or run the full suite with OIDC button login (skips @password tests)
BASE_URL=http://localhost:8001 npm run test:e2e:oidc
```

### OIDC auto-redirect

Redirect tests live in a separate Playwright project (`chromium-oidc-redirect`) and are automatically excluded from the default `chromium`/`firefox` projects.

```bash
# Enable auto-redirect (recreates app_test only, Keycloak stays running)
OIDC_AUTO_REDIRECT=true docker compose -f docker-compose.test.yml up -d app_test

# Run OIDC redirect tests
cd frontend
BASE_URL=http://localhost:8001 npm run test:e2e:oidc-redirect

# Switch back to button mode
docker compose -f docker-compose.test.yml up -d app_test
```

## npm Scripts

| Script                           | Description                                           |
| -------------------------------- | ----------------------------------------------------- |
| `npm run test:e2e`               | E2E tests (Chromium, excludes OIDC, plugin, whatsapp) |
| `npm run test:e2e:whatsapp`      | WhatsApp API smoke tests (requires stub server)       |
| `npm run test:e2e:firefox`       | E2E tests (Firefox, excludes OIDC, plugin, whatsapp)  |
| `npm run test:e2e:oidc`          | Full suite with OIDC button login (skips `@password`) |
| `npm run test:e2e:oidc-button`   | OIDC button login/logout tests only                   |
| `npm run test:e2e:oidc-redirect` | OIDC auto-redirect tests (separate project)           |
| `npm run test:e2e:ui`            | Interactive Playwright UI mode                        |
| `npm run test:e2e:report`        | View HTML test report                                 |

All scripts accept additional Playwright args via `--`, e.g. `npm run test:e2e -- --grep "@smoke"`.

## Test Tags

Tests use tags in their names for filtering:

| Tag              | Description                                             |
| ---------------- | ------------------------------------------------------- |
| `@ci`            | Runs in CI                                              |
| `@whatsapp`      | WhatsApp stub tests; excluded from default runs         |
| `@oidc`          | Requires Keycloak (`--profile oidc`)                    |
| `@oidc-button`   | OIDC login via button click                             |
| `@oidc-redirect` | OIDC auto-redirect (requires `OIDC_AUTO_REDIRECT=true`) |
| `@password`      | Password-only tests (e.g. registration)                 |
| `@auth`          | Authentication-related                                  |
| `@smoke`         | Quick smoke tests                                       |

## Environment Variables

| Variable      | Default                 | Description                                                              |
| ------------- | ----------------------- | ------------------------------------------------------------------------ |
| `BASE_URL`    | `http://localhost:5173` | App URL to test against                                                  |
| `AUTH_METHOD` | `password`              | `password` or `oidc` — switches the generic `login()` helper to use OIDC |
| `AUTH_USER`   | `admin@synaplan.com`    | Password-auth email                                                      |
| `AUTH_PASS`   | `admin123`              | Password-auth password                                                   |
| `OIDC_USER`   | `testuser@synaplan.com` | Keycloak test user email                                                 |
| `OIDC_PASS`   | `testpass123`           | Keycloak test user password                                              |
| `MAILHOG_URL` | `http://localhost:8025` | MailHog API (registration + email smoke tests)                           |

## CI Matrix

CI runs 4 parallel e2e jobs:

| Job                 | npm Script               | Grep  | Keycloak                   |
| ------------------- | ------------------------ | ----- | -------------------------- |
| Password (Chromium) | `test:e2e`               | `@ci` | No                         |
| Password (Firefox)  | `test:e2e:firefox`       | `@ci` | No                         |
| OIDC                | `test:e2e:oidc`          | `@ci` | Yes                        |
| OIDC Redirect       | `test:e2e:oidc-redirect` | —     | Yes (`AUTO_REDIRECT=true`) |

## Configuration

Override defaults via environment variables:

```bash
BASE_URL=http://localhost:8001 npm run test:e2e
AUTH_USER=custom@example.com AUTH_PASS=secret npm run test:e2e
```

## Troubleshooting

**"Invalid credentials"** after `docker compose down -v` → Fixtures marker file survives on host. Fix:

```bash
rm backend/var/.fixtures_loaded
docker compose -f docker-compose.test.yml restart app_test
```

**Keycloak "Client not found"** → Stale realm from previous run. Full teardown:

```bash
docker compose -f docker-compose.test.yml --profile oidc down -v
docker compose -f docker-compose.test.yml --profile oidc up -d
```

**"Connection refused"** → Ensure test stack is running and healthy:

```bash
docker compose -f docker-compose.test.yml up --wait
```

**EACCES errors** → Fix permissions:

```bash
cd frontend && rm -rf node_modules package-lock.json && npm install
```
