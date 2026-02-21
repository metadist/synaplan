---
name: Synaplan
description: AI-powered knowledge management system with RAG, chat widgets, and multi-channel integration
---

# Synaplan Development Guide

Full-stack AI knowledge management platform: RAG with MariaDB VECTOR, embeddable chat widgets, WhatsApp/Email integration, multiple AI providers (Ollama, OpenAI, Anthropic, Groq, Gemini).

**Stack:** PHP 8.3/Symfony 7, Vue 3/TypeScript/Vite, Docker Compose, frankenphp/caddy.

## Repository Architecture

| Repo | Purpose |
|------|---------|
| `synaplan/` | Main app source code, local development environment |
| `synaplan-base-php/` | Base Docker image (FrankenPHP + gRPC + whisper.cpp) |
| `synaplan-platform/` | Production deployment configs, pulls pre-built images |
| `synaplan-memories/` | Optional Qdrant vector storage for AI memories |

**Local dev** (`synaplan/`): Builds from source, includes all dev tools (phpMyAdmin, MailHog, Vite dev server).

**Production** (`synaplan-platform/`): Uses `ghcr.io/metadist/synaplan:latest`, multi-server with Galera cluster.

**Memories** (`synaplan-memories/`): Rust microservice + Qdrant for user profiling. Connect via `QDRANT_SERVICE_URL`.

See `_devextras/SYSADMIN-help.md` for deployment details.

## Critical Rules

### Merge Conflicts - NEVER Accept One Side Blindly

When resolving merge conflicts:
1. **Manually merge both sides** - understand what each adds/changes
2. **NEVER** use `git checkout --ours` or `git checkout --theirs` for code files
3. **Preserve ALL functionality** from both branches unless explicitly instructed
4. **If unsure, ASK** - throwing away code is worse than asking

### No Attribution in Commits

- Use conventional commits (feat:, fix:, refactor:, etc.)
- **NEVER** add "Generated with Claude Code", "Co-Authored-By: Claude", or similar

### MANDATORY Pre-Commit Gate - Run Tests BEFORE Every Commit

**You MUST run and pass ALL of these before committing or allowing a commit.** This matches what CI runs on GitHub. If any step fails, fix the issue before committing. No exceptions.

```bash
# Step 1: Backend lint (PSR-12 formatting)
make -C backend lint

# Step 2: Backend static analysis (PHPStan)
make -C backend phpstan

# Step 3: Backend tests (PHPUnit - all tests, not just Unit/)
make -C backend test

# Step 4: Frontend lint (Prettier + ESLint)
make -C frontend lint

# Step 5: Frontend type check (vue-tsc -b — catches errors ESLint misses!)
docker compose exec -T frontend npm run check:types

# Step 6: Frontend tests (Vitest)
make -C frontend test
```

**Or run everything in one shot:**
```bash
make lint && make -C backend phpstan && make test && docker compose exec -T frontend npm run check:types
```

**Rules:**
- Run the FULL test suite (`make test`), not just a subset like `tests/Unit/`
- If `make -C backend phpstan` fails, fix the type errors before committing
- If you changed frontend Vue/TS files, the frontend lint and tests are mandatory
- If you only changed backend PHP files, you may skip frontend checks
- If you changed backend OpenAPI annotations, run `make -C frontend generate-schemas` then re-run `vue-tsc`
- **NEVER** commit with failing tests — this blocks the entire CI/CD pipeline

## Essential Commands

```bash
# Start/stop services
docker compose up -d
docker compose down

# Quality checks (ALWAYS before committing)
make lint && make -C backend phpstan && make test

# Building
make build         # Frontend app + widget

# Discover more commands
make help
make -C backend help
make -C frontend help
```

## Key Constraints

### Frontend Runtime Config
- **NO** `VITE_*` env vars for runtime config - we're open source, runtime location unknown at build time
- Widget: use `detectApiUrl()` from `widget-utils.ts`
- App: use `useConfigStore().apiBaseUrl`
- **NEVER** hardcode `http://localhost:8000`

### Widget Development
- Must work cross-origin (CORS-ready)
- API URL detected from script source at runtime

### API Development
- Write detailed OpenAPI annotations on all endpoints
- Frontend schemas auto-generated from backend OpenAPI spec
- After changing annotations: `make -C frontend generate-schemas`

### i18n
- All UI text through `vue-i18n`
- **Always update BOTH** `en.json` AND `de.json`

## Code Style Quick Reference

| Area | Standard | Details |
|------|----------|---------|
| PHP | PSR-12, strict types, final readonly classes | See `docs/PHP_CONVENTIONS.md` |
| TypeScript | No semicolons, single quotes, no `any` | See `docs/FRONTEND_CONVENTIONS.md` |
| Vue | Composition API, `<script setup>`, TypeScript | See `docs/FRONTEND_CONVENTIONS.md` |
| API | Zod validation, OpenAPI annotations | See `docs/API_PATTERNS.md` |
| Styling | CSS variables from `style.css`, never Tailwind colors directly | See `docs/FRONTEND_CONVENTIONS.md` |

## Architecture Patterns

- **Controllers**: HTTP handling only, under 50 lines
- **Services**: All business logic, use DI
- **Repositories**: All database queries, no DQL in controllers
- **Components**: Modular, under 300 lines
- **API Clients**: Use existing clients in `services/api/`, Zod schemas required

## Boundaries

### Ask First Before
- Changing database schema (migrations required)
- Adding dependencies (npm/composer)
- Modifying Docker/CI/build configs
- Adding new AI provider integrations

### Never Do
- Commit secrets or `.env` files with credentials
- Edit `vendor/` or `node_modules/`
- Commit `dist/` directories
- Push directly to `main`
- Force push to `main` or `master`
- Skip lint/test before committing

## Detailed Documentation

For comprehensive guides, see:
- `docs/PHP_CONVENTIONS.md` - PHP code style, examples, patterns
- `docs/FRONTEND_CONVENTIONS.md` - TypeScript, Vue, styling, i18n
- `docs/API_PATTERNS.md` - Zod schemas, OpenAPI, httpClient usage
- `docs/DEVELOPMENT.md` - Development setup
- `backend/.env.example` - Environment variables
