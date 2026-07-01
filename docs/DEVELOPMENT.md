# Development Guide

Commands and workflows for developing Synaplan.

## Service Management

```bash
# Start all services
docker compose up -d

# Stop all services
docker compose down

# View logs (follow mode)
docker compose logs -f
docker compose logs -f backend    # specific service
docker compose logs -f worker     # async job consumer (Messenger)

# Restart a service
docker compose restart backend
docker compose restart frontend
docker compose restart worker
```

### The `worker` service (async jobs)

Async jobs — chat `ProcessMessageCommand`, re-vectorization, plugin installs, widget crawling, and the background media-render advancer (`AdvanceMediaJobCommand`) — run in the dedicated `worker` container, which consumes the Redis Streams transports (`async_ai_high`, `async_extract`, `async_index`) via `messenger:consume`. Without it, queued work never executes and the UI hangs on any "running in background" flow (including async video generation).

The worker boot script (`docker-compose.yml`) is **fail-fast**:

- If the `./backend` bind-mount is broken (no `bin/console`), it exits with `64` instead of looping forever on a silenced error (this used to hang the worker for hours unnoticed).
- The DB-ready wait is bounded to ~3 minutes and prints the actual SQL error every 10 retries.
- It logs the resolved `APP_ENV` on boot.
- A Docker `healthcheck` probes for the live `messenger:consume` process every 30 s — `docker compose ps` reports `(unhealthy)` immediately if the consumer ever dies.

The worker **MUST run in the same `APP_ENV` as the backend container**. The `RedisService` prefixes every key with `synaplan:{env}:`, so a mismatch (e.g. backend `dev` / worker `prod`) silently splits the system in two: the worker consumes `AdvanceMediaJobCommand` messages but `findByKey()` reads the wrong namespace and returns `null`, leaving the job stuck in `queued` until the reaper times it out 20 min later. Local dev uses `APP_ENV=dev` for both; production uses `prod` for both (see `synaplan-platform/docker-compose.yml`).

After switching branches a `docker compose restart worker` is enough to pick up code changes (the entrypoint clears and re-warms the cache).

#### Troubleshooting stuck media jobs

The chat bubble for an async video shows `Auftrag läuft noch / Job still running` indefinitely:

1. **Is the worker healthy?** `docker compose ps worker` must show `(healthy)`. If not, check `docker compose logs worker` for the FATAL line.
2. **Did the message reach the queue?** `docker compose exec -T redis redis-cli XLEN async_index` should be ≥ 1 right after the request, then drop to 0 within a second when the worker picks it up.
3. **Is the job actually in Redis?** `docker compose exec -T redis redis-cli --raw KEYS 'synaplan:*:mediajob:*'` lists every active job and tells you which environment prefix is being used. A backend/worker env mismatch is visible here as two different prefixes.
4. **Re-arm a stuck job** without waiting for the reaper:
   `docker compose exec -T backend php bin/console app:media:advance-jobs <job_id>` (or `--all` for every active job).
5. **The reaper backstop** (`app:media:reap-jobs`) drives every stale / past-deadline job to `timed_out` with a localized error so no bubble hangs forever. Run it from cron (every minute) or manually.

### Redis (cache, sessions, locks, messenger, realtime)

The backend uses **Redis** (`redis` service in Compose) as the canonical cross-node platform store. One Redis instance, five concerns:

| Concern | Where it's wired | Why Redis (vs alternative) |
|---------|------------------|----------------------------|
| Symfony Cache (`cache.app` + `cache.provider_status` / `cache.model_config` / `cache.user_config`) | `backend/config/packages/cache.yaml` | A node-local filesystem cache silently desyncs when ≥3 backend nodes are behind a load balancer (production setup). Redis keeps Stripe idempotency, WhatsApp dedupe, JWKS, CircuitBreaker, etc. consistent across the cluster. |
| **Native PHP sessions** (`Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler`) | `backend/config/packages/framework.yaml` + `backend/config/services.yaml` | File-based sessions force sticky routing or silently log users out as they bounce between backend nodes. Prefix `synaplan_sess_`, TTL 7 days. |
| `LOCK_DSN` (cron-style commands, WhatsApp dedupe) | `backend/.env.example` | Cluster-wide mutex; `flock` would only synchronise one node. |
| **Async Messenger** transports (`async_ai_high`, `async_extract`, `async_index`, `failed`) | `backend/config/packages/messenger.yaml` | Redis Streams give blocking consumers + at-least-once delivery without amplifying writes across the Galera cluster. |
| Centrifugo realtime engine (logical separation through Centrifugo's own key prefix) | `_docker/centrifugo/config.json` | Cross-node WebSocket fan-out. |
| **Embedding cache** (`AiFacade::embed()` and `AiFacade::embedBatch()` share `embed.v1.*` keys, TTL 7 days) | `backend/src/AI/Service/AiFacade.php` | Same `(text, provider, model)` tuple is deterministic. `embedBatch()` looks up every input text individually against the same pool, sends only the misses to the provider as one batch call, and writes the new vectors back so a later `embed()` (or another batch) reuses them. The fallback path is intentionally NOT cached under the primary key — different model spaces must never mix. |

**phpredis (`ext-redis`)** is required by `symfony/redis-messenger` (the messenger consumer speaks the binary Redis protocol). Cache, sessions, locks, and the platform `App\Service\Infrastructure\RedisService` use **Predis** (pure PHP) and do **not** need it. The backend Docker image installs phpredis via `pecl install redis`. After pulling Dockerfile changes, **rebuild the backend image** so dev containers pick it up:

```bash
# Rebuild after Dockerfile changes
docker compose build backend && docker compose up -d backend

# Quick connectivity check inside the stack
docker compose exec redis redis-cli ping       # expects PONG

# Public health probe — reports `redis: { available, ... }`; returns 503
# in dev/prod if Redis is unreachable. PHPUnit reports `skipped: true`.
curl -sf http://localhost:8000/api/health | jq .redis
```

If you run PHP on the host without Compose, install Redis yourself (e.g. `brew install redis && brew services start redis`) and set `REDIS_DSN=redis://127.0.0.1:6379` in `backend/.env`. Without phpredis on the host, `composer install` will refuse to run unless you pass `--ignore-platform-req=ext-redis`; that's only safe if you do **not** plan to run `messenger:consume` from the host (cache + sessions still work via Predis).

**Production cutover note:** jobs that already sit in the legacy MariaDB `messenger_messages` table will **not** be auto-moved to Redis. Drain the queue (`messenger:consume … --limit=…`) or schedule a maintenance window before flipping `REDIS_DSN` in production.

### Connecting to services on the host

The backend container exposes **two** aliases for the Docker host (via `extra_hosts` in `docker-compose.yml`):

- `host.docker.internal` — Docker's standard cross-platform alias.
- `docker-host` — platform-compatible alias matching `synaplan-platform/docker-compose.yml`, so production-style env vars like `QDRANT_URL=http://docker-host:6333` work unchanged in dev.

Both resolve to the host gateway (`host-gateway`). Use `docker-host` in `backend/.env` if you want the same value to work in both dev and prod; use `host.docker.internal` if you're copy-pasting from generic Docker docs.

### Vector database (Qdrant)

Qdrant ships with the main compose file and starts automatically with `docker compose up -d`.
The backend reaches it via `QDRANT_URL=http://qdrant:6333` (or `http://docker-host:6333` for production parity).
No extra repo or external service needed.

---

## Code Quality

```bash
# Run all checks (backend + frontend)
make lint

# Fix backend formatting (PSR-12)
make format

# Run all tests
make test

# Security audit
make audit
```

---

## Backend (PHP/Symfony)

```bash
# Enter backend shell
make -C backend shell

# Run migrations
make -C backend migrate

# Load fixtures
make -C backend fixtures

# Clear cache
make -C backend console -- cache:clear

# Static analysis
make -C backend phpstan

# Run specific test
docker compose exec backend php bin/phpunit tests/Controller/SomeTest.php
```

---

## Frontend (Vue/TypeScript)

All commands run inside Docker:

```bash
# Build app + widget
make -C frontend build

# Build widget only
make -C frontend build-widget

# Type checking
make -C frontend lint

# Run tests
make -C frontend test

# Regenerate API schemas (after backend changes)
make -C frontend generate-schemas
```

---

## Database

```bash
# Reset database (deletes all data!)
docker compose down -v
docker compose up -d

# Run migrations
docker compose exec backend php bin/console doctrine:migrations:migrate

# Create migration
docker compose exec backend php bin/console doctrine:migrations:diff

# Access phpMyAdmin
open http://localhost:8082
```

---

## Widget Development

```bash
# Build widget
cd frontend && npm run build:widget

# Test widget locally
# Open frontend/widget-test.html in browser
```

---

## Git Workflow

### Branch Naming
- `feat/description` - New features
- `fix/description` - Bug fixes
- `refactor/description` - Code improvements
- `docs/description` - Documentation

### Commit Format
```bash
feat: add new feature
fix: resolve bug in widget
refactor: simplify API client
docs: update installation guide
```

### Before Committing
```bash
make lint    # Check formatting
make test    # Run tests
```

---

## Useful URLs (Development)

| Service | URL |
|---------|-----|
| Frontend | http://localhost:5173 |
| Backend API | http://localhost:8000 |
| API Docs (Swagger) | http://localhost:8000/api/doc |
| phpMyAdmin | http://localhost:8082 |
| MailHog | http://localhost:8025 |
| Ollama | http://localhost:11435 |

### GPU Support for Local AI Models

To use Ollama with your NVIDIA GPU, create a `docker-compose.override.yml`:

```yaml
services:
  ollama:
    runtime: nvidia
    environment:
      NVIDIA_VISIBLE_DEVICES: all
    deploy:
      resources:
        reservations:
          devices:
            - driver: nvidia
              count: all
              capabilities: [gpu]
```

Requires NVIDIA driver 550+ (for CUDA 13 compatibility with current Ollama images).

---

## Test Users

These accounts are created by the database fixtures (`make -C backend fixtures`):

| Email | Password | Level | Verified |
|-------|----------|-------|----------|
| admin@synaplan.com | admin123 | ADMIN | Yes |
| demo@synaplan.com | demo123 | PRO | Yes |
| test@example.com | test123 | NEW | No |

---

## Architecture

```
synaplan/
├── backend/           # Symfony PHP API
│   ├── src/
│   │   ├── Controller/   # API endpoints
│   │   ├── Entity/       # Doctrine entities
│   │   ├── Repository/   # Database queries
│   │   ├── Realtime/     # Centrifugo publisher, channels, authorizers, JWT minting
│   │   └── Service/      # Business logic
│   │       └── Message/  # Routing pipeline (classifier, sorter, Multitask/ planner + DAG)
│   └── tests/
├── frontend/          # Vue.js SPA
│   ├── src/
│   │   ├── components/   # Vue components (incl. multitask/ task-plan cards)
│   │   ├── views/        # Page views
│   │   ├── stores/       # Pinia state (incl. realtime connection store)
│   │   ├── services/api/ # API clients
│   │   └── services/realtime/ # Centrifugo WebSocket client + channel helpers
│   └── dist-widget/      # Built widget
├── _docker/           # Docker configs (backend, centrifugo, frontend)
└── docs/              # Documentation
```

---

## Message Routing (Multi-Task)

Inbound messages (chat, widget, WhatsApp, email, API) flow through a routing pipeline before any AI handler runs:

```
MessagePreProcessor → MessageClassifier ─┬→ rule-based routing (user task prompts)
                                         └→ MessageSorter (AI, tools:sort)
        → [MULTITASK enabled?] TaskPlanner (tools:plan) → DagExecutor → ResultAssembler
          [else / single-node]  InferenceRouter → ChatHandler | MediaGenerationHandler | FileAnalysisHandler
```

- **Multi-task plans**: for complex requests the planner decomposes the message into a small DAG of capability nodes (e.g. extract → summarize → text-to-speech → compose reply). Progress streams to the chat UI over the existing SSE channel as `plan` / `task_update` / `task_chunk` / `task_file` events, rendered as live task cards.
- **Feature flags** live in BCONFIG group `MULTITASK` (`ROUTING_ENABLED`, `SHADOW_MODE`, `PARALLEL_ENABLED`, `MAX_PARALLEL`, `NODE_TIMEOUT`) plus `CLASSIFIER.FAST_PATH_ENABLED` — see [CONFIGURATION.md](CONFIGURATION.md#multi-task-routing-bconfig).
- **Key classes**: `Service/Message/MessageProcessor`, `MessageClassifier`, `MessageSorter`, `InferenceRouter`, and `Service/Message/Multitask/` (`TaskPlanner`, `TaskPlanExecutor`, `Execution/DagExecutor`, `TaskPlanStore`).
- **Snapshot tests**: any change to the classifier/sorter contract drifts `tests/Characterization/RoutingCharacterizationTest.php` — re-record with `UPDATE_ROUTING_SNAPSHOTS=1` and review the diff (see [AGENTS.md](../AGENTS.md)).

**Streaming split**: AI chat tokens + task-plan progress use **SSE** (`/api/v1/messages/stream`); widget live-support events (takeover, typing, operator notifications) use **WebSockets** via Centrifugo (see [REALTIME.md](REALTIME.md)).

---

## More Resources

- [AGENTS.md](../AGENTS.md) - AI agent development guide
- [_devextras/planning/](../_devextras/planning/) - Internal design docs
