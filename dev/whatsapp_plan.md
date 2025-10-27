### WhatsApp Webhook Migration Plan

This document outlines the current state of `public/webhookwa.php`, identifies gaps from the previous app’s behavior, and lists a detailed ToDo to complete the migration and harden the integration.

---

### Current state (as implemented)
- Incoming webhook handled by `public/webhookwa.php`:
  - Verifies subscription using `hub_mode`/`hub_verify_token` → responds with challenge.
  - Parses inbound JSON (`entry` → `changes` → `value` → `messages`/`statuses`).
  - Normalizes messages via `processWAMessage()` supporting:
    - `text`, `image`, `video`, `document`, `audio`, `contacts` (contacts saved as JSON file), `reaction`.
    - Media metadata fetched with Graph GET and file downloaded via shell `curl`; `ogg` auto-transcoded to `mp3` via `ffmpeg`.
  - Maps to internal message (`BMESSAGES`) structure; resolves/creates user via `Central::getUserByPhoneNumber()`.
  - Saves receiver phone number/ID in `BWAIDS` for reply routing.
  - Rate-limits via `XSControl::isLimited()` and notifies on limits.
  - Triggers `preprocessor.php` → `aiprocessor.php` → `outprocessor.php`.
- Outbound sending handled by `public/outprocessor.php` using `waSender` (`public/inc/_wasender.php`).
- Storage uses Flysystem instance (`$GLOBALS["filesystem"]` from `_coreincludes.php`).

---

### Gaps and inconsistencies vs. desired/previous behavior
- Token/config sourcing mismatch:
  - Webhook reads WA token from `public/.keys/.watoken.txt` while the rest uses `ApiKeys::getWhatsApp()`/.env. Unify on `ApiKeys`.
- Subscription verify param names:
  - WhatsApp/Meta commonly use `hub.mode`, `hub.verify_token`, `hub.challenge` (dots). Webhook checks `hub_mode`, `hub_verify_token`. Ensure both forms are supported.
- Missing request signature verification:
  - No `X-Hub-Signature-256` verification (HMAC with App Secret). Needed for production integrity.
- Limited message types handled:
  - Not yet handling `interactive` (button/list replies), `location`, `sticker`, `contacts` enrichment into text, `referral`, `system` messages.
- Status updates not persisted:
  - `Central::handleStatus()` is a stub; delivery/read/failure events not stored or linked to `BMESSAGES`.
- Byte counting flag mismatch:
  - Webhook calls `XSControl::countBytes($inMessageArr, 'BOTH', ...)` but implementation expects `ALL`/`FILE`/`TEXT`/`SORT`. Bytes currently not counted here.
- Media download approach:
  - Uses shell `curl` instead of PHP cURL; no retry/backoff; limited error handling; potential portability issues on Windows. Consider switching to PHP cURL for consistency with `httpRequest()` or keep shell with robust checks.
- Transcoding/tooling dependencies:
  - Requires `ffmpeg` in PATH for `ogg` → `mp3`. Document and validate in runtime environment (Docker image or host).
- Directory creation & paths:
  - Uses Flysystem for `createDirectory`, then `file_put_contents` with `./up/...`. Ensure directory existence checks and permissions are consistent and race-safe.
- Language/topic continuity:
  - Conversation threading relies on last 360s messages. Confirm parity with old app’s time window and desired behavior.
- Error logging/observability:
  - `logMessage()` writes to `public/debug.log` without rotation or size caps beyond naive truncation. Standardize logging and toggle via env.
- Dev/prod switches:
  - Outbound WA sending gated by `.keys/.live.txt` in `outprocessor.php`. Replace with a clear `APP_ENV`/env flag.

---

### Detailed ToDo (migration and hardening)
1) Align configuration and secrets
- Switch webhook token sourcing to `ApiKeys::getWhatsApp()`; remove `.keys/.watoken.txt` dependency.
- Add `WHATSAPP_APP_SECRET` to `.env` and `ApiKeys` for signature verification.
- Replace `.keys/.live.txt` gate with `APP_ENV` or `WHATSAPP_SEND_ENABLED`.

2) Verification and security
- Update verification handler to accept both `hub.mode`/`hub.verify_token` and underscore variants; respond with `hub.challenge`.
- Implement `X-Hub-Signature-256` HMAC verification for POST payloads using `WHATSAPP_APP_SECRET`; reject on mismatch (configurable bypass in dev).

3) Message normalization coverage
- Add support in `processWAMessage()` for:
  - `interactive` → map button/list selections into `BTEXT` (include title/ID).
  - `location` → store lat/long/address; attach a small JSON or a formatted text.
  - `sticker` → download as media; consider ignoring or attach info.
  - `contacts` → optionally summarize key fields into `BTEXT` in addition to JSON file.
  - `referral` and `context` (`reply_to`) → link to prior `BMESSAGES` where possible.
- Validate and dedupe `caption + text` merging for media.

4) Persistence and status handling
- Implement `Central::handleStatus($status)` to upsert delivery/read statuses:
  - Create a `BMESSAGEMETA` record per status or a dedicated `BDELIVERY` table.
  - Link by provider message ID (`status.id`) to local message (`BMESSAGES.BPROVIDX`).
- Ensure `BWAIDS` insert uses prepared/escaped values and is idempotent per `BMID`.

5) Byte counting and metrics
- Change webhook call to `XSControl::countBytes($inMessageArr, 'ALL', false)` or extend `countBytes` to accept `'BOTH'`.
- Add basic counters/timers around media downloads and preprocess kicks.

6) Media download/transcoding robustness
- Option A: Replace shell `curl` in `downloadMediaFile()` with PHP cURL using existing `httpRequest` patterns; stream to file; add retries/backoff.
- Option B: Keep shell tools but:
  - Validate presence of `curl` and `ffmpeg`; fail gracefully with clear errors.
  - Check file size > 0 and MIME consistency post-download.
  - Normalize `mime_type` after `ogg`→`mp3` conversion.

7) Error handling and logging
- Wrap webhook main loop with try/catch; on exceptions write structured error logs and return 200 to avoid repeated webhooks where appropriate.
- Gate `logMessage()` by env (`APP_ENV !== 'production'`) and add max size/rotation.

8) Verification and unit tests (dev)
- Add a local JSON fixture replayer for webhook payloads (CLI or HTTP) to test all message types.
- Create minimal tests for `processWAMessage()` transformations and `downloadMediaFile()` behavior (mocked HTTP).

9) Configuration and ops documentation
- Document required env vars: `WHATSAPP_TOKEN`, `WHATSAPP_APP_SECRET`, `APP_ENV`, `BRAVE_SEARCH_API_KEY`, AI keys; and required tools (`ffmpeg`).
- Update Dockerfile/readme to install `ffmpeg` and ensure writable `public/up/`.

10) Rollout plan
- Deploy with signature verification disabled but logged in staging; confirm end-to-end flow.
- Enable signature verification in production; monitor logs/metrics.
- Remove `.keys` fallbacks once env-based config is confirmed stable.

---

### Acceptance criteria
- Webhook accepts verification with dot or underscore params and validates signatures for POST.
- All common WA message types are ingested and mapped; media saved; captions/text merged sanely.
- Message statuses are persisted and queryable per message.
- Bytes counting recorded (`FILEBYTES`, `CHATBYTES`, `SORTBYTES`) for inbound messages.
- Outbound replies choose correct media vs. text and succeed in prod; disabled in dev.
- No hard-coded `.keys` usage remains; env-driven configuration documented.
- Media downloads/transcoding are resilient with clear error logs.

---

### File-level change checklist
- `public/webhookwa.php`
  - Use `ApiKeys::getWhatsApp()`.
  - Verify query params and `X-Hub-Signature-256`.
  - Expand `processWAMessage()` types; improve error handling; return consistent schema.
  - Switch `XSControl::countBytes(..., 'BOTH', ...)` → `'ALL'`.
- `public/inc/_xscontrol.php`
  - Optionally accept `'BOTH'` as alias for `'ALL'`.
- `public/outprocessor.php`
  - Replace `.keys/.live.txt` with env flag.
- `public/inc/_wasender.php`
  - No change expected (already uses WA SDK); ensure errors are surfaced.
- `public/inc/_central.php`
  - Implement `handleStatus()` persistence.
- `public/dev/docker/*` and docs
  - Ensure `ffmpeg` present; permissions for `public/up/`.


