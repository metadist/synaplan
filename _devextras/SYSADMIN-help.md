# Synaplan Sysadmin Guide

Complete reference for managing Synaplan infrastructure.

## Architecture Overview

Synaplan uses a multi-repository architecture:

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         REPOSITORIES                                     │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  synaplan/              Main application source code                     │
│  ├── backend/           PHP/Symfony API                                  │
│  ├── frontend/          Vue/TypeScript app                               │
│  └── docker-compose.yml Local dev environment                            │
│                                                                          │
│  synaplan-platform/     Production deployment                            │
│  ├── docker-compose.yml Pulls ghcr.io/metadist/synaplan:latest          │
│  ├── startweb1.sh       Per-node startup scripts                         │
│  └── .env               Production secrets                               │
│                                                                          │
│  synaplan-memories/     Optional vector storage                          │
│  ├── qdrant-service/    Rust microservice                                │
│  └── docker-compose.yml Qdrant + service                                 │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

## Local Development vs Production

| Aspect | Local (`synaplan/`) | Production (`synaplan-platform/`) |
|--------|---------------------|-----------------------------------|
| Image | Built from source | `ghcr.io/metadist/synaplan:latest` |
| Database | Local MariaDB container | Galera cluster (multi-node) |
| Ollama | Local container | Shared server (10.0.1.10) |
| Frontend | Vite dev server (5173) | Built assets in Docker image |
| Dev tools | phpMyAdmin, MailHog | None |
| Memories | Optional | Connected via docker-host:8090 |

---

# LOCAL DEVELOPMENT

## Starting Local Environment

```bash
cd synaplan/

# Start all services (auto-setup database on first run)
docker compose up -d

# Stop services
docker compose down

# Restart specific service
docker compose restart backend
docker compose restart frontend
```

## Service URLs (Development)

| Service | URL | Purpose |
|---------|-----|---------|
| Frontend | http://localhost:5173 | Vue dev server |
| Backend API | http://localhost:8000 | PHP/Symfony API |
| phpMyAdmin | http://localhost:8082 | Database management |
| MailHog | http://localhost:8025 | Email testing |
| Ollama | http://localhost:11435 | Local AI models |
| Tika | http://localhost:9999 | Document extraction |

## Database Operations

```bash
# Run migrations
docker compose exec backend php bin/console doctrine:migrations:migrate

# Load test fixtures
docker compose exec backend php bin/console doctrine:fixtures:load

# Update schema (dev only!)
docker compose exec backend php bin/console doctrine:schema:update --force

# Clear cache
docker compose exec backend php bin/console cache:clear

# Open database shell
docker compose exec db mariadb -u root -proot_password synaplan
```

## Quality Checks

```bash
# All checks (backend + frontend)
make lint      # PSR-12 + TypeScript
make test      # PHPUnit + Vitest
make format    # Fix backend formatting

# Discover all commands
make help
make -C backend help
make -C frontend help
```

## Shell Access

```bash
docker compose exec backend bash    # Backend
docker compose exec frontend sh     # Frontend
docker compose exec db bash         # Database
```

---

# PRODUCTION DEPLOYMENT

## Multi-Server Architecture

```
                    ┌─────────────────┐
                    │   Load Balancer │
                    └────────┬────────┘
                             │
        ┌────────────────────┼────────────────────┐
        │                    │                    │
        ▼                    ▼                    ▼
┌───────────────┐    ┌───────────────┐    ┌───────────────┐
│   synweb100   │    │   synweb101   │    │   synweb102   │
│   (web1)      │    │   (web2)      │    │   (web3)      │
│   10.0.0.2    │    │   10.0.0.3    │    │   10.0.0.4    │
└───────┬───────┘    └───────┬───────┘    └───────┬───────┘
        │                    │                    │
        └────────────────────┼────────────────────┘
                             │
                    ┌────────┴────────┐
                    │  Galera Cluster │
                    │  (MariaDB)      │
                    └─────────────────┘
                             │
                    ┌────────┴────────┐
                    │  Shared Storage │
                    │  NFS: ./up/     │
                    └─────────────────┘
```

## Deploying to Production

```bash
cd synaplan-platform/

# Start on specific node (sets SYNDBHOST for Galera)
./startweb1.sh   # On synweb100
./startweb2.sh   # On synweb101
./startweb3.sh   # On synweb102

# Or manually:
export SYNDBHOST=10.0.0.2   # Local Galera node IP
docker compose up --pull always -d
```

## Production Environment

Key differences in `synaplan-platform/docker-compose.yml`:

```yaml
environment:
  APP_ENV: prod
  APP_URL: https://web.synaplan.com
  FRONTEND_URL: https://web.synaplan.com
  SYNAPLAN_URL: https://web.synaplan.com
  DATABASE_WRITE_URL: mysql://...@host.docker.internal:3306/synaplan
  OLLAMA_BASE_URL: http://10.0.1.10:11434        # Shared Ollama server
  TIKA_URL: http://tika.synaplan.com             # External Tika
  QDRANT_SERVICE_URL: http://docker-host:8090   # Memories service
```

## NFS Shared Storage

Uploads are shared across all nodes via NFS:

```bash
# Recommended NFS mount options (on each host)
mount -t nfs -o actimeo=1,lookupcache=positive server:/export/up /path/to/up
```

The `actimeo=1` prevents stale file issues when multiple nodes access the same files.

---

# QDRANT MEMORIES SERVICE

The optional memories service provides AI user profiling via vector search.

## Architecture

```
Synaplan Backend (PHP)
        │
        │ HTTP (port 8090)
        ▼
synaplan-qdrant-service (Rust)
        │
        │ gRPC (port 6334)
        ▼
Qdrant Vector Database
```

## Starting Memories Service

```bash
cd synaplan-memories/

# Start Qdrant + service
docker compose up -d

# Check health
curl http://localhost:8090/health
```

## Connecting Synaplan to Memories

### Local Development

Add to `synaplan/backend/.env`:

```bash
QDRANT_SERVICE_URL=http://synaplan-qdrant-service:8090
QDRANT_SERVICE_API_KEY=changeme-in-production
```

Then restart backend:
```bash
cd synaplan/
docker compose restart backend
```

### Production

Already configured in `synaplan-platform/docker-compose.yml`:
```yaml
QDRANT_SERVICE_URL: http://docker-host:8090
```

The `docker-host` alias resolves to the Docker host where qdrant-service runs.

## Verifying Connection

```bash
# Check service directly
curl http://localhost:8090/health

# Check Synaplan's connection
curl http://localhost:8000/api/v1/config/memory-service/check
# Should return: {"configured":true,"available":true}
```

## Memories Service Configuration

Environment variables in `synaplan-memories/docker-compose.yml`:

| Variable | Purpose |
|----------|---------|
| `QDRANT_URL` | Qdrant connection (http://qdrant:6334) |
| `QDRANT_COLLECTION_NAME` | Collection name (default: user_memories) |
| `QDRANT_VECTOR_DIMENSION` | Vector size (default: 1024 for bge-m3) |
| `SERVICE_API_KEY` | API key for authentication |
| `WEBHOOK_URL` | Optional Discord/Slack alerts |

## Cluster Mode

For high availability, Qdrant supports clustering. See `synaplan-memories/CLUSTER.md`.

---

# ENVIRONMENT VARIABLES

## Required (backend/.env)

```bash
APP_ENV=dev                           # or 'prod'
APP_SECRET=your-secret-key

# Database
DATABASE_WRITE_URL=mysql://user:pass@db:3306/synaplan
DATABASE_READ_URL=mysql://user:pass@db:3306/synaplan

# URLs
SYNAPLAN_URL=http://localhost:8000    # Public backend URL (widget embeds)
FRONTEND_URL=http://localhost:5173    # Public frontend URL (email links)
```

## Optional

| Variable | Purpose |
|----------|---------|
| `OPENAI_API_KEY` | OpenAI integration |
| `ANTHROPIC_API_KEY` | Claude integration |
| `GROQ_API_KEY` | Groq integration |
| `WHATSAPP_*` | WhatsApp Business API |
| `STRIPE_*` | Payment processing |
| `QDRANT_SERVICE_URL` | Memories service |
| `QDRANT_SERVICE_API_KEY` | Memories auth |

See `synaplan/backend/.env.example` for complete list.

---

# TROUBLESHOOTING

## Tests fail after schema change

```bash
docker compose exec backend php bin/console doctrine:migrations:migrate
docker compose exec backend php bin/console doctrine:fixtures:load
docker compose exec backend php bin/console cache:clear
```

## Frontend schemas out of sync

```bash
make -C frontend generate-schemas
# Or restart frontend (auto-generates on startup)
docker compose restart frontend
```

## Container won't start

```bash
docker compose logs backend
docker compose build --no-cache backend
docker compose up -d
```

## Database connection issues

```bash
docker compose ps              # Check health
docker compose restart backend
```

## Memories service unavailable

```bash
# Check qdrant-service is running
cd synaplan-memories/
docker compose ps
docker compose logs -f qdrant-service

# Verify URL is correct
# Local: http://synaplan-qdrant-service:8090 (same Docker network)
# Prod:  http://docker-host:8090
```

## Clear everything and start fresh

```bash
docker compose down -v   # Remove volumes too
docker compose up -d     # Fresh start with auto-setup
```

---

# CI REQUIREMENTS

All checks must pass before merge:

- PHP code formatting (PSR-12)
- PHPStan static analysis (level 5)
- Backend tests (PHPUnit)
- Frontend type check
- Frontend tests (Vitest)
- Frontend build
- Widget build
- Docker image build

---

# PRODUCTION NOTES

## URL Configuration

- **SYNAPLAN_URL**: Public URL where backend + widgets are served
- **FRONTEND_URL**: Public URL for generated links in emails
- In production, these are usually the same domain
- In development, FRONTEND_URL points to Vite dev server (port 5173)

## Caddyfile Routing Order

1. Widget files (`/widget.js`, `/chunks/*`)
2. Frontend static files (from dist/)
3. Backend static files (`/bundles/*`, `/uploads/*`)
4. Backend PHP routes (`/api/*`)
5. Frontend SPA fallback (`/index.html`)

## Database Auto-Setup

Dev containers automatically:
1. Wait for database connection
2. Run migrations if needed
3. Load fixtures if database empty

## Widget Caching

- Entry point (`widget.js`): no-cache headers
- Chunks (`/chunks/*`): immutable, long cache
