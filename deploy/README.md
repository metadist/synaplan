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

### Generated secrets: `deploy/data/secrets.env`

Those eight secrets do not have to be configured at all. Every lifecycle script
calls `ensure_deployment_secrets`, which resolves each of them once:

- A value you configured — in `deploy/.env` or as an environment variable — is
  adopted unchanged, so an installation that already runs keeps exactly the
  credentials it has.
- A secret with no value is generated with 32 bytes of randomness, independently
  of every other secret, but only while the stack has never been initialised.
- The resolved set is written to `deploy/data/secrets.env` with mode `0600`. From
  then on that file is authoritative and is never rewritten, so a value once in
  use stays in use, and exported before Compose runs, because Compose prefers a
  real environment variable over any `--env-file` entry.
- If a secret has no value and `deploy/data/mariadb` already holds a database,
  the deployment stops and names the variable. Generating a new one is not an
  option: the MariaDB user still authenticates with the old password, so a new
  `MARIADB_PASSWORD` would lock the application out of its own data permanently.
  Restore the variable in your configuration, or write the known value into
  `deploy/data/secrets.env`.

Because the file is authoritative and is exported before Compose runs, editing
one of these eight variables in `deploy/.env` no longer changes the running
deployment. To rotate one deliberately, change it in
`deploy/data/secrets.env` — and only together with the state it belongs to:
`MARIADB_PASSWORD` and `MARIADB_ROOT_PASSWORD` must be changed in MariaDB itself
in the same step, and a new `APP_SECRET` makes the provider API keys stored in the
database undecryptable (see
[AI Providers](../docs/CONFIGURATION.md#ai-providers)).

**`deploy/data/secrets.env` is part of this deployment's secret material and MUST
be included in every backup and disaster-recovery record.** A database restored
without it is a database nobody can open again — the password exists nowhere
else. Keep it private, never commit it (`deploy/data/` is gitignored), and change
a value in it only in the deliberate way described above.

This is also why a managed platform's generated values are not used directly:
Elestio's `random_password` placeholder is a single draw per deployment,
substituted into every variable that names it, so the database password, the
secret that signs every application token and the Centrifugo admin password were
all the same string. See [`elestio/README.md`](elestio/README.md).

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
both empty is valid and skips the bootstrap, in which case the stack serves the
first-run setup wizard at `/setup` and the first visitor creates the
administrator there. On a publicly reachable host, either finish that wizard
right after deploying or set the bootstrap pair, so the claim window never
exists; `SETUP_WIZARD_ENABLED=false` closes the browser route entirely. See
[First-Run Setup](../docs/CONFIGURATION.md#first-run-setup), and
[SSO-only instances](../docs/CONFIGURATION.md#sso-only-instances-no-local-accounts)
for a deployment whose administrator comes from an identity provider instead.

The email must be a valid address of at most 128 characters. The
password must be 8 to 64 characters, and below 16 characters it must also contain
an uppercase letter, a lowercase letter, and a number; from 16 characters there is
no character requirement. `validate-release.sh` checks both values before the
stack starts, so an unusable value fails the deployment once with a fixable
message instead of crash-looping it. Should only one of the two still reach the
containers, every application container exits with code `78` before it touches
the database, and the restart policy retries until the value is corrected. Full
explanation:
[Create the First Administrator](../docs/INSTALLATION.md#create-the-first-administrator).

Set `BOOTSTRAP_ADMIN_FORCE_PASSWORD_CHANGE=true` when the password was generated
by the deployment rather than typed by a person — a marketplace image that
writes a per-instance password to a parameter store, for example. That
credential then works exactly once: the account can sign in and do nothing but
set its own password, enforced server-side, until it does. Leave it at `false`
when you chose the password yourself.
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
`127.0.0.1:8000`. A managed platform whose HTTPS proxy runs in its own container
cannot reach that address and needs `SYNAPLAN_HTTP_BIND` set to the host
interface the proxy connects to — the Docker bridge gateway, usually
`172.17.0.1`, which is what `elestio.yml` uses. Do not use `0.0.0.0`: Docker
publishes ports through its own iptables rules, which a host firewall such as
ufw does not close, so the app would be reachable as plain HTTP from the
internet and the HTTPS proxy could be bypassed.

Persistent paths:

- `data/mariadb`: relational data
- `data/redis`: durable Redis append-only file
- `data/qdrant`: vector collections
- `data/uploads`: uploaded and generated files shared by web and worker
- `data/ollama`, `data/whisper`: optional local models
- `data/backups`: restricted MariaDB dumps and Qdrant collection snapshots
- `data/secrets.env`: the deployment's generated secrets, mode `0600` — required
  to open the restored database, so it must be in every backup

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

Whatever captures `deploy/data` must include `deploy/data/secrets.env`. It holds
the database, realtime and application secrets, and without it the restored
database cannot be authenticated against.

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

`deploy/aws/` is the AWS Marketplace AMI adapter and follows the same rule one
layer further out: there is no managed platform on an EC2 instance, so systemd
and the `synaplan-update` / `synaplan-snapshot` commands call these scripts
directly. Its additions are the parts only AWS has — a Packer build, a first boot
that configures itself from instance metadata, Caddy as a host TLS terminator,
and CloudFormation templates.
[`scripts/tests/test-lifecycle.sh`](scripts/tests/test-lifecycle.sh) enforces the
contract for both adapters. Details in [`aws/README.md`](aws/README.md).

`deploy/umbrel/` is the Umbrel App Store package and the one adapter that cannot
call these scripts: umbrelOS installs an app from a self-contained directory and
offers no lifecycle hook to run them from. It reproduces the role, persistence
and health contracts in its own `docker-compose.yml`, and keeps its secrets in
the app's data directory for the same reason this deployment does — a database
restored without its password cannot be opened. Its deviations, including the
absent local-AI services and the missing consistent-backup hook, are listed in
[`umbrel/README.md`](umbrel/README.md).

The release pin for that package (store `version`, `APP_VERSION`, and the
`tag@sha256:…` image) is raised automatically together with `elestio.yml` and
`deploy/selfhost.env.example` by `scripts/set-release-version.mjs` after every
published release. Submitting the raised package to the Umbrel App Store remains
a separate, manual pull request against `getumbrel/umbrel-apps`.
