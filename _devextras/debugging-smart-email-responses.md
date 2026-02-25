# Debugging Smart Email Responses

**Problem**: Users send emails to `smart@synaplan.net` but don't receive AI responses.

## Quick Fix

**99% of issues**: `MAILER_DSN` not configured.

```bash
# Check if set
docker compose exec backend env | grep MAILER_DSN

# Add to .env (Gmail example - use App Password!)
MAILER_DSN=smtp://your-email@gmail.com:app-password@smtp.gmail.com:587
```

## Quick Checks

```bash
# 1. Check email processing
docker compose exec backend php bin/console app:process-emails

# 2. Check logs for errors
docker compose logs backend | grep -i "email\|webhook" | tail -20

# 3. Look for these log messages:
# ✅ "Email webhook received" - Email reached webhook
# ✅ "AI response email sent" - Response was sent
# ❌ "Failed to send AI response email" - MAILER_DSN issue
```

## Common MAILER_DSN Formats

```bash
# Gmail (use App Password)
MAILER_DSN=smtp://email@gmail.com:app-password@smtp.gmail.com:587

# Mailhog (dev)
MAILER_DSN=smtp://mailhog:1025

# Generic SMTP
MAILER_DSN=smtp://user:pass@mail.example.com:587
```

## Flow

1. Email → Mailhog/Gmail
2. `app:process-emails` → `/api/v1/webhooks/email`
3. AI processes → `sendAiResponseEmail()` → **Requires MAILER_DSN**
