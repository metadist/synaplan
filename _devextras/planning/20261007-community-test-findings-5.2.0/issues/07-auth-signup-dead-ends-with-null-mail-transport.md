<!-- title: Auth: sign-up with the null mail transport dead-ends silently — warn the admin, log honestly, tell the user -->
<!-- type: Bug -->
<!-- labels: prio:1, area:auth, area:setup, area:mail-handler -->
<!-- issue-type: Bug -->

## Problem
`deploy/selfhost.env.example` ships `MAILER_DSN=null://null` and `APP_SENDER_EMAIL=` while registration requires email verification. Registration succeeds, the backend logs "Verification email sent", the null transport discards the message, and the new user is stuck at "email not verified" with no explanation. The log gives the admin no hint that nothing was delivered.

---

## Expected
When registration is on and mail is not configured, the admin sees a warning where registration is switched on (and on the system status page), the log says the mail was discarded, and the sign-up screen tells the user what happens next (an admin has to verify the account) instead of promising an email.

## Actual
1. Default self-host install, user registers.
2. UI: "Check your inbox". Log: `Verification email sent`.
3. Nothing arrives; login refused with "email not verified"; no admin action exists (separate issue: Add user / Mark verified).

---

## Steps to reproduce
1. Deploy with `deploy/selfhost.env.example` unchanged (`MAILER_DSN=null://null`).
2. Register a new account.
3. Read the backend log and try to log in.

---

## Notes
- Findings: F13 — community test round on 5.2.0; comparison §"Account creation".
- Verified in code: `MailerConfig::isConfigured()` (`backend/src/Service/MailerConfig.php`) already treats `null://null` as "nothing is ever delivered" and is used by `SetupController`, `ConfigController` (`mailerConfigured`) and `PlatformCapabilityInventory` — but not by registration. `InternalEmailService` (`backend/src/Service/InternalEmailService.php` ~line 89) logs "Verification email sent" unconditionally; `AuthController` (~line 1028) logs "Verification email sent successfully".
- The report also notes approval notifications default to email (F35) — the same `mailerConfigured` check should default those to in-app.

Fix direction:
1. `InternalEmailService` logs at `warning` "Verification email NOT delivered: mail transport is null://null" when `!isConfigured()`, and returns a distinct result so callers can branch.
2. Admin → Sign-in & registration: when `REGISTRATION_ENABLED` is on and mail is not configured, show an inline warning with the two ways out (configure `MAILER_DSN`; or verify users by hand in People).
3. Sign-up success screen: when mail is not configured, say "An administrator has to confirm your account before you can sign in." instead of "Check your inbox" (U8 — say what did and did not happen).
4. Default approval notifications to in-app when mail is not configured.
5. Follow-up to decide separately: an explicit "admin approves sign-ups" mode (pending screen) for no-mail instances, as Open WebUI has.

Journey (U10): fresh install, no mail → register → screen says an admin must confirm → admin sees the warning in Sign-in & registration → marks the user verified (issue "Add user / Mark verified") → user signs in.

Verification:
1. Log line at warning level names the null transport.
2. Warning visible in admin settings and on the status page; absent once `MAILER_DSN` points to MailHog in dev.
3. Sign-up copy differs between configured and unconfigured mail in all five locales.

---

## Screenshots/Logs
Log: `Verification email sent` (5.2.0) with `MAILER_DSN=null://null`.
