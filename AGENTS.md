---
name: Synaplan
description: AI-powered knowledge management system with RAG, chat widgets, and multi-channel integration
---

# Synaplan Development Guide for AI Agents

## ⚠️ CRITICAL MERGE RULES - READ FIRST ⚠️

**NEVER blindly accept one version during merge conflicts.**

When resolving merge conflicts:
1. **ALWAYS manually merge both sides** - understand what each side adds/changes
2. **NEVER use `git checkout --ours` or `git checkout --theirs`** for code files
3. **Read and understand BOTH versions** before resolving
4. **Preserve ALL functionality** from both branches unless explicitly instructed otherwise
5. **If unsure, ASK** - throwing away code is worse than asking for clarification

Example: If HEAD has auth failure loop detection and theirs has schema validation, the merged version MUST have BOTH features, not just one.

**This is non-negotiable. Violating this rule causes data loss and broken functionality.**

## Project Overview

Synaplan is a full-stack AI knowledge management platform with:
- **RAG System**: Document processing, vectorization (bge-m3), semantic search with MariaDB VECTOR
- **Multi-Channel AI**: Web chat, embeddable widgets, WhatsApp, Email
- **File Processing**: Tika (documents), Tesseract OCR (images), Whisper.cpp (audio)
- **Multiple AI Providers**: Ollama (local), OpenAI, Anthropic, Groq, Gemini

**Tech Stack:**
- **Backend**: PHP 8.3, Symfony 7, MariaDB 11.8 with VECTOR support
- **Frontend**: Vue 3, TypeScript 5.5, Vite 5.4, Tailwind CSS 4
- **Infrastructure**: Docker Compose, frankenphp/caddy server
- **Widget**: ES modules with dynamic imports, code-splitting

## Commands

**Note:** Use `make` for build/test/quality commands. Use `docker compose` for service management.

### Quick Reference

```bash
# Service management (use docker compose directly)
docker compose up -d       # Start all services
docker compose down        # Stop services
docker compose logs -f backend  # View logs
docker compose restart backend  # Restart service

# Quality checks (runs in backend + frontend)
make lint                  # Check code (backend PSR-12 + frontend types)
make format                # Fix backend formatting
make test                  # Run all tests (backend + frontend)
make audit                 # Security audit

# Building
make build                 # Build everything (frontend app + widget)

# Dependencies
make deps                  # Install all dependencies

# See all available targets
make help                  # Common commands
make -C backend help       # Backend-specific
make -C frontend help      # Frontend-specific
```

### Development Workflow
```bash
# Start all services (auto-setup database on first run)
docker compose up -d

# Stop services
docker compose down

# Access services
# Frontend: http://localhost:5173
# Backend API: http://localhost:8000
# phpMyAdmin: http://localhost:8082
# MailHog: http://localhost:8025

```

Frontend and widget assets are added to the backend docker image in CI builds and automatically mounted to the backend container in development.

### Backend (PHP/Symfony)

```bash
# Code quality
make -C backend lint                  # Check PSR-12 formatting
make -C backend format                # Fix formatting
make -C backend phpstan               # Static analysis
make -C backend audit                 # Security audit

# Testing
make test                  # Run all tests (or: make -C backend test)

# Database
make -C backend migrate    # Run migrations
make -C backend fixtures   # Load fixtures
make -C backend schema-update  # Update schema (dev only!)
make -C backend schema-diff    # Show schema differences

# Symfony console
make -C backend console -- list       # List commands
make -C backend console -- cache:clear  # Clear cache
make -C backend shell                 # Open bash shell

# Dependencies
make -C backend deps
```

### Frontend (Vue/TypeScript)

**Note:** All frontend commands run inside Docker (via `docker compose exec frontend`).

```bash
# Building
# This is for production builds, usually we use the dev server at http://localhost:5173
make -C frontend build         # Build everything (app + widget) in Docker
# This should usually happen automatically via the frontend-widgets docker-compose service
make -C frontend build-widget  # Build widget only in Docker

# Testing & Type Checking
make -C frontend lint          # Check types in Docker
make -C frontend test          # Run all tests in Docker

# Dependencies
make -C frontend deps          # Install npm dependencies in Docker
```

### Git Operations

**⚠️ CRITICAL: NEVER ADD ATTRIBUTION TO COMMITS ⚠️**

Use conventional commits and pull request titles.
Use GitHub CLI (gh) to access pull request information like reviews and ci logs.

**Commit Message Rules:**
- ✅ Use conventional commit format (feat:, fix:, refactor:, etc.)
- ✅ Write clear, concise commit messages
- ❌ **NEVER ADD** attribution footers like "Generated with Claude Code" or "Co-Authored-By: Claude"
- ❌ **NO** AI assistant signatures or credits in commit messages

**Commit Checklist:**
- [ ] Conventional commit format used
- [ ] Code linted (`make lint`)
- [ ] Tests passing (`make test`)
- [ ] **NO attribution footer added**

```bash
# Create feature branch
git checkout -b feat/your-feature-name

# Before committing
make lint                  # Check backend + frontend
make test                  # Run all tests

# Commit with conventional format (NO ATTRIBUTION!)
git commit -m "feat: add new feature"
git commit -m "fix: resolve bug"
git commit -m "refactor: improve code structure"
```

## Project Structure

```
synaplan/
├── backend/              # Symfony PHP backend
│   ├── src/
│   │   ├── Controller/   # API endpoints
│   │   ├── Entity/       # Doctrine entities
│   │   ├── Repository/   # Database queries
│   │   └── Service/      # Business logic
│   ├── tests/            # PHPUnit tests
│   └── public/           # Entry point
├── frontend/             # Vue frontend
│   ├── src/
│   │   ├── components/   # Vue components
│   │   ├── views/        # Page views
│   │   ├── stores/       # Pinia state
│   │   ├── services/api/ # API clients
│   │   └── widget.ts     # Widget entry point
│   ├── dist/             # Built frontend (gitignored)
│   └── dist-widget/      # Built widget (gitignored)
├── _docker/              # Docker configurations
├── .github/workflows/    # CI/CD pipelines
└── docker-compose.yml    # Service orchestration
```

## Code Style Standards

### Editor Configuration

The project uses `.editorconfig` to enforce consistent formatting across all files:

**Global defaults:**
- Charset: UTF-8
- Line endings: LF (Unix-style, never CRLF)
- Indentation: 2 spaces
- Insert final newline: yes
- Trim trailing whitespace: yes

**PHP-specific:**
- Indentation: 4 spaces (overrides global default)

**Important:** Always use LF line endings, never CRLF. This is enforced in `.editorconfig` and should be respected by all editors.

### PHP (Backend)

**Standards:**
- PSR-12 compliance enforced by php-cs-fixer
- Symfony coding conventions
- 4-space indentation
- Type hints required (strict types)
- Readonly properties when possible
- Final classes by default
- Import statements (`use`) sorted lexicographically (alphabetically)
- No spaces around string concatenation operator (`.`) - use `$a.$b` not `$a . $b`
- PHPStan level 5 compliance (static analysis must pass)

**Example:**
```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\Widget;
use App\Repository\WidgetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class WidgetService
{
    public function __construct(
        private EntityManagerInterface $em,
        private WidgetRepository $widgetRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function createWidget(User $owner, string $name): Widget
    {
        $widget = new Widget();
        $widget->setOwner($owner);
        $widget->setName($name);

        $this->em->persist($widget);
        $this->em->flush();

        return $widget;
    }
}
```

### TypeScript (Frontend)

**Standards:**
- No semicolons
- Single quotes for strings
- 2-space indentation
- Explicit types (no `any`)
- Interfaces for object shapes
- Async/await (not .then())

**Example:**
```typescript
export interface Widget {
  id: number
  widgetId: string
  name: string
  config: WidgetConfig
  isActive: boolean
}

export async function createWidget(
  name: string,
  config: WidgetConfig
): Promise<Widget> {
  const data = await httpClient<{ widget: Widget }>(
    '/api/v1/widgets',
    {
      method: 'POST',
      body: JSON.stringify({ name, config })
    }
  )
  return data.widget
}
```

### Vue Components

**Standards:**
- Composition API with `<script setup>`
- TypeScript required
- Props with interfaces
- Emits with type safety
- No Options API
- Translations through `vue-i18n` are required

**Example:**
```vue
<script setup lang="ts">
import { ref } from 'vue'

interface Props {
  widgetId: string
  primaryColor?: string
}

interface Emits {
  (e: 'open'): void
  (e: 'close'): void
}

const props = withDefaults(defineProps<Props>(), {
  primaryColor: '#007bff'
})

const emit = defineEmits<Emits>()

const isOpen = ref(false)

function handleOpen() {
  isOpen.value = true
  emit('open')
}
</script>
```

### Widget Development

**Critical Rules:**
- Widget must work cross-origin (CORS-ready)
- Use `detectApiUrl()` from `widget-utils.ts` instead of hardcoding the url (or reintroducing a build time flag)

## Git Workflow

### Branch Naming
- `feat/description` - New features
- `fix/description` - Bug fixes
- `refactor/description` - Code refactoring
- `docs/description` - Documentation
- `chore/description` - Maintenance

### Commit Message Format
Use conventional commits:
```
feat: add widget lazy loading
fix: resolve CORS issue in widget
refactor: simplify API client
docs: update README with widget info
chore: update dependencies
```

You can put the component in braces, e.g. `feat(frontend): add loading spinner for chat responses`

### Pull Request Process
1. Create branch from `main`
2. Make changes
3. Run `make lint` and `make test` locally
4. Push and create PR
5. Wait for CI to pass (lint, tests, build, docker)
6. Request review
7. Merge when approved

### CI Requirements
All checks must pass:
- ✅ PHP code formatting (PSR-12)
- ✅ PHPStan static analysis
- ✅ Backend tests (PHPUnit)
- ✅ Frontend type check
- ✅ Frontend tests (Vitest)
- ✅ Frontend build
- ✅ Widget build
- ✅ Docker image build

## Environment Variables

### Backend (.env)

**Required:**
```bash
# Application
APP_ENV=dev
APP_SECRET=your-secret-key

# Database
DATABASE_WRITE_URL=mysql://user:pass@db:3306/synaplan
DATABASE_READ_URL=mysql://user:pass@db:3306/synaplan

# URLs
SYNAPLAN_URL=http://localhost:8000  # Public URL for widget embeds
FRONTEND_URL=http://localhost:5173  # Public URL for generated links in emails etc.
```

**Optional:**
- AI Provider keys (OPENAI_API_KEY, ANTHROPIC_API_KEY, GROQ_API_KEY)
- WhatsApp Business API credentials
- Email configuration
- Stripe payment keys

See `backend/.env.example` for complete list.

### Frontend (.env)
Generally not needed - API URL detected from backend or widget script source.

*MUST* *NOT* contain runtime settings. *VITE_* values are forbidden in general.
We are an open source application and we don't know at build time where we will be hosted, what keys to use etc. at runtime.
We can only have runtime settings through the `configStore` (for the regular application, the widget needs individual solutions to avoid build time switches).

## Key Architecture Decisions

### Widget System
- **Entry point**: `frontend/src/widget.ts`
- **Build**: Dedicated `vite.config.widget.ts`
- **Loading**: Lazy loading with dynamic imports
- **API URL**: Detected from script source URL at runtime
- **Caching**: Entry point no-cache, chunks immutable

### URL Configuration
- **FRONTEND_URL**: used in generated links in emails etc.
- **SYNAPLAN_URL**: Public URL where backend+widgets are served (used in embed codes)
- In production, these are usually the same. In development `FRONTEND_URL` points to the Vite Dev Server.

### Caddyfile Routing Order
1. Widget files (`/widget.js`, `/chunks/*`)
2. Frontend static files (from dist/)
3. Backend static files (`/bundles/*`, `/uploads/*`)
4. Backend PHP routes (`/api/*`)
5. Frontend SPA fallback (`/index.html`)

### Database Auto-Setup
Dev containers automatically:
1. Wait for database connection
2. Update schema if needed
3. Load fixtures if database empty

## Testing

### Backend Tests (PHPUnit)
```bash
# Run all tests
make -C backend test

# Run specific test file
docker compose exec backend php bin/phpunit tests/Controller/WidgetControllerTest.php

# With filter
docker compose exec backend php bin/phpunit --filter testWidgetCreation
```

### Frontend Tests (Vitest)
```bash
# Run all tests
make -C frontend test

# UI mode
cd frontend && npm run test:ui

# Coverage report
cd frontend && npm run test:coverage

# Watch mode
cd frontend && npm run test -- --watch
```

## Boundaries

### ✅ Always Do
- Run `make lint` before committing (checks backend + frontend)
- Run `make test` to run all tests
- Write type-safe code (no `any`, no missing types)
- Use existing API clients from `services/api/`
- Test widget changes with `widget-test.html`
- Follow PSR-12 for PHP, project conventions for TypeScript
- Use conventional commit messages
- Update tests when changing functionality
- Use `make help` to discover available commands

### ⚠️ Ask First Before
- Changing database schema (migrations required)
- Adding new npm or composer dependencies
- Modifying build configurations (vite.config, webpack)
- Changing Docker configurations or docker-compose.yml
- Modifying CI/CD workflows (.github/workflows)
- Changing environment variable structure
- Adding new AI provider integrations
- Modifying Caddyfile routing logic

### ❌ Never Do
- **Add attribution to commit messages** (NO "Generated with Claude Code", NO "Co-Authored-By: Claude")
- Commit secrets, API keys, or credentials
- Use `VITE_API_URL` environment variable (removed from codebase)
- Use `VITE_*` env vars for runtime configuration (build-time only, we need runtime config)
- Hardcode `http://localhost:8000` as API URL fallback
- Skip type checking, linting or tests
- Edit `vendor/` or `node_modules/` directories
- Commit `dist/` or `dist-widget/` directories
- Use `git add .` without reviewing changes
- Push directly to `main` branch
- Use `--no-verify` to skip git hooks
- Force push to `main` or `master`
- Commit `.env` files with real credentials
- Skip tests when making changes

## Common Patterns

### API Clients (Frontend)
Always use existing clients in `src/services/api/`:
```typescript
import { createWidget } from '@/services/api/widgetsApi'

// Good ✅
const widget = await createWidget(name, config)

// Bad ❌ - Don't write raw fetch calls
const response = await fetch('/api/v1/widgets', { ... })
```

The client is rather limited, but please prompt the user how it should be extended to fit the new use case.

**Zod Schema Validation (Required):**
All HTTP requests in the frontend **MUST** use Zod schema validation for type safety and runtime validation:

```typescript
import { httpClient } from '@/services/api/httpClient'
import { GetWidgetResponseSchema } from '@/generated/api-schemas'
import { z } from 'zod'

// Prefer using auto-generated schemas from OpenAPI annotations
type Widget = z.infer<typeof GetWidgetResponseSchema>

// Use generated schema with httpClient
const widget = await httpClient('/api/v1/widgets/123', {
  schema: GetWidgetResponseSchema
})
// widget is now typed as Widget and validated at runtime
```

**Benefits:**
- Type safety: Types are inferred from schemas (single source of truth)
- Runtime validation: Catches API contract violations immediately
- Better error messages: Zod provides detailed validation errors
- Maintainability: Schema changes automatically update types
- Auto-generated: Schemas are automatically generated from backend OpenAPI annotations

**OpenAPI Annotations (Backend):**
When creating or modifying API endpoints, write **detailed OpenAPI annotations** in PHP controllers.
This enables automatic generation of type-safe Zod schemas for the frontend:

```php
#[OA\Response(
    response: 200,
    description: 'Widget details',
    content: new OA\JsonContent(
        required: ['id', 'widgetId', 'name', 'config', 'isActive'],
        properties: [
            new OA\Property(property: 'id', type: 'integer', example: 123),
            new OA\Property(property: 'widgetId', type: 'string', example: 'wgt_abc123'),
            new OA\Property(property: 'name', type: 'string', example: 'Support Chat'),
            new OA\Property(
                property: 'config',
                type: 'object',
                required: ['primaryColor'],
                properties: [
                    new OA\Property(property: 'primaryColor', type: 'string', example: '#007bff'),
                ]
            ),
            new OA\Property(property: 'isActive', type: 'boolean', example: true),
        ]
    )
)]
```

Schemas are automatically generated during:
- Dev container startup (waits for backend to be ready)
- Frontend build (pre-build hook)
- CI pipeline (from OpenAPI artifact)
- Manual: `npm run generate:schemas` in frontend/

**Updating Frontend Schemas After Backend Changes:**

When you modify OpenAPI annotations in PHP controllers, regenerate the frontend schemas:

```bash
# Option 1: Inside frontend container (recommended)
docker compose exec frontend npm run generate:schemas

# Option 2: Via make
make -C frontend generate-schemas

# Option 3: Restart frontend container (auto-generates on startup)
docker compose restart frontend
```

The generation script:
1. Fetches OpenAPI spec from `http://backend/api/doc.json`
2. Generates Zod schemas to `src/generated/api-schemas.ts`
3. Fixes Zod v4 compatibility issues
4. Creates readable PascalCase aliases (e.g., `GetWidgetResponseSchema`)

### Error Handling (Backend)
```php
try {
    $widget = $this->widgetService->createWidget($user, $name);
    return $this->json(['success' => true, 'widget' => $widget]);
} catch (\InvalidArgumentException $e) {
    return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
} catch (\Exception $e) {
    $this->logger->error('Widget creation failed', ['error' => $e->getMessage()]);
    return $this->json(['error' => 'Internal error'], Response::HTTP_INTERNAL_SERVER_ERROR);
}
```

### State Management (Frontend)
Use Pinia stores:
```typescript
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
if (!authStore.isAuthenticated) {
  router.push('/login')
}
```

## Troubleshooting

### "VITE_API_URL not defined"
- Don't use it! It was removed.
- Widget: Use `detectApiUrl()` from widget-utils
- Admin UI: Use `useConfigStore().apiBaseUrl`

### "Docker cache not updating base images"
- CI uses GitHub Actions cache (`type=gha`)
- Base images won't update unless Dockerfile changes
- Consider monthly cache-busting workflow

### "Tests fail after schema change"
- Run migrations: `make -C backend migrate`
- Update fixtures if needed: `make -C backend fixtures`
- Clear cache: `make -C backend console -- cache:clear`

## Resources

- [Symfony Documentation](https://symfony.com/doc/current/index.html)
- [Vue 3 Documentation](https://vuejs.org/guide/introduction.html)
- [Vite Documentation](https://vite.dev/guide/)
- [Docker Compose Documentation](https://docs.docker.com/compose/)

## Notes for AI Agents

- When suggesting code changes, always respect existing patterns
- Provide complete code examples, not snippets
- Check for TypeScript errors before suggesting
- Consider CORS and cross-origin implications for widgets
- Remember: this is a multi-tenant SaaS with security requirements
