# Installation Guide

Complete installation instructions for Synaplan.

## Choose the Right Deployment Path

Synaplan has separate development and production deployment contracts:

- **Local development:** use the root `docker-compose.yml` or
  `docker-compose-minimal.yml`. These files build from source and include
  development tooling. They are not the supported production contract.
- **Production self-hosting:** use `deploy/compose.yaml` with a pinned Synaplan
  image version and secrets supplied through `deploy/.env`.
- **Elestio evaluation:** import the repository as a custom Docker Compose
  CI/CD pipeline. The root `elestio.yml` delegates to the same production
  contract under `deploy/`.

The Elestio files make a custom import possible; they do **not** mean that
Synaplan has been accepted into Elestio's Fully Managed Catalog.

## Production Self-Hosting

### Requirements

- Docker Engine and Docker Compose v2
- A Linux server with at least 4 vCPU, 8 GB RAM, and sufficient persistent disk
- A DNS name and HTTPS-capable reverse proxy or managed platform
- A released Synaplan version to pin instead of `latest`

The production stack exposes only the web service. MariaDB, Redis, Centrifugo,
Tika, and Qdrant remain on its internal network.

### Start the Cloud-AI Stack

Copy the production environment template, then set the required URL, version,
password, and secret values:

```bash
cp deploy/selfhost.env.example deploy/.env
# Edit deploy/.env and replace every required placeholder. The image version
# must already be published and immutable.
deploy/scripts/prepare.sh
docker compose --env-file deploy/.env -f deploy/compose.yaml pull
deploy/scripts/validate-release.sh
docker compose --env-file deploy/.env -f deploy/compose.yaml up -d
```

Keep `deploy/.env` outside version control, restrict its permissions,
and preserve the same secret values across updates and redeployments.

Cloud AI is the production default. Ollama and Whisper are not started, model
downloads are disabled, and an AI provider key can be added after login under
**Admin → AI Providers**.

### Create the First Administrator

There are three ways to get the first administrator, and you only need one of
them.

**In the browser.** Leave `BOOTSTRAP_ADMIN_EMAIL` and `BOOTSTRAP_ADMIN_PASSWORD`
empty and open the instance in a browser after the first start. A production
installation without an administrator serves a short setup wizard at `/setup`:
create the administrator, optionally paste an AI provider key, and decide whether
strangers may sign up or chat as guests. Everything else is closed while that is
pending — the API answers `503 SETUP_REQUIRED` for every other route — so there
is no half-configured state to stumble into.

> **The window between the first start and the first administrator is open.** The
> account belongs to whoever fills in that form first, exactly as in Open WebUI,
> Immich or n8n. On a publicly reachable host, either complete the wizard right
> after the deployment, or use the bootstrap variables below so the window never
> exists.

Set `SETUP_WIZARD_ENABLED=false` to switch the wizard off entirely. An instance
without an administrator then has no way in through the browser, which is what
you want when the account is only ever created by automation or by an identity
provider.

**Through an identity provider.** For an OIDC/SSO deployment there is no local
administrator to create at all: accounts appear on first sign-in, and an
administrator is whoever carries a matching role claim. Switch the wizard off,
point the instance at the provider, and leave the bootstrap variables empty —
the empty database is then a normal steady state, not a pending setup. See
[SSO-only instances](CONFIGURATION.md#sso-only-instances-no-local-accounts) for
the full variable set and how the admin role mapping behaves.

**Through the deployment environment.** Preferable for automated and
platform-managed installs, because no browser step is involved. Before the first
start, set a unique email and strong password:

```dotenv
BOOTSTRAP_ADMIN_EMAIL=admin@example.com
BOOTSTRAP_ADMIN_PASSWORD=REPLACE-ME
```

`REPLACE-ME` is rejected by the checks below on purpose, so a copied example can
never become a working administrator account.

The email must be a valid address of at most 128 characters. Very long or unusual
addresses can still be refused: the part before the `@` may not be longer than 64
characters or contain a dot at the start, at the end, or twice in a row, and each
part of the domain name may not be longer than 63 characters or start or end with
a hyphen. If your own address is refused, put a different valid address in
`BOOTSTRAP_ADMIN_EMAIL`, or leave both bootstrap variables empty and use the
setup wizard instead.

The password must be 8 to 64 characters long; below 16 characters it must also
contain at least one uppercase letter, one lowercase letter, and one number. A
password of 16 characters or more has no character requirements.

Following [NIST SP 800-63B](https://pages.nist.gov/800-63-3/sp800-63b.html),
composition rules apply only to short passwords, because length already provides
the entropy they are meant to enforce. This also means a long generated password
from a managed platform is accepted as-is: the application never shortens or
rewrites it.

> **The `deploy/.env` file itself can change a password before the application
> ever sees it.** Docker Compose reads that file with its own rules: it cuts a
> value off at the first `$`, removes one matching pair of surrounding quotes,
> drops everything after a space followed by `#`, and trims leading and trailing
> spaces. A password containing those characters is therefore stored in a changed
> form, and the password you typed no longer opens the account. Use only letters,
> digits, `-` and `_`, or generate the value like the other secrets with
> `openssl rand -hex 32` — 64 characters, the longest length allowed above, and
> safe in this file. A deployment that finds such a value in `deploy/.env` stops
> with a message naming the variable, instead of creating an administrator whose
> password nobody knows. Values that a hosting platform passes in directly as
> environment variables are used exactly as given and are not affected. For a
> value you cannot choose freely — your SMTP provider's password inside
> `MAILER_DSN` — percent-encode the special characters instead, as described in
> [Outgoing Email](EMAIL.md#outgoing-email-smtp).

**Set both variables together, or leave both empty.** Leaving both empty is a
valid choice: no administrator is created automatically, and the setup wizard
described above takes over. Setting only one of the two is a configuration error.

> **A half-configured pair stops the container on purpose.** Each application
> container checks the pair first and, when only one value is set, prints which
> variable is missing and exits with code `78` before it touches the database.
> Because the production stack restarts containers automatically, the deployment
> keeps restarting until you either supply the missing value or clear both. This
> is intended fail-fast behavior, not a bug — read the container log to see
> which variable is missing.

#### Passwords the deployment generated

An image that invents a per-instance password and hands it over through a
parameter store or a boot log has to treat that password as one-time use. Set

```dotenv
BOOTSTRAP_ADMIN_FORCE_PASSWORD_CHANGE=true
```

and the bootstrap account is created in a state where signing in is all it can
do: the UI opens the password form, and every other request is answered with
`403` — the JSON API, the MCP endpoint and the OpenAI-compatible gateway alike,
so an API key is no way around it. The default is `false`, which is right
whenever a person chose the password themselves.

The production bootstrap creates or promotes this account only when no
administrator exists. If an administrator is already present, it changes nothing,
so it is safe on every start. Later restarts do not reset an existing
administrator's password or role.

On the deployment path shown above, `deploy/scripts/validate-release.sh` checks
both values **before the stack starts**. An unusable value fails that command
once, with a message naming the problem, and nothing is deployed. Only a value
that reaches the containers anyway — because it was changed after the check, or
because the check was skipped — is rejected by the application itself, within
seconds of container start and before it waits for the database, applies
migrations, or seeds anything; that container then exits with code `1`.

In short: exit code `78` means the pair is half-configured, exit code `1` means
the email or the password does not meet the rules above.

Remove both bootstrap variables from platform-visible configuration after the
first login when the platform permits it — always both, never only one — and
store the credentials in a password manager. Changing the variables later does
not change the password of the administrator that already exists; if you no
longer have those credentials, see
[Lost Administrator Password](ADMIN.md#lost-administrator-password).

### Optional Local AI

The optional `local-ai` Compose profile adds Ollama and Whisper. It requires an
explicit redeploy, at least 16 GB RAM, and substantially more disk than the
Cloud-AI stack:

```bash
COMPOSE_PROFILES=local-ai \
  docker compose --env-file deploy/.env -f deploy/compose.yaml up -d
```

Enabling the profile installs the local AI services; a local chat model remains
an additional opt-in. Leave `COMPOSE_PROFILES` empty to keep the Cloud-AI
default.

### Evaluate on Elestio

Use Elestio's custom Docker Compose import for evaluation:

1. Start the free three-day trial, note its exact expiry time, disable
   Auto-Refill immediately, and confirm the current trial terms. Elestio
   currently requires a payment card.
2. Create an isolated target with at least 4 vCPU and 8 GB RAM.
3. Import this repository as a custom CI/CD pipeline. Keep
   `COMPOSE_PROFILES` empty for the initial Cloud-AI deployment and use the
   generated HTTPS domain.
4. Sign in with the generated first-administrator credentials, configure a
   cloud AI provider, and test chat, file/RAG search, widgets, realtime
   connections, restart, and redeploy.
5. Trigger a backup and restore it into a separate pipeline or clone. Confirm
   database records, uploads, and Qdrant-backed search results.
6. Before the trial expires, delete every pipeline, target, service, and backup,
   choose immediate backup deletion, verify Auto-Refill is still disabled, and
   confirm that no billable resource remains.

Test `local-ai` only when the selected machine and remaining trial credit are
sufficient. Do not publish credentials, deployment logs containing secrets, or
private endpoint details.

See [Administration Guide](ADMIN.md) for backups, restore, and cleanup, and
[Update a Self-Hosted Deployment](UPDATE_SELFHOST.md),
[Update on Elestio](UPDATE_ELESTIO.md), or
[Update on AWS (Marketplace AMI)](UPDATE_AWS.md) for version upgrades.

---

## Local Development

The remaining instructions are for a source checkout used for development and
local evaluation. Do not use this root Compose stack as the production
deployment contract.

### Prerequisites

- **Docker** & **Docker Compose** (v2.0+)
- **Git**
- 8GB RAM minimum (16GB recommended for local AI)
- ~9GB disk space (Standard) or ~5GB (Minimal). Add ~14GB if you enable the local
  chat model (`ENABLE_LOCAL_GPT_OSS=true`, see
  [Standard Development Stack](#standard-development-stack))

> **Apple Silicon (M1–M4) Macs — build the backend image, don't pull it.** The
> Quick Start below already does: `docker compose up -d` builds the backend and
> worker locally from a multi-arch base, so PHP runs natively on `arm64` with no
> emulation tax. That is the fastest setup and needs no extra steps. Released
> production images support both `linux/amd64` and `linux/arm64`. See
> [Troubleshooting](#slow-performance-on-apple-silicon-mac).

### Quick Start

```bash
git clone <repository-url>
cd synaplan
docker compose up -d
```

That's it! Visit http://localhost:5173 after ~2 minutes, log in as
`admin@synaplan.com` / `admin123`, and connect a provider under
**Admin → AI Providers** (see [Connect an AI Provider](#connect-an-ai-provider)).

---

## Local Development Modes

### Standard Development Stack

Full-featured installation with local AI models and audio transcription.

| Component | Size | Description |
|-----------|------|-------------|
| Base services | ~5 GB | Backend, frontend, worker, database, Redis, Centrifugo, Tika, Qdrant |
| Ollama embedding model | ~4 GB | `bge-m3` for RAG / semantic search |
| **Total** | **~9 GB** | Everything except the local chat model |

```bash
docker compose up -d
```

**Local chat model is opt-in.** The standard install downloads the embedding model
only. A local chat model (`gpt-oss:20b`, another ~14 GB, needs a GPU or a strong
CPU box) is pulled only when you ask for it:

```bash
ENABLE_LOCAL_GPT_OSS=true docker compose up -d
```

Until then — or while the download runs — chat needs a cloud provider key
(see [Connect an AI Provider](#connect-an-ai-provider)).

**What's included:**
- Full web app and REST API
- Document processing (Apache Tika)
- MariaDB database with VECTOR support
- Redis (cache, sessions, locks, message queues, realtime engine)
- Centrifugo WebSocket gateway (live chat takeover, realtime events)
- Background worker (Symfony Messenger consumer for async AI/indexing jobs)
- Local Ollama server (embedding model downloaded automatically, chat model opt-in)
- Whisper audio transcription
- Cloud AI support (Groq, OpenAI, Anthropic, Gemini, xAI, …)
- Qdrant vector database (AI memories, RAG, feedback)
- Dev tools (phpMyAdmin, MailHog)

### Minimal Development Stack (Cloud AI Only)

Fastest way to start—uses cloud AI providers, skips large local models.

| Component | Size | Description |
|-----------|------|-------------|
| Base services | ~5 GB | Backend, frontend, worker, database, Redis, Centrifugo, Tika |
| **Total** | **~5 GB** | No local AI models (`AUTO_DOWNLOAD_MODELS=false`) |

```bash
# Start minimal stack
docker compose -f docker-compose-minimal.yml up -d
```

**Excluded (saves ~4 GB):**
- Ollama (local AI models)
- Whisper models (audio transcription)
- Local embedding models

**Upgrade to Standard later:**
```bash
docker compose -f docker-compose-minimal.yml down
docker compose up -d
```

---

## Connect an AI Provider

After `docker compose up -d`, log in as `admin@synaplan.com` / `admin123` and open
**Admin → AI Providers** (`http://localhost:5173/admin/setup`).

- Paste a cloud key (free tier: [console.groq.com](https://console.groq.com)) — it is
  tested live, stored encrypted in the database, and works immediately (no restart).
- Or run chat on local Ollama — start the stack with `ENABLE_LOCAL_GPT_OSS=true` and
  wait for the model download to finish (progress is shown in the app and in
  `docker compose logs -f backend`).
- Optional: put keys in `backend/.env` for scripted deploys. The backend reads `.env`
  at container start and imports the key into the encrypted store on first use, so
  restart the container after editing the file.

```bash
# Optional env bootstrap (UI is preferred)
echo "GROQ_API_KEY=your_key_here" >> backend/.env
docker compose restart backend worker
```

> An optional interactive helper lives at `_devextras/_1st_install_linux.sh`. It
> asks the same questions and then does exactly what this guide describes; the
> manual path above stays the reference.

---

## What Happens Automatically

On first start, the system:

1. Creates `backend/.env` from template
2. Installs dependencies (Composer, npm)
3. Generates JWT keypair for authentication
4. Creates database schema
5. Loads test fixtures (if database is empty)
6. Downloads the Ollama embedding model in the background — standard install only,
   and only while `AUTO_DOWNLOAD_MODELS=true` (the Minimal stack sets it to `false`)
7. Points chat at a provider that has a usable key, if the default one has none
8. Starts all services

**First startup:** ~1-2 minutes  
**Subsequent restarts:** ~15-30 seconds

## Development Login Credentials

After the local development stack starts for the first time, the following test
accounts are available. They are development fixtures and are never the
production first-administrator mechanism:

| Email | Password | Level |
|-------|----------|-------|
| admin@synaplan.com | admin123 | ADMIN |
| demo@synaplan.com | demo123 | PRO |
| test@example.com | test123 | NEW (unverified) |

Log in at http://localhost:5173 with any of these accounts. The admin account has full access to all settings.

---

## Line Endings (Windows Users)

This project enforces LF (Unix-style) line endings. If you cloned before `.gitattributes` was added:

```bash
git rm --cached -r .
git reset --hard
```

---

## Troubleshooting

### Services won't start
```bash
# Check logs
docker compose logs -f

# Restart everything
docker compose down
docker compose up -d
```

### Database connection issues
```bash
# Wait for MariaDB to be ready
docker compose logs db

# Reset database completely
docker compose down -v
docker compose up -d
```

### Model download stuck
```bash
# Check Ollama logs
docker compose logs ollama

# Manually pull model
docker compose exec ollama ollama pull bge-m3
```

### Slow performance on Apple Silicon (Mac)

On Apple Silicon (M1–M4) the fastest setup is to **build the backend image
locally**, which is what both compose files do by default (`pull_policy: build`
on the `backend` and `worker` services). The base image is published for
`linux/amd64` *and* `linux/arm64`, so a local build produces a native `arm64`
backend — no emulation.

If PHP still feels slow, check what you are actually running:

```bash
docker compose exec backend uname -m   # expect: aarch64
```

- `x86_64` means the local development container is running under emulation.
  Rebuild with `docker compose build --pull backend worker`, then run
  `docker compose up -d`.
- The optional `phpmyadmin` and `mailhog` services use amd64-only upstream images
  and stay emulated. Enable **Docker Desktop → Settings → General → "Use Rosetta
  for x86/amd64 emulation on Apple Silicon"** (macOS 13+) — much faster than the
  default QEMU — or drop those two services if you don't need them. The
  `requested image's platform (linux/amd64) does not match ...` warning refers to
  them and is safe to ignore.
- The first `docker compose up -d` is slower because the image is built; every
  later start is a cache hit.

### Port conflicts
Default host ports: 5173 (frontend), 8000 (backend), 3307 (database), 8082 (phpMyAdmin), 8025/1025 (MailHog), 6333 (Qdrant), 11435 (Ollama), 9999 (Tika)

Redis, Centrifugo, and the worker are internal-only services — they expose no host ports and cannot conflict.

Edit `docker-compose.yml` to change ports if needed.

### Worker / queue issues
Async jobs (AI processing, document indexing) run in the `worker` container:

```bash
# Worker logs — should show "messenger:consume" processing messages
docker compose logs -f worker

# Realtime gateway logs
docker compose logs -f centrifugo

# Redis connectivity
docker compose exec redis redis-cli ping   # expects PONG
```

---

## Next Steps

- [Configuration Guide](CONFIGURATION.md) - API keys, environment variables
- [Features Overview](FEATURES.md) - What Synaplan can do
- [Development Guide](DEVELOPMENT.md) - Contributing and testing
