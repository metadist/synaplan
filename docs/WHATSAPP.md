# WhatsApp Integration

Connect Synaplan to WhatsApp Business API for AI-powered messaging.

## Overview

- Bidirectional messaging (send & receive)
- Multi-phone number support (up to 20)
- Media handling (images, audio, video, documents)
- Audio transcription via Whisper
- Anonymous usage (no signup required)

---

## Setup

### 1. Create WhatsApp Business Account

1. Go to [Meta Business Suite](https://business.facebook.com/)
2. Create or select a business account
3. Add WhatsApp to your business
4. Get your **Access Token** from the API settings

### 2. Configure Environment

Add to `backend/.env`:

```bash
WHATSAPP_ENABLED=true
WHATSAPP_ACCESS_TOKEN=your_meta_access_token
WHATSAPP_WEBHOOK_VERIFY_TOKEN=your_custom_verify_token
```

### 3. Configure Webhook in Meta

In Meta Business Settings → WhatsApp → Configuration:

| Setting | Value |
|---------|-------|
| Callback URL | `https://your-domain.com/api/v1/webhooks/whatsapp` |
| Verify Token | Same as `WHATSAPP_WEBHOOK_VERIFY_TOKEN` |
| Subscribe to | `messages` |

---

## Multi-Number Support

**Zero configuration needed!** The system is fully dynamic:

- Incoming messages contain `phone_number_id` in the webhook payload
- Responses automatically route to the originating number
- Add/remove numbers in Meta Portal—works immediately

### How It Works

```
User → Number A → Synaplan → AI Response → Number A → User
User → Number B → Synaplan → AI Response → Number B → User
```

---

## User Tiers

| Tier | Messages | Images | Videos | How to Get |
|------|----------|--------|--------|------------|
| **Anonymous** | 10 | 2 | 0 | Just message! |
| **Verified** | 50 | 5 | 2 | Verify phone in app |
| **PRO/TEAM/BUSINESS** | Subscription limits | Full access | Full access | Subscribe |

### Anonymous Usage

No verification required. Users can immediately chat:

1. User sends WhatsApp message
2. System creates anonymous account
3. AI responds instantly
4. User can optionally verify phone for higher limits

### Phone Verification (Optional)

1. User enters phone in web interface
2. System sends 6-digit code via WhatsApp
3. User confirms code
4. Higher rate limits unlocked

---

## Supported Features

| Feature | Status |
|---------|--------|
| Text messages | ✅ Send & receive |
| Images | ✅ Send & receive |
| Audio | ✅ Receive + transcription |
| Video | ✅ Receive |
| Documents | ✅ Receive |
| Reactions | ✅ |
| Status tracking | ✅ |

---

## Message Flow

```
WhatsApp User
    ↓
Meta Webhook → /api/v1/webhooks/whatsapp
    ↓
Message Entity
    ↓
PreProcessor (files, audio transcription)
    ↓
Classifier (sorting, tool detection)
    ↓
InferenceRouter
    ↓
AI Handler (Chat/RAG/Tools)
    ↓
Response → WhatsApp
```

---

## Troubleshooting

### Webhook not receiving messages

1. Check webhook URL is publicly accessible (HTTPS)
2. Verify token matches in Meta and `.env`
3. Check subscription includes `messages`

### Messages not sending

1. Verify access token is valid
2. Check phone number is approved in Meta
3. Review logs: `docker compose logs -f backend | grep WhatsApp`

### Audio not transcribing

1. Ensure Whisper is enabled
2. Check audio format is supported
3. Verify Whisper models are downloaded

---

## API Reference

```bash
# Webhook endpoint (called by Meta)
POST /api/v1/webhooks/whatsapp

# Webhook verification (called by Meta)
GET /api/v1/webhooks/whatsapp?hub.verify_token=...
```

---

## Security Notes

- Access token is never exposed to clients
- Webhook verify token prevents spoofing
- Phone numbers are hashed for privacy
- Rate limiting prevents abuse
