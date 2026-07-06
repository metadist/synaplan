# Smart Email local testing

Test `smart@synaplan.com` via **MailHog** (SMTP **1025**). Separate from [mail-handler-test](../mail-handler-test/) (Greenmail **3025**).

## Prerequisites

1. Main stack running: `docker compose up -d` from **repo root** (Docker project name defaults to `synaplan`)
2. Seed/dev data loaded — sender `admin@synaplan.com` must exist (default fixtures)
3. `GMAIL_USERNAME` and `GMAIL_PASSWORD` **empty** in `.env` (otherwise Synaplan reads real Gmail instead of MailHog)
4. A configured **CHAT** model (and **TEXT2PIC** if testing image generation)

Commands: `make -C _devextras/smart-email-test …` from repo root (or `make -C ../smart-email-test …` from inside `_devextras/`).

## Quick start

```bash
# Terminal 1: auto-process incoming smart@ mails
make -C _devextras/smart-email-test watch-smart-email

# Terminal 2 or Thunderbird: send to smart@synaplan.com via localhost:1025
make -C _devextras/smart-email-test send BODY="Create an image of a red cat."
```

Responses appear in **MailHog**: http://localhost:8025 (look for `Re: …`). Processed **inbound** `smart@` mails are removed automatically (prod-like). AI replies (`Re:` to `admin@…`) stay in MailHog.

```bash
# Debug: keep inbound mails in MailHog (re-polls same mail; idempotency skips AI)
make -C _devextras/smart-email-test watch-smart-email KEEP=1
```

## Architecture

```
Thunderbird / send-smart-email.php
    │  smtp://localhost:1025 (host) or smtp://mailhog:1025 (backend)
    ▼
MailHog — captures all mail
    │  app:process-emails (polls MailHog API)
    ▼
Synaplan webhook — only smart@synaplan.com / .net
    │  AI pipeline + optional image generation
    ▼
MailHog — reply to sender (Re: … at http://localhost:8025)
Synaplan UI — chat "Email Conversation"
```

**Port rule:** Smart Email uses MailHog **1025**. Do not send to Greenmail **3025** (that is [mail-handler-test](../mail-handler-test/)).

**Address rule:** Only mail **to** `smart@synaplan.com`, `smart@synaplan.net`, or `smart+keyword@…` is processed. Replies to `admin@…` in MailHog are skipped on the next poll (by design).

## Desktop mail client (optional)

**Recommended:** `make send` (see Quick start). Same workflow works with any SMTP client on `localhost:1025`.

If you also use [mail-handler-test](../mail-handler-test/), configure **two outgoing SMTP servers** in your client — same host, different ports:

| Outgoing server (example) | Port | Purpose |
|---------------------------|------|---------|
| Synaplan MailHog | **1025** | Smart Email → `smart@synaplan.com` |
| Greenmail Mail Handler | **3025** | Mail handler tests only |

**Thunderbird account for Smart Email** (`admin@synaplan.com`):

| Setting | Value |
|---------|-------|
| Incoming IMAP | `localhost:3143`, user **`admin`**, pass `adminpass` (Greenmail — needed so Thunderbird can create the account; replies do **not** arrive here) |
| Outgoing SMTP | `localhost:1025`, security **None**, auth **None** (MailHog) |
| Send **From** | `admin@synaplan.com` (fixture user) |
| Send **To** | `smart@synaplan.com` |
| Processing | `make watch-smart-email` in another terminal |

**Where to read replies:** MailHog http://localhost:8025 (look for `Re: …`) and Synaplan UI → chat **Email Conversation**. Not in the Thunderbird inbox.

**Tips:** Compose in **plain text** (HTML/multipart may show raw MIME in the UI). If Thunderbird prompts for a localhost password, clear saved credentials for `localhost` and confirm the outgoing server is port **1025** without auth.

## Make targets

| Target | Description |
|--------|-------------|
| `watch-smart-email` | Poll and process (default every 10s); inbound `smart@` removed after success |
| `process` | Process once |
| `send` | Inject test mail via MailHog SMTP |
| `KEEP=1` | Optional: retain inbound mails in MailHog (debug only) |

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| No reply in MailHog | `process-emails` not running | `make watch-smart-email` or `make process` |
| Raw MIME in chat/UI | Thunderbird sent HTML multipart | Compose as plain text (Ctrl+Shift+O in TB) |
| Log shows `3/10` every poll | Old mails in MailHog + `KEEP=1` | Default watch deletes processed inbound; or clear MailHog UI |
| Mail ignored | Wrong recipient | **To** must be `smart@synaplan.com` or `.net` |
| Reads real Gmail | `GMAIL_*` set in `.env` | Leave empty for local MailHog |
| Sent to wrong port | Used Greenmail 3025 | Smart Email = MailHog **1025** only |
| TB asks for localhost password | Wrong/cached SMTP credentials | Outgoing server = **1025**, no auth; clear saved passwords |

Idempotency: re-processing the same inbound mail does **not** call the AI again — only a quick duplicate check.

## Files

| File | Purpose |
|------|---------|
| `docker-compose.yml` | Mount scripts into backend container |
| `send-smart-email.php` | Inject test mail via MailHog SMTP |
| `Makefile` | `watch-smart-email`, `process`, `send` |
| `README.md` | This guide |
