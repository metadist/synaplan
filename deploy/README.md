# Synaplan production deployment

This directory is the portable, single-node production contract. It uses published
images only and does not include Vite, MailHog, phpMyAdmin, source bind mounts, or
other development services.

## Requirements

- Docker Engine with Docker Compose v2
- Cloud-AI profile: at least 4 vCPU, 8 GB RAM, and 30 GB free disk
- Optional `local-ai` profile: at least 16 GB RAM and substantially more disk
- A reverse proxy terminating HTTPS in front of `127.0.0.1:8000`

All database, cache, vector, upload, model, and backup data lives below
`deploy/data/`. Back up that directory only through the lifecycle hooks so the
database and vector snapshots are consistent.

## First installation

```bash
cp deploy/selfhost.env.example deploy/.env
# Replace every required placeholder, including SYNAPLAN_VERSION.
deploy/scripts/prepare.sh
docker compose --env-file deploy/.env -f deploy/compose.yaml pull
deploy/scripts/validate-release.sh
docker compose --env-file deploy/.env -f deploy/compose.yaml up -d
deploy/scripts/smoke-test.sh
```

`deploy/.env` is intentionally ignored. Keep a secure external copy of it.
`APP_SECRET`, `TOKEN_SECRET`, both MariaDB passwords, and all `REALTIME_*`
secrets must remain stable across every redeploy and restore. Keep a configured
`BOOTSTRAP_ADMIN_PASSWORD` unchanged as well: a later start never rotates the
administrator account, so a regenerated value would no longer match the password
that account actually has.

Docker Compose reads `deploy/.env` with its own rules and would change a value
containing `$`, surrounding quotes, ` #`, or leading and trailing spaces.
Generate secrets with `openssl rand -hex 32`, or restrict a value you type
yourself to letters, digits, `-` and `_`. `MAILER_DSN` is the one value you
cannot simply regenerate, because it carries your SMTP provider's password:
percent-encode the special characters inside it (`$` as `%24`) as described in
[Outgoing Email](../docs/EMAIL.md#outgoing-email-smtp). A value that the file
would alter fails the deployment with a message naming the variable instead of
being stored in altered form — for the administrator password that would mean
being locked out of your own instance with no way back. Values that a platform
passes in as environment variables are used exactly as given.

The bootstrap administrator is created only when no administrator exists. A
restart must not rotate this account. `BOOTSTRAP_ADMIN_EMAIL` and
`BOOTSTRAP_ADMIN_PASSWORD` must be set together or left empty together; leaving
both empty is valid and simply skips the bootstrap, so an administrator can be
promoted later. The email must be a valid address of at most 128 characters. The
password must be 8 to 64 characters, and below 16 characters it must also contain
an uppercase letter, a lowercase letter, and a number; from 16 characters there is
no character requirement. `validate-release.sh` checks both values before the
stack starts, so an unusable value fails the deployment once with a fixable
message instead of crash-looping it. Should only one of the two still reach the
containers, every application container exits with code `78` before it touches
the database, and the restart policy retries until the value is corrected. Full
explanation:
[Create the First Administrator](../docs/INSTALLATION.md#create-the-first-administrator).
Configure a cloud AI key after login under Admin > AI Providers, or provide a
bootstrap provider key in `deploy/.env`.

The checked-in version value is deliberately non-deployable. Replace it only
after an immutable SemVer image has been published that contains the
`SYNAPLAN_ROLE=web|worker|scheduler` and `/usr/local/bin/container-healthcheck`
contracts. `prepare.sh` rejects placeholders and mutable tags;
`validate-release.sh` checks the pulled image itself. Catalog submissions must
pin an actually published compatible version, never `latest` or an anticipated
future tag.

## Local AI profile

Cloud AI is the default. To add Ollama, the embedding model, and Whisper:

```dotenv
COMPOSE_PROFILES=local-ai
```

Redeploy after changing the value. This enables Ollama and the Whisper model
download and points the app roles at Ollama. The large local chat model remains
off unless `ENABLE_LOCAL_GPT_OSS=true`. To return to Cloud AI, empty
`COMPOSE_PROFILES`, set the desired cloud provider in the UI, and redeploy. Model
files remain in `deploy/data/` until deliberately removed.

## Network and persistence

Only the web service binds a host port. MariaDB, Redis, Centrifugo, Tika, Qdrant,
Ollama, and Whisper remain on the Compose network. The default bind is
`127.0.0.1:8000`; managed platforms may set `SYNAPLAN_HTTP_BIND=0.0.0.0` while
restricting exposure with their firewall and HTTPS proxy.

Persistent paths:

- `data/mariadb`: relational data
- `data/redis`: durable Redis append-only file
- `data/qdrant`: vector collections
- `data/uploads`: uploaded and generated files shared by web and worker
- `data/ollama`, `data/whisper`: optional local models
- `data/backups`: restricted MariaDB dumps and Qdrant collection snapshots

`deploy/data/` must never be committed.

### Docker Desktop validation

The production bind mounts target Linux servers. MariaDB cannot safely use a
macOS bind mount because the host filesystem's case behavior differs from the
Linux database contract. For local Docker Desktop validation, add the supplied
named-volume override:

```bash
docker compose --env-file deploy/.env \
  -f deploy/compose.yaml \
  -f deploy/compose.docker-desktop.yaml up -d
```

Pass the same override to lifecycle scripts with an absolute path:

```bash
SYNAPLAN_COMPOSE_OVERRIDE="$PWD/deploy/compose.docker-desktop.yaml" \
  deploy/scripts/smoke-test.sh
```

The override is for local validation only. Elestio and Linux self-hosting use
the bind-mounted production paths from `deploy/compose.yaml`.

## Backup and restore

The scripts are non-interactive and safe to invoke more than once:

```bash
deploy/scripts/pre-backup.sh
# Capture deploy/data with the platform backup system.
deploy/scripts/post-backup.sh
```

`pre-backup.sh` first pauses web writers, the worker, and scheduler; while the
data stores remain online it creates a single-transaction MariaDB dump, one
snapshot per Qdrant collection, and a checksummed upload archive. It then stops
every stateful service that was running, including optional Ollama, before the
platform captures `deploy/data`. If preparation fails, it removes the
incomplete backup and resumes exactly the services it stopped.
`post-backup.sh` resumes that recorded service set and keeps seven completed
portable backups by default (`BACKUP_RETENTION_COUNT` changes this).

For restore:

```bash
deploy/scripts/pre-restore.sh
# Restore deploy/data with the platform backup system.
deploy/scripts/post-restore.sh
```

By default, the post hook uses the MariaDB and Qdrant data directories restored
by the platform, repairs upload permissions, lets the web role apply migrations,
starts background roles, and runs the smoke test. Set
`RESTORE_PORTABLE_BACKUP=true` only when restoring the generated SQL dump and
Qdrant snapshots and the uploaded-file archive into fresh, empty storage. The
archive checksum, paths, link types, and restored file checksums are verified
before it replaces `data/uploads`. Do not replay portable artifacts on top of
data directories that the platform already restored. Test restores in a
separate stack before relying on them.

For the portable import path, invoke the post hook explicitly:

```bash
RESTORE_PORTABLE_BACKUP=true deploy/scripts/post-restore.sh
```

## Update

Step-by-step instructions:
[Update a Self-Hosted Deployment](../docs/UPDATE_SELFHOST.md), or
[Update on Elestio](../docs/UPDATE_ELESTIO.md) for a pipeline on that platform.
Only a tested, concrete `SYNAPLAN_VERSION` may be deployed.

The pre hook enforces a successful backup and pulls the pin. The post hook waits
for every role and dependency, then verifies health and reports the running image
reference and image ID. Roll back by restoring the previous version pin and the
matching pre-update backup.

## Platform adapters

Platform-specific adapters must call the scripts in this directory rather than
reimplementing operations. `deploy/elestio/` is the first thin adapter. Future
Coolify, CapRover, or Railway definitions should preserve the same role,
persistence, backup, restore, and health contracts.
