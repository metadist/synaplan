# Quick Development Commands

## Database Management

### Check Users
```bash
# List all users
docker compose exec backend php bin/console doctrine:query:sql "SELECT BID, BMAIL, BEMAILVERIFIED FROM BUSER"

# Check specific user
docker compose exec backend php bin/console doctrine:query:sql "SELECT * FROM BUSER WHERE BMAIL='user@example.com'"
```

### Manually Verify Email
```bash
# Verify user by email
docker compose exec backend php bin/console doctrine:query:sql "UPDATE BUSER SET BEMAILVERIFIED=1 WHERE BMAIL='user@example.com'"

# Verify all users (dev only)
docker compose exec backend php bin/console doctrine:query:sql "UPDATE BUSER SET BEMAILVERIFIED=1"
```

### Check Email Verification Attempts
```bash
docker compose exec backend php bin/console doctrine:query:sql "SELECT * FROM BEMAILVERIFICATION"
```

### Reset Rate Limits
```bash
# Delete all rate limit entries
docker compose exec backend php bin/console doctrine:query:sql "DELETE FROM BEMAILVERIFICATION"

# Delete for specific email
docker compose exec backend php bin/console doctrine:query:sql "DELETE FROM BEMAILVERIFICATION WHERE BEMAIL='user@example.com'"
```

## Email Testing

### With MailHog (Development)
1. **Set in `.env.local`:**
   ```env
   MAILER_DSN=smtp://mailhog:1025
   ```

2. **Restart:**
   ```bash
   docker compose restart backend
   ```

3. **View Emails:**
   - Web UI: http://localhost:8025
   - API: http://localhost:8025/api/v2/messages

### With Real SMTP (Production-like)
1. **Set in `.env.local`:**
   ```env
   MAILER_DSN="smtp://user:password@smtp.gmail.com:587"
   ```

2. **Restart:**
   ```bash
   docker compose restart backend
   ```

3. **Test:**
   ```bash
   curl -X POST http://localhost:8000/api/v1/auth/resend-verification \
     -H "Content-Type: application/json" \
     -d '{"email":"test@example.com"}'
   ```

## Logs

### Watch Logs
```bash
# All logs
docker compose logs -f backend

# Mail-related logs only
docker compose logs -f backend | grep -i "mail\|verification\|smtp"

# Last 50 lines
docker compose logs backend --tail=50
```

### Check for Errors
```bash
docker compose logs backend --tail=100 | grep -i "error\|exception\|critical"
```

## User Registration & Testing

### Quick Test User
```bash
# Register via API
curl -X POST http://localhost:8000/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "email": "test@example.com",
    "password": "Test123!",
    "language": "en"
  }'

# Manually verify
docker compose exec backend php bin/console doctrine:query:sql \
  "UPDATE BUSER SET BEMAILVERIFIED=1 WHERE BMAIL='test@example.com'"
```

## Cache & Performance

### Clear Cache
```bash
docker compose exec backend php bin/console cache:clear
```

### Warm Up Cache
```bash
docker compose exec backend php bin/console cache:warmup
```

## Database Reset (Dev Only)

### Full Reset
```bash
# Drop and recreate
docker compose exec backend php bin/console doctrine:database:drop --force
docker compose exec backend php bin/console doctrine:database:create
docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec backend php bin/console doctrine:fixtures:load --no-interaction
```

### Quick Reset (Keep Structure)
```bash
docker compose exec backend php bin/console doctrine:fixtures:load --no-interaction --purge-with-truncate
```

## Common Issues

### "Email not received"
1. Check logs: `docker compose logs backend --tail=50 | grep -i mail`
2. Check MAILER_DSN in `.env.local`
3. For Gmail: Check spam folder
4. For MailHog: Open http://localhost:8025

### "Rate limited"
```bash
# Reset rate limits
docker compose exec backend php bin/console doctrine:query:sql "DELETE FROM BEMAILVERIFICATION WHERE BEMAIL='user@example.com'"
```

### "User already verified"
```bash
# Unverify user
docker compose exec backend php bin/console doctrine:query:sql "UPDATE BUSER SET BEMAILVERIFIED=0 WHERE BMAIL='user@example.com'"
```

