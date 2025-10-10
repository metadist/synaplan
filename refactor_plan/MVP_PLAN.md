# Synaplan Symfony 7 MVP - Solo Developer Plan

## Overview
Clean rebuild of Synaplan on Symfony 7 + API Platform. No hybrid/strangler approach - direct cutover when ready.

## Tech Stack
- **Backend**: Symfony 7 + API Platform (pure API)
- **Database**: MariaDB 11.8 (already migrated schema)
- **Queue**: Symfony Messenger + Redis
- **Cache**: Redis
- **Frontend**: Vue.js (separate repo: synaplan-ui)
- **AI**: Provider interfaces (Anthropic, OpenAI, Ollama, Test)

## Core Architecture

```
Request → Controller → Service → Provider/Repository
                ↓
         Messenger Queue → Worker → AI Provider
```

## MVP Milestones

### M1: Core Infrastructure (Week 1)
**Goal**: Basic API working with auth + health check

- [x] Symfony project setup with Docker
- [x] Doctrine entities from existing schema
- [x] JWT authentication (login, register, verify email, password reset)
- [x] Health check endpoint
- [ ] Basic CRUD for users/config

**Test**: `curl /api/health` returns OK, login works

### M2: AI Provider System (Week 2)
**Goal**: Message processing with AI

- [ ] Provider interfaces (Chat, Vision, Embedding, etc.)
- [ ] TestProvider (mock for dev/testing)
- [ ] AnthropicProvider + OpenAIProvider + OllamaProvider
- [ ] ProviderRegistry + AiFacade
- [ ] Basic rate limiting

**Test**: Send message → get AI response

### M3: Message Processing (Week 2-3)
**Goal**: Full message pipeline

- [ ] Message entities + repositories
- [ ] Messenger setup (async queues)
- [ ] Message handlers (PreProcessor, Classifier, Router, ChatHandler)
- [ ] SSE streaming for chat
- [ ] File upload + analysis

**Test**: Upload file → extract text → AI response

### M4: Frontend Integration (Week 3-4)
**Goal**: Vue.js frontend working

- [ ] API endpoints for chat
- [ ] Token refresh
- [ ] Message history
- [ ] Settings/config UI
- [ ] File upload UI

**Test**: Full user flow in browser

### M5: Production Ready (Week 4-5)
**Goal**: Deploy to production

- [ ] Docker production setup
- [ ] Basic monitoring (logs, metrics)
- [ ] Backup strategy
- [ ] SSL/domain setup
- [ ] Performance tuning

**Test**: Handle production load

## Key Services

### Core Services
```yaml
ProviderRegistry        # Manages AI providers
AiFacade               # Single entry for AI calls
MessagePreProcessor    # File download, extract text
MessageClassifier      # Detect topic, language, intent
InferenceRouter        # Route to handler
MessageHandlerInterface # Chat, Tools, Analyze handlers
CircuitBreaker         # Provider failover
```

### Repositories
```yaml
UserRepository
MessageRepository
ConfigRepository
ModelRepository
PromptRepository
ApiKeyRepository
RagDocumentRepository
```

## Development Workflow

### Daily Tasks
1. Pick next milestone task
2. Write code + tests
3. Test locally with TestProvider
4. Commit + deploy to staging
5. Test with real providers (if needed)

### Testing Strategy
- **Unit Tests**: Services, providers (mocked HTTP)
- **Integration Tests**: Repositories, message flow
- **E2E Tests**: API endpoints with TestProvider
- **Manual Tests**: Real AI providers (limited)

### Code Quality
- PHPStan Level 8
- PHP-CS-Fixer (PSR-12)
- Test coverage > 70%

## Environment Setup

### .env (required)
```env
# Database
DATABASE_URL=mysql://user:pass@mariadb:3306/synaplan

# Redis
REDIS_URL=redis://redis:6379
MESSENGER_TRANSPORT_DSN=redis://redis:6379/messages

# JWT
JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
JWT_PASSPHRASE=your-passphrase

# AI Providers (optional for dev with TestProvider)
ANTHROPIC_API_KEY=sk-ant-...
OPENAI_API_KEY=sk-...
OLLAMA_BASE_URL=http://ollama:11434

# Mailer
MAILER_DSN=smtp://mailhog:1025
APP_SENDER_EMAIL=noreply@synaplan.com
APP_URL=http://localhost:3000
```

### Docker Services
```yaml
services:
  app:        # Symfony PHP
  mariadb:    # Database
  redis:      # Cache + Queues
  mailhog:    # SMTP testing
  ollama:     # Local AI (optional)
```

## Critical Paths

### Message Flow
```
1. User sends message (POST /api/v1/messages)
2. Save to DB, return tracking_id (202 Accepted)
3. Dispatch to Messenger queue
4. Worker: PreProcess → Classify → Route → Handle
5. Save response to DB
6. (Optional) SSE stream chunks to frontend
```

### File Upload Flow
```
1. Upload file (POST /api/v1/files)
2. Save metadata, dispatch ExtractFileCommand
3. Worker: Download → Tika/OCR → Vectorize → Save to BRAG
4. Message references file_id for context
```

### Authentication Flow
```
1. Register → email verification token → SMTP
2. Verify email → activate account
3. Login → JWT token (24h)
4. Token refresh → new JWT
5. Password reset → token via email
```

## Deployment

### Staging
```bash
docker compose up -d
php bin/console doctrine:migrations:migrate
php bin/console messenger:consume async -vv
```

### Production
```bash
# Build & deploy
docker build -t synaplan-api .
docker push registry/synaplan-api:latest

# Update servers
ssh prod "docker pull ... && docker compose up -d"

# Run migrations
ssh prod "docker exec synaplan-api php bin/console doctrine:migrations:migrate --no-interaction"
```

### Rollback
```bash
# Revert to previous Docker image
docker compose pull synaplan-api:previous
docker compose up -d

# Rollback migration
php bin/console doctrine:migrations:migrate prev
```

## Monitoring Basics

### Health Check
```bash
curl /api/health
# Returns: DB status, Redis status, Provider status
```

### Logs
```bash
docker compose logs -f app
tail -f var/log/prod.log
```

### Metrics (basic)
- Request count per endpoint
- Error rate
- Queue length
- Response time P95

## Non-Goals (for MVP)
- ❌ Multi-tenancy
- ❌ Complex rate limiting per user level
- ❌ Read/Write DB splitting (single DB is fine)
- ❌ Horizontal scaling (single instance OK)
- ❌ Advanced monitoring (Prometheus/Grafana later)
- ❌ Legacy widget compatibility (rebuild widget)
- ❌ WhatsApp/Gmail webhooks (later)

## Success Criteria

### Week 2 ✓
- [x] Auth working (login, register, email verify, password reset)
- [ ] TestProvider responds to chat
- [ ] Message saved to DB

### Week 4 ✓
- [ ] Frontend chat working
- [ ] File upload + analysis
- [ ] Real AI providers connected

### Week 6 ✓
- [ ] Production deployed
- [ ] Users can chat with AI
- [ ] Basic monitoring active

## Next Steps

1. **Now**: Finish M2 (AI Provider System)
2. **This Week**: M3 (Message Processing)
3. **Next Week**: M4 (Frontend Integration)
4. **Week 4**: M5 (Production)

---

**Keep it simple. Ship fast. Iterate.**

