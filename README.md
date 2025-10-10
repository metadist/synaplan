# Synaplan Backend

Symfony 7 API backend for multi-AI chat platform with streaming, authentication, and database-driven model configuration.

## Quick Start

```bash
docker compose up -d
```

Access: http://localhost:8000

## Features

**Working:**
- User authentication (JWT, email verification)
- Real-time chat with SSE streaming
- Multi-provider AI integration (Ollama, OpenAI, Anthropic)
- Message preprocessing and intelligent routing
- Database-driven prompts and model configuration
- Circuit breaker pattern for resilience
- Reasoning support for compatible models

**In Development:**
- File analysis and RAG
- Media generation (images, videos, audio)
- Rate limiting
- Office document generation

## Stack

- **Framework:** Symfony 7 + FrankenPHP
- **Database:** MariaDB with vector support
- **Cache:** Redis
- **AI:** Ollama (local), OpenAI, Anthropic
- **Docs:** Apache Tika for file processing

## Architecture

See `refactor_plan/` for detailed architecture documentation and migration strategy.

## Frontend

Vue 3 UI available at: https://github.com/metadist/synaplan/tree/feat/ui-greenfield
