# Administration Guide

Operations reference for self-hosted and enterprise deployments.

---

## Production Setup

### Environment

Generate a unique application secret first:

```bash
openssl rand -hex 16
```

Then set all production variables in your `.env`:

```bash
APP_ENV=prod
APP_SECRET=<output from above>
APP_URL=https://your-domain.com
FRONTEND_URL=https://your-domain.com
CORS_ALLOW_ORIGIN=https://your-domain.com
```

See [Configuration Guide](CONFIGURATION.md) for all environment variables.

### Starting Services

```bash
docker compose up -d
```

Minimal (cloud AI only, no local models):

```bash
docker compose -f docker-compose-minimal.yml up -d
```

See [Installation Guide](INSTALLATION.md) for full setup instructions.

---

## Monitoring

### Health Check Endpoint

`GET /api/health/login` exercises the full auth stack (DB, password hash, email-verified gate, token generation) and returns `STATUS:OK` or `STATUS:ERROR`.

Protected by `X-Health-Monitor-Token` header. No sensitive details in the response — diagnostics are logged server-side only.

Quick smoke test:

```bash
curl -i -H "X-Health-Monitor-Token: <token>" https://your-domain.com/api/health/login
```

See [Health Monitoring](HEALTH_MONITORING.md) for full setup: token generation, monitor user creation, Uptime Robot configuration.

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

### Database

MariaDB — use `mariadb-dump` or equivalent:

```bash
docker compose exec db mariadb-dump -u root -p synaplan > backup_$(date +%Y%m%d).sql
chmod 600 backup_$(date +%Y%m%d).sql
```

Restore (always verify the backup file before restoring):

```bash
docker compose exec -T db mariadb -u root -p synaplan < backup_20260428.sql
```

Schedule daily backups via cron. Keep at least 7 daily and 4 weekly snapshots. Store backups outside the project directory with restricted permissions (`chmod 600`). For sensitive environments, encrypt backups with `gpg` or equivalent before transferring off-server.

### Uploaded Files

Back up the backend storage volume:

```bash
docker compose cp backend:/var/www/backend/var/uploads ./backup-uploads/
chmod -R 600 ./backup-uploads/
```

---

## Updates

**Always back up the database before updating:**

```bash
docker compose exec db mariadb-dump -u root -p synaplan > backup_pre_update_$(date +%Y%m%d).sql
```

Then pull and rebuild:

```bash
git pull
docker compose build --no-cache
docker compose up -d
```

Check `docs/MIGRATIONS.md` before updating — some releases require database migrations:

```bash
docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction
```

Verify the application works after the update. If something breaks, restore from the pre-update backup.

---

## Security

### Token Rotation

Rotate `HEALTH_MONITOR_TOKEN`:

1. Generate new token: `openssl rand -hex 32`
2. Update ENV var, restart backend
3. Update Uptime Robot header

Rotate `APP_SECRET`:

1. Generate new secret: `openssl rand -hex 16`
2. Update ENV var, restart backend
3. Existing JWT tokens are invalidated — users must re-login

### CORS

`CORS_ALLOW_ORIGIN` must match your frontend domain exactly. Never use `*` in production.

### JWT Keys

Auto-generated on first start at `backend/config/jwt/`. To regenerate:

```bash
docker compose exec backend php bin/console lexik:jwt:generate-keypair --overwrite
```

All active sessions are invalidated on key rotation.

### HTTPS

Always run behind a reverse proxy (nginx, Caddy, Traefik) with TLS termination. Synaplan does not handle TLS directly.

---

## User Management

User levels: `NEW`, `PRO`, `ADMIN`.

Verify the user exists before changing their level:

```sql
SELECT BID, BMAIL, BUSERLEVEL FROM BUSER WHERE BMAIL = 'user@example.com';
```

Promote to admin only after confirming the correct user:

```sql
UPDATE BUSER SET BUSERLEVEL = 'ADMIN' WHERE BID = <id from above>;
```

List all admin users:

```sql
SELECT BID, BMAIL, BUSERLEVEL FROM BUSER WHERE BUSERLEVEL = 'ADMIN';
```

Always use `BID` (primary key) in UPDATE statements to avoid affecting the wrong account.

---

## Integrations

| Channel | Guide |
|---------|-------|
| Email | [EMAIL.md](EMAIL.md) |
| WhatsApp | [WHATSAPP.md](WHATSAPP.md) |
| Widget / Embed | [WIDGET.md](WIDGET.md) |
| OpenAI-compatible API | [OPENAI_COMPATIBLE_API.md](OPENAI_COMPATIBLE_API.md) |

---

## Troubleshooting

### Logs

```bash
docker compose logs -f backend
docker compose logs -f db
docker compose logs --tail=100 backend
```

### Restart Services

```bash
docker compose restart backend
docker compose down && docker compose up -d
```

### Full Reset (Development Only — Never in Production)

The following command **permanently destroys all data** including the database, uploads, and AI models. There is no recovery without a backup.

```bash
docker compose down -v
docker compose up -d
```

**Do not run `docker compose down -v` on production systems.**
