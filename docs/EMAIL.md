# Email Integration

AI-powered email conversations with smart context management.

## Overview

Send emails to Synaplan and get AI responses:

- **General inbox**: `smart@synaplan.net`
- **Topic-specific**: `smart+keyword@synaplan.net`

---

## Email Addresses

| Address | Creates | Example Use |
|---------|---------|-------------|
| `smart@synaplan.net` | General chat | Quick questions |
| `smart+project@synaplan.net` | Project context | Project discussions |
| `smart+support@synaplan.net` | Support context | Help tickets |
| `smart+research@synaplan.net` | Research context | Research threads |

The `+keyword` becomes a dedicated chat context, keeping related conversations together.

---

## How It Works

```
User sends email
    ↓
System checks sender
    ↓
Registered? → Use user's rate limits
Unknown? → Create anonymous user (ANONYMOUS limits)
    ↓
Parse keyword from recipient (smart+keyword@)
    ↓
Find or create chat context
    ↓
Process through AI pipeline
    ↓
Send response via email
```

---

## User Types

| User Type | Detection | Rate Limits |
|-----------|-----------|-------------|
| **Registered** | Email matches account | Subscription limits |
| **Anonymous** | Unknown sender | ANONYMOUS (10 messages) |
| **Blacklisted** | Spam detected | Blocked |

---

## Spam Protection

Built-in spam prevention:

- **Rate limit**: Max 10 emails/hour per unknown address
- **Auto-blacklist**: Triggered after exceeding limits
- **Unified limits**: Same limits across Email, WhatsApp, Web

---

## Email Threading

Replies stay in the same chat context:

1. User sends to `smart+project@synaplan.net`
2. AI responds
3. User replies to the email
4. Conversation continues in "project" context

---

## Setup (Self-Hosted)

### Incoming Email

Configure your mail server to forward to Synaplan's webhook:

```bash
# Webhook endpoint
POST /api/v1/webhooks/email

# Headers
X-API-Key: your_api_key
Content-Type: application/json

# Body
{
  "from": "user@example.com",
  "to": "smart+topic@yourdomain.com",
  "subject": "Question about...",
  "body": "Your question here"
}
```

### Outgoing Email (SMTP)

Configure in `backend/.env`:

```bash
MAILER_DSN=smtp://user:pass@smtp.example.com:587
```

> **AWS SES (and other strict SMTP servers):** SES hard-closes connections
> that are idle for more than ~10 seconds, while Symfony Mailer keeps the
> connection open between sends and only health-checks it after 100 seconds.
> In long-running processes (FrankenPHP workers, messenger consumers) a reused
> stale connection fails with `451 4.4.2 Timeout waiting for data from client`.
> Append `?ping_threshold=9` to the DSN so the transport pings (and reconnects)
> before reusing a connection that has been idle for 9+ seconds:
>
> ```bash
> MAILER_DSN=smtp://USER:PASS@email-smtp.eu-west-1.amazonaws.com:587?ping_threshold=9
> ```
>
> Independently of this setting, the backend retries a transiently failed
> send once on a fresh connection (permanent 5xx rejections are not retried).

---

## API Reference

### Webhook Endpoint

```bash
POST /api/v1/webhooks/email
X-API-Key: sk_your_api_key

{
  "from": "sender@example.com",
  "to": "smart+topic@synaplan.net",
  "subject": "Email subject",
  "body": "Email body text",
  "html": "<p>Optional HTML body</p>",
  "attachments": []
}
```

### Response

```json
{
  "success": true,
  "response": "AI generated response text",
  "chat_id": 123,
  "context": "topic"
}
```

---

## Rate Limits

Unified across all channels:

| Level | Messages | Reset |
|-------|----------|-------|
| ANONYMOUS | 10 | Daily |
| NEW | 20 | Daily |
| PRO | 100 | Daily |
| TEAM | 500 | Daily |
| BUSINESS | Unlimited | - |

---

## Best Practices

1. **Use keywords** - Organize conversations with `+keyword`
2. **Register users** - Higher limits, persistent history
3. **Monitor spam** - Review blacklisted addresses periodically
4. **Set up SMTP** - Enable outgoing responses
