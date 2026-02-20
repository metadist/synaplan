# Playwright E2E Tests

## Quick Start

1. **Start the test stack**:

   ```bash
   docker compose -f docker-compose.test.yml up -d
   ```

2. **Install Node.js 20+** (if not installed):

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
   ```bash
   cd frontend
   npm run test:e2e
   ```

**Note:** Locally runs Chromium only. CI tests both Chromium and Firefox.

## Test Stack

All e2e tests run against `docker-compose.test.yml` (port 8001), not the dev stack.

### Password auth (default)

```bash
# Start test stack
docker compose -f docker-compose.test.yml up -d

# Run password-auth tests
cd frontend
BASE_URL=http://localhost:8001 npm run test:e2e
```

### OIDC prerequisites

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
| `npm run test:e2e`               | Password auth tests (Chromium, excludes OIDC)         |
| `npm run test:e2e:firefox`       | Password auth tests (Firefox, excludes OIDC)          |
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
| `MAILHOG_URL` | `http://localhost:8025` | MailHog API (for registration tests)                                     |

## CI Matrix

CI runs 4 parallel e2e jobs:

| Job                 | npm Script               | Grep  | Keycloak                   |
| ------------------- | ------------------------ | ----- | -------------------------- |
| Password (Chromium) | `test:e2e`               | `@ci` | No                         |
| Password (Firefox)  | `test:e2e:firefox`       | `@ci` | No                         |
| OIDC                | `test:e2e:oidc`          | `@ci` | Yes                        |
| OIDC Redirect       | `test:e2e:oidc-redirect` | —     | Yes (`AUTO_REDIRECT=true`) |

## Configuration

Optional: Create `frontend/tests/e2e/.env.local` to override defaults:

```bash
BASE_URL=http://localhost:8001
AUTH_USER=admin@synaplan.com
AUTH_PASS=admin123
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
