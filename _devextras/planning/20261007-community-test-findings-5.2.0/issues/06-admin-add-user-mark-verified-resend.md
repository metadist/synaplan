<!-- title: Admin: Add user, Mark verified and Resend verification in People (the provisioning endpoint exists, the UI never calls it) -->
<!-- type: Feature -->
<!-- labels: prio:1, area:admin, area:auth -->
<!-- issue-type: Feature -->

## Summary
Give administrators three actions in People → Users: an "Add user" form (creates a ready-to-use, verified account), "Mark verified" and "Resend verification email" on each unverified row.

---

## Problem / Motivation
The Users list shows a verified mark for some accounts, but there is no way to verify an unverified account or to resend the mail. The backend already has `POST /api/v1/admin/users`, which creates verified users ("ready-to-use users without the email verification roundtrip"), but the admin frontend never calls it. On a default self-host install the mail transport is `null://null`, so every self-registered user is stuck at "email not verified" forever and the admin has no way out (F13, F14). Open WebUI lets the admin add or approve users in one click.

---

## Goal
An admin on an instance without mail can create accounts and unblock stuck sign-ups from the People page, and sees on each row whether the account is verified.

---

## Acceptance criteria
- [ ] People → Users has a primary "Add user" action: email, display name, level, and a one-time password the admin types (shown once in the dialog, never emailed, never written to the audit log). "Send a set-password link" appears only when `mailerConfigured` is true. Submits to the existing `POST /api/v1/admin/users`. The new row appears without reload.
- [ ] Each unverified row offers "Mark verified" behind a confirm dialog ("This person can sign in without opening the email.") and, only when mail is configured, "Resend verification email". Neither action is a silent one-click.
- [ ] Both actions write an audit row (People → Audit) with who and when.
- [ ] The verified / unverified mark has a tooltip and a legend (F23).
- [ ] All five locales; light and dark; 320 px.

---

## Notes
- Findings: F14 (and the unblocking half of F13) — community test round on 5.2.0.
- Verified in code: `backend/src/Controller/AdminUserProvisioningController.php` serves `POST /api/v1/admin/users`; `frontend/src/services/api/adminApi.ts` only calls `/admin/users/search`, list, `/{id}/level` and delete. A "mark verified" endpoint has to be added (small: set the verified flag, audit row, OpenAPI annotations → `make -C frontend generate-schemas`).
- Mail availability: `MailerConfig::isConfigured()` is already exposed to the frontend as `mailerConfigured` by `ConfigController` — use it to hide "Resend".
- Journey (U10): admin opens People → Add user → fills the form → the row appears → signs in as that user in a private window → works; then a self-registered unverified user → Mark verified → that person signs in.

---

## Screenshots/Logs
—
