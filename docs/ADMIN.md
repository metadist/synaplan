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
3. Signed-in users stay signed in: only the 5-minute `access_token` cookies
   stop verifying, and the browser silently obtains new ones through
   `/auth/refresh` because the refresh tokens in `BTOKENS` do not depend on
   `APP_SECRET`. To force everyone out, clear `BTOKENS` as described under
   *Sessions survive restarts*. Provider API keys encrypted with the old
   secret become unreadable and must be re-entered.

### CORS

`CORS_ALLOW_ORIGIN` must match your frontend domain exactly. Never use `*` in production.

### Sessions survive restarts

There is no JWT keypair to generate. Sign-in uses two HttpOnly cookies minted
by `App\Service\TokenService`:

| Cookie | Lifetime | Where it lives | What a restart does |
| ------ | -------- | -------------- | ------------------- |
| `access_token` | 5 minutes | HMAC-signed with `APP_SECRET`, not stored | Nothing — the signature still verifies |
| `refresh_token` | 30 days, sliding on every refresh | `BTOKENS` in MariaDB | Nothing — the row is still there |

Restarting or redeploying the backend, worker, Redis or the whole compose
stack therefore keeps every browser and mobile-app session; the web app
retries `/auth/refresh` through a 5xx/network blip instead of treating it as a
sign-out, and only a definitive `401`/`403` ends the session. Two things must
stay stable for that to hold:

- **`APP_SECRET`** — the self-hosted stack persists it in
  `deploy/data/secrets.env` (see `deploy/README.md`); Helm and other
  automated deployments must inject the same value on every rollout. A new
  secret invalidates every access cookie at once (sessions recover through
  `/auth/refresh`, see *Token Rotation*) and makes the provider API keys
  stored in the database unreadable.
- **The MariaDB volume** — `BTOKENS` holds the refresh tokens. Wiping the
  database signs everyone out.

To sign every user out deliberately, delete their rows from `BTOKENS`
(`DELETE FROM BTOKENS WHERE BTYPE = 'refresh'`). Rotating `APP_SECRET` alone
does **not** do that — the refresh tokens are plain database rows.

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
`IAM.GROUPS_ENABLED` (BCONFIG group `IAM`, owner `0`). The flag is **on by
default** (seeded `1`; a migration turns it on for existing installs). Operators
switch it in **Operate → System configuration → Features → People & sharing**
(`/admin/config?tab=features`, field `FEATURE_IAM_GROUPS_ENABLED`) or pin it
for automated deployments with the environment variable
`FEATURE_IAM_GROUPS_ENABLED=false`. See [Feature flags](FEATURE_FLAGS.md) for
the full list.

When the flag is off:

- The Operate **People** child opens the Operate **Users** tab
  (`/admin?tab=users`); `/admin/people` redirects there.
- `/groups` is not routable and shows the not-found page.
- `/api/v1/admin/groups` and `/api/v1/groups/mine` return 404.
- The Operate Overview **Users** tab is unchanged.

When the flag is on:

- Operate shows **People** (`/admin/people`) with **Users**, **Groups**, and
  **Audit**. **Policies** appears only when group policies are also on.
- An admin can create a manual group, add people by email, and set the role
  to member or manager.
- Groups that come from company login (`kind=directory`) can still receive
  extra people by hand; memberships that came from login update at the next
  sign-in.
- Sharing a folder or conversation with a group is gated separately by
  `IAM.SHARING_ENABLED` (effective only when groups are also on).

## Sharing

Sharing needs both `IAM.GROUPS_ENABLED` and `IAM.SHARING_ENABLED` set to `1`
(both are on by default; **Features → People & sharing** or
`FEATURE_IAM_SHARING_ENABLED`). When they are on:

- An owner can share a knowledge folder, a conversation, an **AI assistant**,
  a **saved task**, or a **chat widget** with a person, a group, or everyone
  on the instance.
- Conversation permissions are **Can view** and **Can use**. **Can use** lets
  a member continue the chat as their own copy (file binaries stay with the
  owner).
- RAG only includes another person's files when a share grants **Can use**
  or higher. A query never runs without an owner scope.
- `IAM.EVERYONE_SHARES` (`any_owner` | `admins_only`) is on the same Sharing
  page. With `admins_only`, the share dialog does not offer
  "Everyone on this instance" to non-admins.
- **Can manage** on a folder lets that person re-share it; only the owner can
  delete it. Sharing an item with yourself is rejected.
- A copy made with "continue as copy" keeps the conversation text, but the
  owner's files are readable and searchable only while the share exists.
  Revoking the share closes them again on the next request.
- The public link token of a conversation is only returned to its owner; a
  group share never exposes it.
- `IAM.DIRECTORY_SYNC_ENABLED` (seeded `1`) puts people into groups from the
  company login (OIDC groups claim) at sign-in. Role mapping is unchanged.
  Directory groups show **From your login** on People; you can still add extra
  people by hand. Login-managed memberships update at the next sign-in.

### Directory groups

**Directory groups** is on by default; switch it under **Operate → System
configuration → Features → People & sharing**
(`FEATURE_IAM_DIRECTORY_SYNC_ENABLED`). The claim settings stay under
**Access → Sharing**. Optional settings:

| Setting | Default | Meaning |
| ------- | ------- | ------- |
| `IAM.DIRECTORY_GROUPS_CLAIM` | `groups` | Dotted claim path (same resolver as OIDC roles) |
| `IAM.DIRECTORY_GROUP_NAMES` | `{}` | JSON map of claim value → display name |

Acceptance script: `_devextras/testing/iam/directory-demo.sh`. OpenCloud
token-exchange check: `_devextras/testing/iam/opencloud-regression.md`.

### Audit

People → **Audit** lists who shared what, group changes, login-group updates,
impersonation, and when an admin opened another user's resource list. Rows
never include content. `app:iam:reap-audit` deletes rows older than
`IAM.AUDIT_RETENTION_DAYS` (default 365; `0` keeps them forever).

### Admin privacy and impersonation

Administrators can share, unshare and delete (manage) but they cannot read
another person's chats, files or assistants unless those items are shared with
them. **Operate → People → Users** has **View as user** for audited
impersonation (`IAM.ADMIN_IMPERSONATION` = `audited`). Set it to `disabled`
to hide that action.

### Group policies and locked defaults

**Group policies** is switched under **Operate → System configuration →
Features → People & sharing** (`FEATURE_IAM_GROUP_POLICIES_ENABLED`). People &
groups must also be on. The seeder inserts the flag as `1`. Off means every
resolver reads only `[user, global]` and never touches `BGROUPCONFIG`.

When the flag is on, People shows a **Policies** tab. Pick one group at a
time and set:

| Setting | What it does | Several groups |
| ------- | ------------ | -------------- |
| Default models (`DEFAULTMODEL.*`) | Suggested model per capability | First group by id |
| Allowed models (`MODELS.ALLOWED`) | Empty = every model; a list hides the rest | Union |
| Features (saved tasks, desktop agent, document tools, multi-step) | On if any group turns the feature on | OR |
| Rate-limit tier (`RATELIMITS.TIER`) | Which limit table `checkLimit()` uses | Highest of NEW / PRO / TEAM / BUSINESS |

A personal setting still wins unless you lock the global row. Locked defaults
show **Set by your administrator** on the user's model settings; a group
default (when not locked) shows **Default from your group**. Changing a locked
setting returns **409** `iam.settingLocked`.

Search embeddings (`VECTORIZE`) stay instance-wide: you can store a group
default, but the indexer and the user's Search dropdown still use the global
row.

Acceptance script: `_devextras/testing/iam/policy-demo.sh`.

The People, sharing, and policy switches live under
**Operate → System configuration → Features → People & sharing**. The page
reloads the runtime config so People, Share, and Policies appear without a
restart. For automated deployments pin a flag with its `FEATURE_*` environment
variable (the toggle then shows as locked); SQL remains available too:

```sql
INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (0, 'IAM', 'SHARING_ENABLED', '1')
ON DUPLICATE KEY UPDATE BVALUE = '1';
```

### Publishing an assistant to a group

1. Turn groups and sharing on (both flags above).
2. Create a custom assistant under **AI → Assistants** (**AI → Instructions**
   while `FEATURE_AGENTS_ENABLED` is off). System assistants with owner `0`
   cannot be shared — everyone can already use them.
3. Open **Share** and grant **Can use** to the group (for example Sales).
4. Members of that group see the assistant in their list and the classifier
   may pick it. Its knowledge folder `TASKPROMPT:{topic}` rides with the
   share. People outside the group get **403** on `GET /api/v1/prompts/{id}`.
5. A shared **saved task** is run as the member's own copy
   (`POST /api/v1/saved-tasks/{id}/copy`) — trigger resets to manual. The
   assistant on that task must also be usable (owned, system, or shared
   `use`), otherwise the copy returns **409** `iam.assistantNotShared`.
6. A shared **widget** can be opened read-only (`read`) or co-edited (`edit`).
   Embed code, visitor sessions, and transcripts stay with the owner.

Acceptance script (HTTP only): `_devextras/testing/iam/publish-demo.sh`.

### Plugin-declared kinds

A plugin `manifest.json` may declare shareable rows in `plugin_data`:

```json
{
  "provides": {
    "resourceKinds": [
      {
        "key": "synaform:form",
        "dataType": "form",
        "labelKey": "synaform.kind.form",
        "permissions": ["read", "use", "edit"]
      }
    ]
  }
}
```

`key` must be `{pluginId}:{name}`. Permissions are a subset of
`read`, `use`, `edit`, `manage`. An invalid field fails plugin load and names
the field. Shared rows are loaded with
`PluginDataRepository::findSharedWith($userId, $pluginId, $dataType)`.

Public token links are unchanged. Admins do not see other people's chats,
files, assistants, tasks, or widget transcripts unless those items are shared
with them.

**People & groups** is on by default (`FEATURE_IAM_GROUPS_ENABLED` on the
Features tab). Members see **Account → My groups**. Rollback is the same toggle
(or SQL with `'0'`, or `FEATURE_IAM_GROUPS_ENABLED=false`). Group rows stay in
the database.

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

### Linked platforms

A **linked platform** is a Nextcloud, ownCloud or OpenCloud server whose users
sign in to Synaplan with the account they already have, instead of the partner
app minting a fresh Synaplan account per user. The partner instance registers
once; each of its users then links once, in the browser, while signed in to
Synaplan. The result is a scoped API key (`chat`, `files`, `rag`, optionally
`memories`) that the partner app stores and that the user can revoke at any
time. The Outlook add-in (Synamail) uses the same bridge page and is always on.

Everything under `/api/v1/platform-links/*` and `/api/v1/me/platform-links*`
is gated by `PLATFORM_LINKS.ENABLED` (on by default — **Features → Platforms
& desktop**, `FEATURE_PLATFORM_LINKS_ENABLED`); with the flag off those routes
answer **404** and nothing in the UI changes.

```sql
INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (0, 'PLATFORM_LINKS', 'ENABLED', '1')
ON DUPLICATE KEY UPDATE BVALUE = '1';
```

Rollback is the same statement with `'0'`. Rows in `BPLATFORMINSTANCES` and
`BEXTERNALIDENTITIES` stay; issued keys keep working until revoked.

**Approving instances.** A partner instance registered by a signed-in
administrator is `active` immediately. One registered anonymously or by a
regular user is `pending` and cannot issue link codes until an administrator
approves it under **Operate → People → Linked platforms** (host, client,
status, last seen; *Approve* / *Revoke*). Anonymous registration is limited to
10 per hour per address. Revoking an instance also revokes every key it
issued.

**What a user sees.** **Channels & integrations → Linked platforms** lists the
user's own links (platform, host, external id, key name) with *Disconnect*,
which revokes that key. API keys minted this way carry a *linked platform*
badge on the API-keys page. Link codes live five minutes, are single-use, and
a user may issue at most 20 per hour.

**Security model.** Redirect targets are prefix-matched against the URIs the
instance registered (HTTPS only outside dev, same host, same port, no
wildcard hosts). A rejected redirect is audited as
`platform_link.redirect_rejected`; every register, approve, revoke, link and
disconnect writes a People → Audit row. Re-linking an external id that already
belonged to another Synaplan account revokes the old key and moves the link to
the new account (`platform_link.reassigned`, audited under both) — one external
user is never two Synaplan accounts at once.

Acceptance script: `_devextras/testing/platform-links/fake-instance.sh`
(`--flag-off` proves the 404 contract). Endpoint reference:
[Swagger UI](http://localhost:8000/api/doc) → tag *Platform Links*.

### AI plugs (S1–S5)

Extraction, web search and rerank go through `App\Plug\` registries.
FileProcessor still runs today's built-in strategies (native → Tika →
vision → STT). Extra adapters whose keys are not built-in (today:
`docling`) run first when an admin adds them to a family chain, and fall
through if they fail, so a down sidecar never blocks an upload.

Web search defaults to Brave (`WEB_SEARCH.PROVIDER=brave`). An admin
picks Brave, SearXNG, Tavily, Exa, Firecrawl or Perplexity on
**Operate → AI infrastructure → Web search**, plus an optional
fallback. The next chat search uses the new provider with no restart.
**Test query** shows up to five titles. Per-user override is
**Settings → Use my own search**, only when
`WEB_SEARCH.USER_OVERRIDE_ALLOWED=1`.

Rerank stays **off** by default (`RERANK.ENABLED=0`) until a live eval
shows recall@5 up and p95 latency inside the budget. An admin turns it
on under **Operate → AI infrastructure → Reranking**: pick a catalog
rerank model (TEI / Jina / Cohere / Voyage), set how many extra
snippets to fetch and the millisecond budget, optionally allow the
summary model as a costly fallback, and run **Test order** on sample
snippets. Chat search is unchanged while rerank is off.

Importing models saves typing each row by hand. On **Models & keys**,
an OpenAI-compatible endpoint card has **Import models** and the local
AI card has **Import pulled models**. The preview lists what the
endpoint offers with a guessed capability tag you can edit, and an
**already added** badge for rows the catalog has. **Import** creates
only the new rows; running it again says nothing changed, and it never
touches a model you switched off or made a default. The optional
**Check what each model can do** box sends two tiny requests per model
(one chat, one embeddings) and refines the guess — it uses a little
credit, so it is off by default. The scheduled model health check also
re-lists each import source: a model the endpoint no longer offers is
marked **not offered by endpoint** and (only when auto-disable is on)
switched off; it recovers by itself when the endpoint lists it again.
An **unreachable** endpoint marks nothing, so a brief outage never
retires a model. Native Ollama is not capability-probed this way.

The Operate page is **AI infrastructure** (`/admin/setup`). The
**Extraction** tab shows adapter health, lets an admin reorder a family
chain, and offers **Test with a file**. Tika and Docling have the same
sidecar controls: a connection test on that tab, and URL / timeout
(plus Docling max file size) under **System configuration →
Processing**. Models & keys is the previous provider-key UI and now
includes Perplexity as an optional chat provider. The **Web search**
tab is the provider picker. The **Reranking** tab is the rerank
settings and test.

Settings table: [CONFIGURATION.md — AI plugs](CONFIGURATION.md#ai-plugs-plugs).

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
