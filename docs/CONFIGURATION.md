# Configuration Guide

All configuration is done via environment variables in `backend/.env`.

![Settings Page](images/settings.png)

## Quick Reference

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_ENV` | `dev` | Environment: `dev`, `prod`, `test` |
| `APP_URL` | `http://localhost:8000` | Public URL for widgets/embeds/OAuth |
| `FRONTEND_URL` | `http://localhost:5173` | Frontend URL for email links |
| `REDIS_DSN` | `redis://redis:6379` | **Required.** Cache, sessions, locks, queues, realtime engine ([details](#redis-required)) |
| `REALTIME_ENABLED` | `true` | WebSocket realtime layer (Centrifugo) — see [REALTIME.md](REALTIME.md) |

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

## Redis (required)

```bash
REDIS_DSN=redis://redis:6379
LOCK_DSN=redis://redis:6379
```

Redis is **mandatory infrastructure** (no filesystem fallback): it backs the
Symfony cache pools, locks, rate-limiter, sessions, the Messenger queues
(Redis Streams) and the Centrifugo realtime engine. `/api/health` returns
**HTTP 503 while Redis is unreachable** so load balancers drop the node.

- All compose files ship a `redis` service — it must be running.
- Multi-node production: point every node at the same managed/HA Redis.
- Upgrading an existing install from the old Doctrine queue? Follow the
  cutover runbook in `_devextras/SYSADMIN-help.md`
  ("Upgrading: Doctrine → Redis queue cutover") — queued jobs are **not**
  migrated automatically.

Realtime (Centrifugo) secrets live next to it — `REALTIME_TOKEN_SECRET` and
`REALTIME_API_KEY` **must** be replaced in production: with `APP_ENV=prod`
the backend refuses to mint WebSocket tokens (and skips publishes) while
they still have the shipped `changeme_*` placeholders. See
[REALTIME.md](REALTIME.md).

---

## Multi-Task Routing (BCONFIG)

The multi-task routing pipeline (AI planner + task DAG, see
[DEVELOPMENT.md](DEVELOPMENT.md#message-routing-multi-task)) is configured via
**BCONFIG** database settings, not environment variables. Admins manage the
master switch in the UI (**Settings → Routing**); the rest can be set per
group in the `BCONFIG` table.

| Group / Key | Default | Description |
|-------------|---------|-------------|
| `MULTITASK / ROUTING_ENABLED` | `true` (new installs)¹ | Master switch: plan multi-step requests as a task DAG |
| `MULTITASK / SHADOW_MODE` | `false` | Generate + persist plans for analysis, but answer via the legacy path |
| `MULTITASK / PARALLEL_ENABLED` | `false` | Execute independent media nodes concurrently (subprocess offload) |
| `MULTITASK / MAX_PARALLEL` | `3` | Concurrency cap for parallel media nodes |
| `MULTITASK / NODE_TIMEOUT` | `120` | Per-node subprocess timeout (seconds) |
| `CLASSIFIER / FAST_PATH_ENABLED` | `false` | Skip the AI sorter for trivial chat messages (heuristic) |

¹ Existing installations are grandfathered to `0` by migration so behavior
doesn't change on upgrade — enable it per user or globally when ready.

---

## Async Media Jobs (BCONFIG)

Media generation (image, video, audio) can run as **background jobs** instead of
blocking the chat turn: the assistant shows a live status banner, a completion
toast fires when the render is ready, and a global Jobs tray tracks everything.
Configured via **BCONFIG** (not env vars). Admins manage the master switch in the
UI (**Settings → Processing → Async media generation**); the rest can be set per
group in the `BCONFIG` table. Resolution is per-user row → global row
(`BOWNERID=0`) → built-in code default.

| Group / Key | Default | Description |
|-------------|---------|-------------|
| `MEDIA / ASYNC_JOBS_ENABLED` | `true` (new installs)¹ | Master switch: chat/multitask media (image + video + audio) detaches to a background job vs running inline |
| `MEDIA / JOB_POLL_INTERVAL_SECONDS` | `3` | Delay the advancer waits before re-dispatching itself for the next poll step (clamped 1–30) |
| `MEDIA / JOB_IMAGE_INLINE_FAST_MS` | `1500` | Grace window: a fast image render that finishes within this on the first advance resolves in the same turn (clamped 0–10000) |
| `MEDIA / JOB_HEARTBEAT_STALE_SECONDS` | `90` | Seconds without a heartbeat before the reaper presumes the worker died and times the job out (clamped 30–1800) |
| `MEDIA / JOB_MAX_ACTIVE_PER_USER` | `16` | Max concurrent in-flight media jobs per user (clamped 1–100) |

¹ Existing installations are grandfathered to `0` by migration
(`Version20260629120000`) so behavior doesn't change on upgrade — each user
opts in via **Settings → Processing → Async media generation** (or set the
group/per-user row directly).

> **Requires the `worker` container.** Async jobs are consumed by the dedicated
> `worker` service (Messenger over Redis Streams). Without it, jobs sit in
> `queued` and the chat bubble shows "Job still running" indefinitely. The worker
> **must** run in the same `APP_ENV` as the backend — see
> [DEVELOPMENT.md](DEVELOPMENT.md#the-worker-service-async-jobs).

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
