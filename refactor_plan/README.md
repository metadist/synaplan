# Synaplan Refactor Plan

## Overview
Clean rebuild of Synaplan on Symfony 7 + API Platform. Solo developer, pragmatic approach.

## Key Documents

### 📋 [MVP_PLAN.md](./MVP_PLAN.md)
**Start here!** Milestones, timeline, and daily workflow for solo developer.

### 🏗️ [ARCHITECTURE_SIMPLE.md](./ARCHITECTURE_SIMPLE.md)
System design, request flows, and core components.

### 🔌 [INTERFACES.md](./INTERFACES.md)
AI Provider interfaces (8 interfaces for different capabilities).

### 🗄️ [DATABASE_SCHEMA.md](./DATABASE_SCHEMA.md)
Doctrine entities, repositories, and database setup.

### ⚠️ [RISKS_MITIGATIONS.md](./RISKS_MITIGATIONS.md)
Potential issues and how to handle them.

### 📊 [SEQUENCE_DIAGRAMS.md](./SEQUENCE_DIAGRAMS.md)
Visual flows for key processes (auth, messages, files).

## Quick Start

```bash
# 1. Clone and setup
cd /home/ysf/projects/synaplan-sf
composer install

# 2. Configure .env
cp .env.example .env
# Edit: DATABASE_URL, REDIS_URL, JWT keys

# 3. Start Docker
docker compose up -d

# 4. Run migrations
php bin/console doctrine:migrations:migrate

# 5. Generate JWT keys
php bin/console lexik:jwt:generate-keypair

# 6. Test
curl http://localhost:8000/api/health
```

## Current Status

**✅ Completed (M1)**:
- Symfony 7 setup with Docker
- Doctrine entities + repositories
- JWT authentication
- Email verification + password reset
- Health check endpoint
- MailHog for local email testing

**🔄 In Progress (M2)**:
- AI Provider interfaces
- TestProvider implementation
- ProviderRegistry + AiFacade
- Message processing pipeline

**📋 Next (M3)**:
- Message handlers
- SSE streaming
- File upload + analysis
- RAG/Vector search

## Tech Stack

**Backend**:
- PHP 8.3
- Symfony 7.2
- API Platform 3.2
- Doctrine ORM
- Symfony Messenger
- LexikJWTAuthenticationBundle

**Storage**:
- MariaDB 11.8
- Redis 7

**AI Providers**:
- Anthropic (Claude)
- OpenAI (GPT-4)
- Ollama (Local)
- TestProvider (Mock)

**Frontend** (separate repo):
- Vue.js 3
- TypeScript
- Pinia
- Vue Router

## Development Principles

1. **Keep it simple**: One service, one database
2. **Test with mocks**: Use TestProvider for dev/CI
3. **Async by default**: Heavy work in queues
4. **Fail gracefully**: Circuit breaker for AI providers
5. **Document as you go**: Update docs when changing code

## File Structure

```
synaplan-sf/
├── config/          # Symfony config
├── migrations/      # Doctrine migrations
├── public/          # Entry point
├── refactor_plan/   # This folder
├── src/
│   ├── AI/          # Provider system
│   ├── Controller/  # API endpoints
│   ├── Entity/      # Doctrine entities
│   ├── Message/     # Messenger commands/handlers
│   ├── Repository/  # Database queries
│   ├── Service/     # Business logic
│   └── DTO/         # Data Transfer Objects
├── tests/           # PHPUnit tests
└── var/             # Cache, logs
```

## Help & Resources

- **Symfony Docs**: https://symfony.com/doc/current/
- **Doctrine Docs**: https://www.doctrine-project.org/
- **API Platform**: https://api-platform.com/docs/

---

**Focus on MVP. Ship early. Iterate fast.**
