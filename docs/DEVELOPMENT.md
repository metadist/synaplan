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

# Restart a service
docker compose restart backend
docker compose restart frontend
```

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

---

## Test Users

| Email | Password | Level |
|-------|----------|-------|
| admin@synaplan.com | admin123 | BUSINESS |
| demo@synaplan.com | demo123 | PRO |
| test@example.com | test123 | NEW |

---

## Architecture

```
synaplan/
├── backend/           # Symfony PHP API
│   ├── src/
│   │   ├── Controller/   # API endpoints
│   │   ├── Entity/       # Doctrine entities
│   │   ├── Repository/   # Database queries
│   │   └── Service/      # Business logic
│   └── tests/
├── frontend/          # Vue.js SPA
│   ├── src/
│   │   ├── components/   # Vue components
│   │   ├── views/        # Page views
│   │   ├── stores/       # Pinia state
│   │   └── services/api/ # API clients
│   └── dist-widget/      # Built widget
├── _docker/           # Docker configs
└── docs/              # Documentation
```

---

## More Resources

- [AGENTS.md](../AGENTS.md) - AI agent development guide
- [_devextras/planning/](../_devextras/planning/) - Internal design docs
