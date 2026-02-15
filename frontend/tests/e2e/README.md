# Playwright E2E Tests

## Quick Start (dev stack)

```bash
docker compose up -d   # Backend :8000, Frontend :5173, MailHog :8025/:1025
make build             # Build frontend + widget (needed for widget tests)
cd frontend
npm install
npx playwright install --with-deps
npm run test:e2e                        # All tests
npm run test:e2e -- -g "id=013"         # Single test
npm run test:e2e:ui                     # Playwright UI
```

## Switching between dev stack and test stack

Switching between the two stacks gives you a **CI-like environment**: the test stack uses the same config as CI (TestProvider, port 8001, tmpfs DB), so you can run locally what the pipeline runs.

Both stacks share **MailHog ports** (8025/1025) — always stop one before starting the other.

### Dev → Test stack

```bash
docker compose down                                     # Stop dev stack
sudo rm -rf frontend/dist frontend/dist-widget          # Remove root-owned build artifacts (if permission error)
make test-stack-build                                   # Build + start test stack, waits until healthy (~30-60s)
```

Then run tests from the **frontend** directory (see [Test commands](#test-commands) below).

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

- **Same as CI:** Start the test stack (`make test-stack-build`), then run tests with `npm run test:e2e:teststack`. Matches the CI environment (port 8001, TestProvider, tmpfs DB).
- **Switching stacks:** See [Switching between dev stack and test stack](#switching-between-dev-stack-and-test-stack) above.

## Test commands

From the **frontend** directory:

| What                               | Command                                                       |
| ---------------------------------- | ------------------------------------------------------------- |
| **Dev stack**: all tests           | `npm run test:e2e`                                            |
| **Dev stack**: single test         | `npm run test:e2e -- -g "id=013"`                             |
| **Test stack**: all tests          | `npm run test:e2e:teststack`                                  |
| **Test stack**: CI-like (no @noci) | `npm run test:e2e:teststack -- --grep-invert "@noci"`         |
| **Test stack**: single test        | `npm run test:e2e:teststack -- -g "id=020"`                   |
| **Test stack**: Playwright UI      | `npm run test:e2e:teststack:ui`                               |
| **API tests only**                 | `npm run test:e2e -- --grep "@api"` (or `test:e2e:teststack`) |

Everything after `--` is passed through to Playwright. API tests live in `tests/api/` and use the same config and helpers; they hit the backend via `getApiUrl()` and can use `getAuthHeaders(request)` for authenticated calls.

## Reload fixtures (test stack)

Test DB is tmpfs — `down` + `up` gives a fresh DB with fixtures.  
If the stack is still running:

```bash
docker compose -f docker-compose.test.yml exec app_test rm -f /var/www/backend/var/.fixtures_loaded
docker compose -f docker-compose.test.yml restart app_test
```

## Overriding URLs

Edit **`tests/e2e/.env.test`** (e.g. `BASE_URL=http://localhost:9000`).  
`.env.test` is loaded only when running **`npm run test:e2e:teststack`** (which sets `E2E_STACK=test`). For `npm run test:e2e` (dev stack), only `.env.local` applies.
