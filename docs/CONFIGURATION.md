# Configuration Guide

All configuration is done via environment variables in `backend/.env`.

![Settings Page](images/settings.png)

## Quick Reference

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_ENV` | `dev` | Environment: `dev`, `prod`, `test` |
| `APP_URL` | `http://localhost:8000` | Public URL for widgets/embeds/OAuth |
| `FRONTEND_URL` | `http://localhost:5173` | Frontend URL for email links |

---

## AI Providers

Synaplan supports multiple AI providers. Configure one or more:

### Groq (Recommended - Free Tier)

```bash
GROQ_API_KEY=gsk_your_key_here
```

Get a free key at [console.groq.com](https://console.groq.com)

### OpenAI

```bash
OPENAI_API_KEY=sk-your_key_here
```

### Anthropic (Claude)

```bash
ANTHROPIC_API_KEY=sk-ant-your_key_here
```

### Google Gemini

```bash
GOOGLE_GEMINI_API_KEY=your_key_here
```

### Local Ollama

No API key needed. Models are pulled automatically.

```bash
# Disable auto-download if needed
AUTO_DOWNLOAD_MODELS=false
```

---

## Database

```bash
DATABASE_WRITE_URL=mysql://user:password@db:3306/synaplan
DATABASE_READ_URL=mysql://user:password@db:3306/synaplan
```

Default Docker setup uses these internally. Only change for external databases.

---

## Audio Transcription (Whisper)

```bash
WHISPER_ENABLED=true
WHISPER_DEFAULT_MODEL=base          # tiny|base|small|medium|large
WHISPER_BINARY=/usr/local/bin/whisper
WHISPER_MODELS_PATH=/var/www/backend/var/whisper
FFMPEG_BINARY=/usr/bin/ffmpeg
```

Supported formats: mp3, wav, ogg, m4a, opus, flac, webm, aac, wma

---

## WhatsApp Integration

```bash
WHATSAPP_ENABLED=true
WHATSAPP_ACCESS_TOKEN=your_meta_access_token
WHATSAPP_WEBHOOK_VERIFY_TOKEN=your_verify_token
```

See [WhatsApp Integration Guide](WHATSAPP.md) for setup details.

---

## Email Channel

```bash
# SMTP for outgoing emails
MAILER_DSN=smtp://user:pass@smtp.example.com:587
```

See [Email Integration Guide](EMAIL.md) for full setup.

---

## Qdrant Vector Database

Qdrant is included in `docker-compose.yml` and starts automatically with Synaplan.
It powers AI memories (user profiling) and RAG document vector search.

Configure in `backend/.env`:

Qdrant runs as an internal Docker service — no configuration needed beyond the default `QDRANT_URL=http://qdrant:6333` in `.env`.

**This is optional** — Synaplan works fully without it (memories and vector search will be disabled).

---

## Production Settings

For production deployments:

```bash
APP_ENV=prod
APP_SECRET=generate_a_random_32_char_string

# Public URLs (replace with your domain)
APP_URL=https://your-domain.com
FRONTEND_URL=https://your-domain.com

# Security
CORS_ALLOW_ORIGIN=https://your-domain.com
```

---

## All Environment Variables

See `backend/.env.example` for the complete list with descriptions.

---

## Next Steps

- [Installation Guide](INSTALLATION.md) - Getting started
- [Features Overview](FEATURES.md) - What Synaplan can do
- [WhatsApp Setup](WHATSAPP.md) - Meta Business API integration
