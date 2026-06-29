# Mail Handler Local Testing

Optional dev tooling to exercise the full mail-handler pipeline locally:

**Greenmail** (IMAP inbox) → **Synaplan handler** (AI routing) → **MailHog** (catch-all for forwards)

Not part of the main `docker-compose.yml`. MailHog is already in the main stack.

---

## Prerequisites

1. Main stack running: `docker compose up -d` (from **repo root** — Docker Compose project name defaults to the folder name `synaplan`)
2. A working **CHAT** model for the handler owner (user ID 1 / admin by default in `setup-local-mail-handler.php`)
3. System prompt `tools:mailhandler` seeded (`make -C backend seed` or normal dev setup)

> **Network:** Greenmail joins the existing stack network `synaplan_synaplan-network`. If you use a custom `COMPOSE_PROJECT_NAME`, start Greenmail with the same project name or adjust the `external.name` in `docker-compose.greenmail.yml`.

---

## Quick start

```bash
make -C _devextras/mail-handler-test up
make -C _devextras/mail-handler-test setup
make -C _devextras/mail-handler-test flow TYPE=support
# Open http://localhost:8025 — forwarded mail should appear
```

| Command | Purpose |
|---------|---------|
| `make up` | Start Greenmail |
| `make down` | Stop Greenmail |
| `make setup` | Create/update handler in DB (**also visible in UI** at `/channels/email`) |
| `make send TYPE=support` | Inject test mail via SMTP |
| `make process` | Run `app:process-mail-handlers` once |
| `make watch` | Optional: poll handlers in a loop (`INTERVAL=10`, default) |
| `make flow TYPE=hr` | Send preset + process (one-shot) |

Use `watch` only when you want hands-free polling (e.g. testing with Thunderbird). For quick checks, `make flow` or `make process` is enough.

Presets: `sales`, `support`, `hr`, `spam`

---

## Architecture

```
send-test-email.php (or any SMTP client)
    │  smtp://greenmail:3025 (from backend) or localhost:3025 (from host)
    ▼
Greenmail — mailbox testhandler@test.local
    │  IMAP greenmail:3143
    ▼
Synaplan Mail Handler — AI routes to department
    │  SMTP mailhog:1025
    ▼
MailHog — http://localhost:8025
```

**Port rule:** use **3025** to deliver mail *into* Greenmail. Port **1025** is MailHog (handler forwards only — do not send customer test mail there).

**Hostname rule:**

| Client runs on | IMAP/SMTP host |
|----------------|----------------|
| Host (desktop mail client) | `localhost` |
| Backend container | `greenmail` / `mailhog` |

---

## Greenmail (Docker)

**Image:** `greenmail/standalone`

**Users** (IMAP login = local part only):

| Login | Password | Address |
|-------|----------|---------|
| `testhandler` | `testpass` | `testhandler@test.local` |
| `customer` | `customer123` | `customer@test.local` |
| `admin` | `adminpass` | `admin@synaplan.com` |

**Ports:** SMTP `3025`, IMAP `3143` (host and container)

---

## Handler configuration

`make setup` writes the same data as the UI. Default handler: **Local Mail Handler (Greenmail)** for user **1** (`admin@synaplan.com`).

| IMAP (from backend) | Value |
|---------------------|-------|
| Server | `greenmail` |
| Port | `3143` |
| Security | None |
| User / pass | `testhandler` / `testpass` |
| Filter | New emails only |

| SMTP forward | Value |
|--------------|-------|
| Server | `mailhog` |
| Port | `1025` |
| Security | None |
| From | `noreply@test.local` |
| User / pass | any non-empty (MailHog ignores auth) |

**Departments (setup script defaults):**

| Email | Rules | Default |
|-------|-------|---------|
| `sales@test.local` | Sales, orders, pricing, quotes | No |
| `support@test.local` | Support, bugs, issues, refunds | **Yes** |
| `hr@test.local` | Jobs, hiring, HR | No |

---

## Sending test mail

**Recommended:** `make send TYPE=support` — no extra software, avoids IMAP read/unread pitfalls.

### Desktop mail client (optional)

Use Thunderbird, Evolution, etc. only if you want a realistic “customer sends mail” workflow.

**Simulate a customer writing to the handler:**

| Setting | Value |
|---------|-------|
| Account address | `customer@test.local` |
| IMAP | `localhost:3143`, security **None**, user **`customer`**, pass `customer123` |
| SMTP | `localhost:3025`, security **None**, auth **None** |
| Send **To** | `testhandler@test.local` |
| After send | `make process` or `make watch` |

**Where to see the result:** forwarded mail appears in **MailHog** (http://localhost:8025), not in the customer account inbox.

> **IMAP username:** Greenmail expects the **local part** only (`customer`, `testhandler`) — not `customer@test.local`.

> **UNSEEN filter:** Opening the handler inbox in a client marks mail as read; the handler then skips it. Prefer `make send`, send fresh mail, or mark unread before `make process`.

---

## AI routing

Routing uses:

1. System prompt **`tools:mailhandler`** (English; semantic intent, not keyword matching)
2. Handler owner's **default CHAT model** (`ModelConfigService::getDefaultModel('CHAT', userId)`)
3. Department **rules** text from the handler config

The model must return **exactly one department email** from the list (or `DISCARD`). On failure, invalid output, or missing model → **default department**.

**Why “Rechnung” may hit default:** Rules and prompt are English-oriented; German-only wording may not align strongly enough with `invoice`/`billing` rules. Use clear intent in subject/body (or English keywords matching your department rules). Presets in `send-test-email.php` are tuned for the default departments.

Check which model ran: handler owner → usage/activity logs (`EMAIL_ROUTING`) or backend logs during `make process`.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| MailHog empty | Handler not processed | `make process` |
| Handler: no new emails | Mail already SEEN in IMAP | New mail via `make send`; don't open in client first |
| Wrong department | AI uncertainty / language mismatch | Align mail text with department rules; check default dept |
| TLS error | Security not None | Set IMAP/SMTP security to None locally |
| Mail bypasses handler | SMTP to port 1025 | Send to Greenmail SMTP **3025** |
| IMAP login failed | Full email used as username | Use local part only (`customer`, `testhandler`) |
| `greenmail` in desktop client | Client runs on host | Use `localhost`, not `greenmail` |

---

## CI

**Greenmail in CI is not recommended** for this pipeline:

- Routing requires a **real AI call** (handler owner's CHAT model + provider keys)
- CI typically has no Ollama/OpenAI/etc. configured for integration tests
- Unit tests already cover routing logic (`InboundEmailHandlerServiceTest`)

Greenmail adds value for **manual local E2E** only. Keep it in `_devextras/` as optional dev tooling.

---

## Files

| File | Purpose |
|------|---------|
| `docker-compose.greenmail.yml` | Greenmail service + script mount into backend |
| `setup-local-mail-handler.php` | Idempotent handler setup (DB + UI) |
| `send-test-email.php` | SMTP test mail presets |
| `Makefile` | Convenience commands |
| `README.md` | This guide |

---

## Tear down

```bash
make -C _devextras/mail-handler-test down   # stops Greenmail only (not the main stack)
```

Delete handler in UI (`/channels/email`) or re-run `make setup` after changes. Greenmail mail is in-memory — restart clears mailboxes.
