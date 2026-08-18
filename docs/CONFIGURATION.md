# Configuration Guide

Synaplan reads configuration from three places, and it matters which one you reach for:

| Where | What belongs there | Takes effect |
|-------|--------------------|--------------|
| `backend/.env` | Infrastructure and boot settings: database, Redis, public URLs, `APP_SECRET`, channel credentials | When the container starts |
| **Admin → AI Providers** (`/admin/setup`) | AI provider API keys — validated live, stored encrypted in the database | Immediately, no restart |
| **Admin → System Configuration** (`/admin/config`), backed by `BCONFIG` rows | Behavior switches: multi-task routing, async media jobs, white-label branding | Immediately, no restart |

Provider keys are the common case and belong in the UI — see [AI Providers](#ai-providers) below. Environment variables remain supported for scripted and orchestrated deploys.

![Admin → AI Providers: every provider listed with a connection badge, a free-tier hint and a single field to paste the key into](images/tour/provider-setup.webp)

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

Synaplan supports multiple AI providers. Configure one or more.

**Recommended: the setup UI.** Log in as admin and open **Admin → AI Providers**
(`/admin/setup`). Keys entered there are validated with a live request, stored
**AES-256-CBC encrypted in the database** (`BCONFIG`, group `provider_keys`,
keyed off `APP_SECRET`), and take effect without a restart — web requests use the
new key at once, long-running processes (the messenger worker, FrankenPHP worker
mode) within a few seconds, because each memoizes resolved keys briefly. The
wizard can also apply the recommended default models for a provider in one
click (same as `php bin/console app:provider:apply-defaults <provider>`).

**Env variables still work** and are the right tool for scripted/orchestrated
deploys: a key found in the environment is imported into the encrypted store the
first time the backend resolves it ("transfer on first load"). Rotating the env
value rotates the stored copy; a key saved through the UI permanently wins over
the env var, so you can delete it from `.env` afterwards. Keys never appear in
migrations, seeders, or anything tracked by git.

Two caveats for the env path:

- **The environment is read when the container starts.** Editing `backend/.env`
  on a running stack changes nothing until you restart:
  `docker compose restart backend worker`.
- **Placeholder values are ignored.** Template text such as
  `your-api-key-here` is not imported and the provider stays unconfigured, so an
  untouched `.env.example` never looks like a working setup.

> **Back up `APP_SECRET`.** Stored keys are encrypted with a key derived from it.
> If `APP_SECRET` changes, existing rows can no longer be decrypted: the affected
> providers report as unconfigured and you have to re-enter their keys (or restore
> them from `.env` and restart). Rotate `APP_SECRET` only when you can re-enter
> every provider key afterwards.

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

Also unlocks the Google media models (Imagen 4, Nano Banana, Veo 3.1, Gemini TTS).

### Mistral

```bash
MISTRAL_API_KEY=your_key_here
```

Chat and vision (Mistral Medium 3.5, Large 3) plus the Voxtral audio pair (transcription + TTS).

### xAI (Grok)

```bash
XAI_API_KEY=your_key_here
```

Long-context chat and image understanding (Grok 4.5 with a 500K window), Grok Imagine for image and
video generation, and Grok voice for speech synthesis and transcription. Grok 4.5 always reasons at a
fixed depth, so the Thinking toggle has no effect on it. Grok TTS reads its voice roster live from
xAI (default `eve`; the five core voices eve, ara, rex, sal and leo are also available offline) and
delivers the audio in one piece — xAI streams synthesis over a WebSocket only, which this backend
does not speak. The realtime Speech-to-Speech API is not supported. Get a key at
[console.x.ai](https://console.x.ai/) under **Team → API Keys**.

### TrustedTokens (sovereign, Germany)

```bash
TRUSTEDTOKENS_API_KEY=your_key_here
```

Open-weight models (GLM 5.2, Qwen3.6 35B, GPT OSS 120B) served from GPUs in Munich by TNG
Technology Consulting, under German jurisdiction with zero data retention. Synaplan talks to
its OpenAI-compatible API at `https://api.trustedtokens.eu/v1`. Get a key at
[trustedtokens.eu](https://trustedtokens.eu/) under **Account → API Access**.

### HuggingFace

```bash
HUGGINGFACE_API_KEY=hf_your_key_here
```

### Image & video generation

```bash
THEHIVE_API_KEY=your_key_here          # Flux Schnell, SDXL
HIGGSFIELD_API_KEY=your_key_here       # Soul, Reve, DoP, Kling 2.1
HIGGSFIELD_API_SECRET=your_secret_here # both halves are required
```

### Cloudflare Workers AI (embeddings)

```bash
CLOUDFLARE_ACCOUNT_ID=your_account_id
CLOUDFLARE_API_TOKEN=your_token_here
EMBEDDING_FALLBACK_PROVIDER=cloudflare  # optional auto-failover
```

### Local Ollama

No API key needed. Models are pulled automatically.

```bash
# Disable auto-download if needed
AUTO_DOWNLOAD_MODELS=false
```

### Model prices

Every catalog entry carries the provider's own in/out rate, which drives the cost badges in
the model selector, the Statistics page, and the per-tier budgets. Adding a model, retiring
one, or changing a price is a catalog + migration job — see
[PRICING_MAINTENANCE.md](PRICING_MAINTENANCE.md).

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
| `MULTITASK / EMAIL_SEARCH_ENABLED` | `true` (seeded)² | `email_search` DAG node: live read-only search over the user's IMAP accounts and Microsoft 365 connections |
| `CLASSIFIER / FAST_PATH_ENABLED` | `false` | Skip the AI sorter for trivial chat messages (heuristic) |

¹ Existing installations are grandfathered to `0` by migration so behavior
doesn't change on upgrade — enable it per user or globally when ready.

² Seeded `1` insert-if-missing by `MultitaskConfigSeeder` (runs in `app:seed`
on container start); an operator's explicit `0` row survives every deploy.
The built-in code default stays `false` when no row exists. The capability is
only offered to the planner when the user has a connected mail source.

## Saved Tasks (BCONFIG)

Pin a Task Prompt and run it on demand or on a schedule. Configured via
**BCONFIG**, not environment variables. Admins manage the master switch in
**Settings → Routing → Saved Tasks**. Resolution is per-user row → global row
(`BOWNERID=0`) → built-in code default (`false`). The seeder inserts a global
`1` for new and local-dev installs (insert-if-missing).

| Group / Key | Default | Description |
|-------------|---------|-------------|
| `SAVEDTASKS / ENABLED` | `true` (new / local-dev installs); code default `false` | Master switch: Saved Task APIs, AI Instructions chrome, and the Connections page. Widget chat never runs Saved Tasks. When the flag is off, `app:saved-tasks:tick` is a no-op. |

---

## Microsoft 365 connector (BCONFIG)

Lets a user connect their Microsoft 365 account (currently delegated
`Mail.Read`) from **Channels → Connections**. The app registration is
**operator-owned and install-wide**: rows live under `BOWNERID=0` only, so a
user can never point the consent flow at an app registration the operator does
not control. Admins manage it in **Settings → Inbound Channels → Microsoft 365
(Graph)**; no restart is required.

| Group / Key | Default | Description |
|-------------|---------|-------------|
| `M365 / ENABLED` | `false` | Offer Microsoft 365 as a connection. The connect action stays hidden until client ID **and** secret are also set. |
| `M365 / CLIENT_ID` | — | Application (client) ID of the Azure app registration. |
| `M365 / CLIENT_SECRET` | — | Client secret, **stored AES-encrypted** (`APP_SECRET` derived) and masked in every API response. |
| `M365 / TENANT` | `common` | `common`, `organizations`, or a single tenant GUID. |
| `M365 / REDIRECT_URI` | `APP_URL` + `/api/v1/connections/m365/callback` | Override only when a proxy changes the public URL. Must match Azure exactly. |

**Azure app registration** — register a *Web* platform (not SPA) with the
redirect URI above, add the delegated permissions `offline_access`, `openid`,
`email`, `profile`, `User.Read`, `Mail.Read`, and create a client secret.
`offline_access` is not optional: without it Microsoft issues no refresh token
and every scheduled run stops working after an hour.

The same steps are shown inside the admin UI above the fields, with the
resolved redirect URI and the scope list as copyable values, so nobody has to
read this file to set the connector up.

Tokens are stored per user as one encrypted JSON blob in the credential vault
(`BCREDENTIALS`), never in `BCONNECTIONS`, and are refreshed automatically —
including from cron, with no user session. When Microsoft finally rejects the
grant (consent revoked, refresh token expired), the connection flips to
`reauth_required` instead of failing silently.

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

Qdrant runs as an internal Docker service — no configuration needed beyond the default `QDRANT_URL=http://qdrant:6333` in `backend/.env`.

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

### Authentication cookies over plain HTTP

The `access_token`, `refresh_token`, and OIDC cookies are marked `Secure` when
`APP_URL` uses `https://`. A browser never sends a `Secure` cookie back over
plain HTTP, so a deployment that is reachable only over HTTP — a LAN appliance
such as umbrelOS, which serves every app from `http://<device>.local:<port>` —
would log in successfully and lose the session on the next request. Deriving the
flag from `APP_URL` keeps those deployments usable without weakening anything
that is served over HTTPS.

Set `AUTH_COOKIE_SECURE` only when the detection cannot work: `true` when TLS is
terminated by a proxy that `APP_URL` does not reveal, `false` to serve an
HTTPS-configured deployment over plain HTTP anyway. Leave it empty otherwise.
Only an explicit `http://` or `https://` scheme decides — if `APP_URL` is unset
or carries no scheme, production falls back to `Secure` cookies.

```bash
AUTH_COOKIE_SECURE=
```

---

## All Environment Variables

See `backend/.env.example` for the complete list with descriptions.

---

## Next Steps

- [Installation Guide](INSTALLATION.md) - Getting started
- [Features Overview](FEATURES.md) - What Synaplan can do
- [WhatsApp Setup](WHATSAPP.md) - Meta Business API integration
