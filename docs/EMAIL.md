> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 4: Channels, Gateways & Integrations (8/11)**  
> **← Previous:** [4.7 WhatsApp Business Channel](WHATSAPP.md) · **Next:** [4.9 Live Voice (Full-Duplex Speech) →](LIVE_VOICE.md)

# Email Channel, IMAP & SMTP Configuration

> **In this guide:** How Synaplan's four email capabilities work together—inbound email webhooks (`smart+keyword@` topic threads), IMAP mailbox handlers, live read-only mailbox search (`email_search`), and outgoing SMTP configuration (`MAILER_DSN` percent-encoding and AWS SES connection keepalive).

---

## How Email Works in Synaplan

Synaplan supports four distinct email flows:

| Flow | Direction | How It Is Configured | Purpose |
| ---- | --------- | -------------------- | ------- |
| **1. Smart Email Webhook (`smart+keyword@`)** | Inbound + Reply | `POST /api/v1/webhooks/email` (or `SYNAPLAN_SCHEDULER_SMART_MAILBOX=1` polling `GMAIL_USERNAME`) | Users email `smart@` or `smart+project@`; each `+keyword` maps to a persistent conversation thread and replies by email. |
| **2. User IMAP Mail Handlers** | Inbound routing | **Manage → Channels → Email** (`InboundEmailHandler`, polled by the scheduler every minute) | Connects a user's or department's IMAP/POP3 mailbox for automated AI triage and routing. |
| **3. Live Mailbox Search (`email_search`)** | Read-only pull during chat | Automatic once an IMAP handler or [Microsoft 365 connection](CONNECTIONS.md#microsoft-365) exists | When you ask *"summarize the latest email from X about Y"*, the multi-task planner searches IMAP and M365 live without storing your inbox on the server. |
| **4. Outgoing SMTP (`MAILER_DSN`)** | Outbound | `MAILER_DSN` in `backend/.env` or `deploy/.env` | Sends verification emails, password resets, email-channel replies, watched-page diffs, and Saved Task deliveries. |

---

## Smart Addressing (`smart+keyword@`)

When a mail relay or the smart-mailbox poller forwards messages to Synaplan, the recipient address controls the conversation context (`SmartEmailHelper` matches `smart@synaplan.net` / `smart+keyword@synaplan.net` and legacy `synaplan.com`; custom recipient domains are not parsed for `+keyword` by the built-in helper):

| Recipient Address | Chat Context Created | Typical Use |
| ----------------- | -------------------- | ----------- |
| `smart@synaplan.net` | General email chat | Quick one-off questions |
| `smart+project@synaplan.net` | Dedicated `project` thread | Ongoing project discussions |
| `smart+support@synaplan.net` | Dedicated `support` thread | Customer or team support threads |
| `smart+research@synaplan.net` | Dedicated `research` thread | Long-running research topics |

### Intake & Threading Pipeline

```
Incoming Email → InboundEmailIntake (MIME parse, +keyword extraction, idempotency check)
  ├─ Known sender (matches BUSER.BMAIL) → Uses the account's subscription rate limits
  ├─ Unknown sender                     → Creates/uses an ANONYMOUS user (10 messages/day, max 10/hr spam guard)
  └─ Blacklisted / over limit           → Refused (sender is notified once)
      ↓
EmailConversation (stores message & attachments; audio attachment requests a spoken reply)
      ↓
MessageProcessor (classifies and runs AI turn with thread history)
      ↓
EmailReply (sends reply in the same email thread with generated files & doc links)
```

---

## Setup (Self-Hosted)

### Incoming Email Webhook

Configure your mail server or relay to forward inbound emails to Synaplan's webhook:

```bash
POST /api/v1/webhooks/email
X-API-Key: sk_your_api_key
Content-Type: application/json

{
  "from": "sender@example.com",
  "to": "smart+topic@synaplan.net",
  "subject": "Question about Q3 numbers",
  "body": "Plain text email body",
  "html": "<p>Optional HTML body</p>",
  "attachments": []
}
```

**Response:**

```json
{
  "success": true,
  "response": "AI generated response text",
  "chat_id": 123,
  "context": "topic"
}
```

### Outgoing Email (SMTP)

Configure `MAILER_DSN` in `backend/.env` (or `deploy/.env` in production):

```bash
MAILER_DSN=smtp://user:pass@smtp.example.com:587
```

> **Percent-encode special characters in the username and password.** Because `MAILER_DSN` is parsed as a URL, characters with URL syntax meaning will truncate the password or break host parsing. Replace special characters with their percent codes and leave the rest untouched:
>
> | Character | `$` | `@` | `:` | `/` | `#` | `?` | `%` |
> | --------- | --- | --- | --- | --- | --- | --- | --- |
> | **Encoded** | `%24` | `%40` | `%3A` | `%2F` | `%23` | `%3F` | `%25` |
>
> Synaplan decodes them before authenticating with your SMTP server:
>
> ```bash
> # Example for SMTP password: pa$$:word
> MAILER_DSN=smtp://user:pa%24%24%3Aword@smtp.example.com:587
> ```
>
> Percent-encoding is also mandatory in `deploy/.env`: Docker Compose strips everything from an unescaped `$` onward, and `validate-release.sh` refuses raw `$` values so a deployment never starts with a corrupted SMTP password.

> **AWS SES (and other strict SMTP servers):** SES closes idle connections after ~10 seconds, whereas Symfony Mailer reuses open connections across worker turns and only pings after 100 seconds by default—causing `451 4.4.2 Timeout waiting for data from client`. Append `?ping_threshold=9` so the transport health-checks connections idle for 9+ seconds:
>
> ```bash
> MAILER_DSN=smtp://USER:PASS@email-smtp.eu-west-1.amazonaws.com:587?ping_threshold=9
> ```
>
> In addition, the backend automatically retries a transiently failed send once on a fresh connection (permanent `5xx` rejections are not retried).

---

## Rate Limits Across Channels

Rate limits are shared across Email, WhatsApp, and Web:

| User Level | Messages | Reset Window |
| ---------- | -------- | ------------ |
| `ANONYMOUS` | 10 | Daily (plus max 10 emails/hour spam guard) |
| `NEW` | 20 | Daily |
| `PRO` | 100 | Daily |
| `TEAM` | 500 | Daily |
| `BUSINESS` | Unlimited | — |

---

> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 4: Channels, Gateways & Integrations (8/11)**  
> **← Previous:** [4.7 WhatsApp Business Channel](WHATSAPP.md) · **Next:** [4.9 Live Voice (Full-Duplex Speech) →](LIVE_VOICE.md)
