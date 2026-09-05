# Administration Guide

Operations reference for self-hosted deployments.

---

## Production Setup

Production operations use `deploy/compose.yaml` and the lifecycle scripts under
`deploy/scripts/`. The root `docker-compose.yml` and
`docker-compose-minimal.yml` build a development environment and must not be
treated as the production deployment contract.

### Environment and Secrets

Generate a separate value for every secret, never the same one twice:

```bash
openssl rand -hex 32
```

Copy `deploy/selfhost.env.example` to `deploy/.env`, restrict access to
it, and set all required production variables:

```bash
SYNAPLAN_VERSION=<released-version>
APP_SECRET=<output from above>
APP_URL=https://your-domain.com
FRONTEND_URL=https://your-domain.com
```

`deploy/compose.yaml` sets `APP_ENV=prod` itself, so the production stack needs
no entry for it.

Set every other password and token marked as required in the template. Secrets
must remain stable across container recreation, updates, backup, and restore.
Never commit the populated environment file.

`APP_SECRET`, `TOKEN_SECRET`, both MariaDB passwords and the four `REALTIME_*`
secrets may also be left unset. The lifecycle scripts then generate an
independent value for each on the first start and record the set in
`deploy/data/secrets.env` (mode `0600`), which is authoritative from then on and
never rewritten. A value you configure yourself is adopted unchanged. Details:
[deploy/README.md](../deploy/README.md#generated-secrets-deploydatasecretsenv).

See [Configuration Guide](CONFIGURATION.md) for all environment variables.

### Starting Services

```bash
deploy/scripts/prepare.sh
docker compose --env-file deploy/.env -f deploy/compose.yaml pull
deploy/scripts/validate-release.sh
docker compose --env-file deploy/.env -f deploy/compose.yaml up -d
```

The production stack starts in Cloud-AI mode by default. Configure a provider
after login under **Admin → AI Providers**.

To add Ollama and Whisper on a host with at least 16 GB RAM and sufficient disk,
enable the optional profile and redeploy:

```bash
COMPOSE_PROFILES=local-ai \
  docker compose --env-file deploy/.env -f deploy/compose.yaml up -d
```

The profile adds local AI services; the local chat model remains a separate
opt-in. Remove the profile and redeploy to return to the Cloud-AI footprint.

### First Administrator

Provide `BOOTSTRAP_ADMIN_EMAIL` and a strong `BOOTSTRAP_ADMIN_PASSWORD` before
the first production start, or leave both empty and promote an administrator
yourself later (see [User Management](#user-management)). Set both variables
together, or leave both empty: `deploy/scripts/validate-release.sh` refuses to
pass an unusable pair before the stack starts, and a container that receives only
one of the two refuses to start and keeps restarting until the configuration is
corrected. The bootstrap runs only while no administrator exists. It does not
overwrite an existing administrator on restart. The password must be 8 to 64
characters long; below 16 characters it must also contain at least one uppercase
letter, one lowercase letter, and one number.

Sign in immediately, verify access, and move the bootstrap credentials to your
password manager. Remove both bootstrap variables from platform-visible
configuration after bootstrap when your deployment platform permits it — always
both, never only one. Never reuse the development fixture credentials in
production.

See [Create the First Administrator](INSTALLATION.md#create-the-first-administrator)
for the password rules and the full bootstrap behavior, and
[Installation Guide](INSTALLATION.md) for full setup instructions.

### Lost Administrator Password

Changing `BOOTSTRAP_ADMIN_PASSWORD` later does not change the password of an
administrator that already exists. A new value — including one that a hosting
platform generates for you on a redeploy and shows in its dashboard — is ignored,
so the account keeps the password from the very first start. Always sign in with
the credentials of that first deployment.

If they are lost, there are three ways back in.

**1. On the server (recommended).** Anyone with shell access on the host can set
a new password directly. This needs no mailer and no SQL:

```bash
docker compose exec -T backend php bin/console app:admin:reset-password \
  admin@example.com --generate
```

The generated password is printed once and has to be replaced at the next
sign-in; that rule is enforced server-side, so an API key is no way around it.
Pass `--password='Str0ngPass'` instead to set a password you chose yourself. It
follows the same rules as `BOOTSTRAP_ADMIN_PASSWORD`: 8 to 64 characters, and
below 16 characters it must also contain at least one uppercase letter, one
lowercase letter, and one number.

If every administrator is gone — deleted, or demoted, so nobody can reach
**Admin → Users** anymore — add `--promote`. It makes the named account an
administrator and marks its address verified in the same step:

```bash
docker compose exec -T backend php bin/console app:admin:reset-password \
  someone@example.com --generate --promote
```

The setup wizard deliberately does *not* reopen in that situation: a wizard that
reappears on a running instance would let the next visitor claim it. Shell access
is the intended proof of ownership instead.

Accounts managed by an enterprise identity provider are refused — their password
lives in that provider, not here.

**2. Password reset by email.** Use *Forgot password?* on the sign-in page. This
only works when the deployment can send mail: `MAILER_DSN` must point at your
SMTP server. The production default is `null://null`, which silently discards
every message, so configure SMTP first (see [EMAIL.md](EMAIL.md)).

**3. Through the database.** Sign up in the app with an address you control, then
make that account the administrator with the SQL in
[User Management](#user-management), which also shows how to open the database
prompt. Without working SMTP the sign-up confirmation mail never arrives, so mark
the account as verified in the same step — otherwise it cannot sign in.

There is no other recovery path: passwords are stored as hashes and cannot be
read back out of the database.

---

## Monitoring

### Health Check Endpoint

`GET /api/health/probe` exercises the auth stack (DB via API-Key lookup, email-verified gate, token generation) and returns `STATUS:OK` or `STATUS:ERROR`.

Protected by standard API-Key authentication (`X-API-Key` header). No sensitive details in the response — diagnostics are logged server-side only.

Quick smoke test:

```bash
curl -i -H "X-API-Key: sk_your-health-monitor-api-key" https://your-domain.com/api/health/probe
```

See [Health Monitoring](HEALTH_MONITORING.md) for full setup: monitor user creation, API-Key generation, Uptime Robot configuration.

### Recent Errors

`GET /api/v1/admin/logs` returns a redacted feed of recent `warning`-and-above events (`mode=summary` for counts by level/route, `mode=recent` for individual events). Every field is allow-listed and free text is scrubbed, so it never carries chat content, user emails, document text or secrets.

The same feed is available to the in-chat AI through the admin-only `recent_errors` MCP tool. See [Observability](OBSERVABILITY.md) for the field list, retention and the `X-Request-Id` correlation flow.

### Recommended Uptime Robot Settings

| Setting | Value |
|---------|-------|
| Type | Keyword |
| Keyword | `STATUS:OK` |
| Alert when | Keyword does NOT exist |
| Interval | 5 min |
| Alert threshold | 2 consecutive failures |

---

## Backups

For production, back up all four state classes together:

- MariaDB data
- uploaded files
- Qdrant collections and snapshots
- `deploy/data/secrets.env`, the deployment's generated secrets

The secrets file is as critical as the data itself: it holds the MariaDB
password the restored database expects, and it exists nowhere else. A recovery
point without it yields a database the application cannot open.

The portable lifecycle hooks under `deploy/scripts/` coordinate write
processes and prepare consistent artifacts in the deployment data paths:

1. Run `deploy/scripts/pre-backup.sh`.
2. After the hook has created SQL, Qdrant, and uploaded-file artifacts and
   stopped the running stateful services, capture the deployment data paths
   with the platform backup system.
3. Run `deploy/scripts/post-backup.sh`, including after a failed snapshot, so
   exactly the services paused by the pre hook resume.

Elestio maps these hooks through its lifecycle configuration. Trigger the
backup from Elestio and wait for it to complete before making changes or
updating.

Schedule daily backups and keep at least 7 daily and 4 weekly recovery points.
Store an encrypted copy outside the host and restrict access to generated dumps
and snapshots.

### Restore

Restore into an isolated deployment before using the procedure on the live
instance:

1. Verify the backup's timestamp, version, size, and integrity.
2. Run `deploy/scripts/pre-restore.sh`.
3. Restore the complete data set through the platform: MariaDB dump/data,
   uploads, and Qdrant state must come from the same recovery point.
4. Run `deploy/scripts/post-restore.sh`. It repairs permissions, clears cache,
   applies required migrations, resumes services, and checks health.
5. Verify administrator login, representative database records, uploaded files,
   and a known Qdrant-backed RAG search.

Do not declare a backup strategy operational until this full restore has
succeeded on a fresh, separate stack.

---

## Updates

Follow the step-by-step guide for your platform:

- [Update a Self-Hosted Deployment](UPDATE_SELFHOST.md)
- [Update on Elestio](UPDATE_ELESTIO.md)
- [Update on AWS (Marketplace AMI)](UPDATE_AWS.md)

Production images must use a released, pinned `SYNAPLAN_VERSION`; never update
a production deployment by following `latest`. Read the release notes and the
[migration guidance](MIGRATIONS.md) before you start.

The web container applies migrations through its production startup contract.
Do not run `doctrine:schema:update --force`. Keep the pre-update recovery point
until login, chat, uploads/RAG, worker processing, scheduler execution, and
realtime connections have been verified. If verification fails, restore the
complete pre-update recovery point rather than restoring only the database.

---

## Elestio Trial Cleanup

An imported custom pipeline can incur charges even though the initial
three-day trial includes credit. Before the recorded trial expiry:

1. Delete the Synaplan pipeline and every target or service created for it.
2. Delete trial backups and select immediate deletion where offered.
3. Confirm Auto-Refill is disabled.
4. Check the Elestio resource and billing views for any remaining billable
   resource.
5. Retain only scrubbed test evidence; remove credentials, secret-bearing logs,
   and private endpoint details.

Custom import support does not imply acceptance into Elestio's Fully Managed
Catalog. Catalog submission is a separate partnership process and should happen
only after fresh-install, persistence, restore, update, rollback, and cleanup
tests pass.

---

## Security

### Token Rotation

Rotate health monitor API-Key:

1. Log in as the health-monitor user, revoke old API-Key, create new one
2. Update Uptime Robot header with the new key

Rotate `APP_SECRET`:

1. Generate new secret: `openssl rand -hex 16`
2. Update ENV var, restart backend
3. Existing JWT tokens are invalidated — users must re-login

### CORS

`CORS_ALLOW_ORIGIN` must match your frontend domain exactly. Never use `*` in production.

### JWT Keys

Auto-generated on first start at `backend/config/jwt/`. To regenerate:

```bash
docker compose --env-file deploy/.env -f deploy/compose.yaml \
  exec backend php bin/console lexik:jwt:generate-keypair --overwrite
```

All active sessions are invalidated on key rotation.

### HTTPS

Always run behind a reverse proxy (nginx, Caddy, Traefik) with TLS termination. Synaplan does not handle TLS directly.

---

## User Management

User levels: `NEW`, `PRO`, `TEAM`, `BUSINESS`, `ADMIN`.

### Running These Statements

The production stack publishes no database port, so open a database prompt inside
the stack:

```bash
docker compose --env-file deploy/.env -f deploy/compose.yaml \
  exec db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"'
```

On the local development stack (root `docker-compose.yml`) the variable names
inside the database container are different:

```bash
docker compose exec db \
  sh -c 'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'
```

Either command opens a MariaDB prompt — `MariaDB [synaplan]>` with the default
database name. Keep the single quotes: the user name, password, and database name
are then read inside the container, so no password appears on your command line
or in your shell history. Enter the statements below one at a time, end each one
with `;`, and type `exit` when you are done. Development also offers phpMyAdmin
at http://localhost:8082.

Verify the user exists before changing their level:

```sql
SELECT BID, BMAIL, BUSERLEVEL, BEMAILVERIFIED FROM BUSER WHERE BMAIL = 'user@example.com';
```

Promote to admin only after confirming the correct user:

```sql
UPDATE BUSER SET BUSERLEVEL = 'ADMIN' WHERE BID = <id from above>;
```

An account that never confirmed its sign-up email cannot sign in, whatever its
level. If the query above returned `BEMAILVERIFIED = 0` — which is what happens
when the deployment has no working SMTP configuration — mark it as verified in
the same step:

```sql
UPDATE BUSER SET BUSERLEVEL = 'ADMIN', BEMAILVERIFIED = 1 WHERE BID = <id from above>;
```

List all admin users:

```sql
SELECT BID, BMAIL, BUSERLEVEL FROM BUSER WHERE BUSERLEVEL = 'ADMIN';
```

Always use `BID` (primary key) in UPDATE statements to avoid affecting the wrong account.

---

## People and groups

Groups, the People page under Operate, and the group API are gated by
`IAM.GROUPS_ENABLED` (BCONFIG group `IAM`, owner `0`). The seeder inserts the
flag as `0`. Existing installs stay unchanged until an operator turns it on.

When the flag is off:

- Operate has no People child.
- `/api/v1/admin/groups` and `/api/v1/groups/mine` return 404.
- The Operate Overview **Users** tab is unchanged.

When the flag is on:

- Operate shows **People** (`/admin/people`) with **Users** and **Groups**.
- An admin can create a manual group, add people by email, and set the role
  to member or manager.
- Groups that come from company login (`kind=directory`) are read-only here.
- Sharing a folder or conversation with a group is gated separately by
  `IAM.SHARING_ENABLED` (effective only when groups are also on).

## Sharing

Sharing is off until both `IAM.GROUPS_ENABLED` and `IAM.SHARING_ENABLED` are
`1`. When they are on:

- An owner can share a knowledge folder or a conversation with a person, a
  group, or everyone on the instance.
- Conversation permissions are **Can view** and **Can use**. **Can use** lets
  a member continue the chat as their own copy (file binaries stay with the
  owner).
- RAG only includes another person's files when a share grants **Can use**
  or higher. A query never runs without an owner scope.
- `IAM.EVERYONE_SHARES` (`any_owner` | `admins_only`) is in Operate → System
  config → Sharing.

Enable:

```sql
INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (0, 'IAM', 'SHARING_ENABLED', '1')
ON DUPLICATE KEY UPDATE BVALUE = '1';
```

Public token links are unchanged. Admins do not see other people's chats or
files unless those items are shared with them.

Enable on a running instance:

```sql
INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (0, 'IAM', 'GROUPS_ENABLED', '1')
ON DUPLICATE KEY UPDATE BVALUE = '1';
```

Then reload the app (or wait for the next runtime-config fetch). Rollback is
the same statement with `'0'`. Group rows stay in the database.

API keys: empty or legacy webhook-only scopes keep full access. A key that
opts into `iam:read` or `iam:manage` is limited to those People routes.

---

## Self-awareness (`SELF_AWARE`)

The assistant can answer "what can you do here?" from a live capability
inventory, and "how do I …?" from a system-owned copy of
[docs.synaplan.com](https://docs.synaplan.com/). Both are gated by the
`SELF_AWARE` BCONFIG group (owner 0). Rows are insert-if-missing on
`app:seed` and are never overwritten.

### Flags

| Group / Key | Default | Effect when off |
|-------------|---------|-----------------|
| `SELF_AWARE / ENABLED` | `true` | No inventory block, `/help` falls through to ordinary chat, the `synaplan` topic is hidden from routing. Byte-identical to a pre-feature install. |
| `SELF_AWARE / INVENTORY_IN_GENERAL` | `true` | Inventory is injected only into the `synaplan` topic, not into everyday `general` chat. |
| `SELF_AWARE / DOCS_RAG_ENABLED` | `true` | No documentation retrieval and no `docs_loaded` citations. The corpus sync still runs. |
| `SELF_AWARE / DOCS_MANIFEST_URL` | `https://docs.synaplan.com/docs-manifest.json` | Empty string disables sync (air-gapped). Point at a mirror that serves the same three endpoints (`/docs-manifest.json`, `/raw/{slug}.md`, `/llms.txt`). |

Resolution for the boolean flags is per-user → owner 0 → built-in default.
`DOCS_MANIFEST_URL` is operator-only (owner 0).

### Commands

```bash
# Live capability block for a user (default user id 2)
docker compose exec -T backend php bin/console app:selfaware:inventory --user 2

# Refresh the SYSTEM:synaplan documentation corpus
docker compose exec -T backend php bin/console app:selfaware:sync-docs
docker compose exec -T backend php bin/console app:selfaware:sync-docs --dry-run
docker compose exec -T backend php bin/console app:selfaware:sync-docs --force

# Release spot-check (needs a live chat model; not part of `make test`)
docker compose exec -T backend php bin/console app:selfaware:eval --install=no_engine
```

`app:selfaware:sync-docs` also runs in the daily scheduler slot (after
`app:updates:check`) and is queued when a new published version is
recorded. It never runs at container boot. The corpus is owner 0 /
`SYSTEM:synaplan` and does not appear in any user's file list.

An unreachable manifest leaves the previous corpus in place. An empty
`DOCS_MANIFEST_URL` prints `skipped` and exits 0.

### Release checklist: `KNOWN_ABSENT`

There is no `docs/RELEASE.md`. Before every release that ships a capability,
review `PlatformCapabilityInventory::KNOWN_ABSENT`:

1. **Remove** any entry a shipped feature now provides.
2. **Add** an `alternative` (and an `adminHint` when an operator can enable
   the missing piece) for anything newly and deliberately unsupported.

This is the only hand-maintained list in the feature. Leaving a stale
entry makes the assistant deny something the install can do; missing an
entry makes it invent a capability.

---

## Integrations

| Channel | Guide |
|---------|-------|
| Email | [EMAIL.md](EMAIL.md) |
| WhatsApp | [WHATSAPP.md](WHATSAPP.md) |
| Widget / Embed | [WIDGET.md](WIDGET.md) |
| OpenAI-compatible API | [OPENAI_COMPATIBLE_API.md](OPENAI_COMPATIBLE_API.md) |
| Anthropic-compatible API (Claude Code) | [ANTHROPIC_COMPATIBLE_API.md](ANTHROPIC_COMPATIBLE_API.md) |

---

## Troubleshooting

### Logs

```bash
docker compose --env-file deploy/.env -f deploy/compose.yaml logs -f backend
docker compose --env-file deploy/.env -f deploy/compose.yaml logs -f db
docker compose --env-file deploy/.env -f deploy/compose.yaml \
  logs --tail=100 backend
```

### Restart Services

```bash
docker compose --env-file deploy/.env -f deploy/compose.yaml restart backend
docker compose --env-file deploy/.env -f deploy/compose.yaml up -d
```

### Full Reset (Development Only — Never in Production)

The following command **permanently destroys all data** including the database, uploads, and AI models. There is no recovery without a backup.

```bash
docker compose down -v
docker compose up -d
```

**Do not run `docker compose down -v` on production systems.**
