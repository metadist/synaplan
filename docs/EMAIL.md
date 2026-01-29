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
