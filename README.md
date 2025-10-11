# Synaplan Backend

Symfony 7 API backend for multi-AI chat platform with streaming, authentication, and database-driven model configuration.

## Quick Start

### 1. Setup Environment
```bash
# Copy example to .env.local and configure
cp .env.example .env.local
# Edit .env.local with your API keys and settings
```

### 2. Start Development Environment
```bash
docker compose up -d
# Or use Makefile: make dev-up
```

### 3. Run Migrations
```bash
docker compose exec app php bin/console doctrine:migrations:migrate
docker compose exec app php bin/console doctrine:fixtures:load
# Or use Makefile: make migrate && make fixtures
```

Access: http://localhost:8000

## Features

**Working:**
- User authentication (JWT, email verification, rate limiting)
- Real-time chat with SSE streaming
- Multi-provider AI integration (Ollama, OpenAI, Anthropic, Groq, Google)
- Message preprocessing and intelligent routing
- Database-driven prompts and model configuration
- Chat sessions with message history
- Model selection and "Again" functionality
- Circuit breaker pattern for resilience
- Reasoning support for compatible models
- Dynamic AI model configuration without hardcoded values

**In Development:**
- File uploads and text extraction (Tika/OCR)
- Vector storage and RAG search
- Message queues for async processing
- Media generation (images, videos, audio)
- Web search integration
- Office document generation

## Stack

- **Framework:** Symfony 7 + FrankenPHP
- **Database:** MariaDB with vector support
- **Cache:** Redis
- **AI:** Ollama (local), OpenAI, Anthropic
- **Docs:** Apache Tika for file processing

## Configuration

### Environment Files
- `.env` - Empty file with hint (committed, required by Symfony)
- `.env.local` - Your local configuration (not committed, copy from `.env.example`)
- `.env.example` - Template with all available settings
- `.env.test` - Test environment configuration (committed, used by CI/CD)

### Development vs Test
- **Development**: Uses `docker-compose.yml` with real services and Ports 8000, 3307, 6380
- **Testing**: Uses `docker-compose.test.yml` with isolated services and Ports 8001, 3308, 6381

Both can run in parallel without conflicts.

## Testing

See [README_TESTING.md](README_TESTING.md) for detailed testing documentation.

### Quick Test Commands
```bash
# Start test infrastructure
make test-up

# Run all tests
make test

# Run unit tests only
make test-unit

# Stop test infrastructure
make test-down
```

## Architecture

See `refactor_plan/` for detailed architecture documentation and migration strategy.

## Frontend

Vue 3 UI available at: https://github.com/metadist/synaplan/tree/feat/ui-greenfield
