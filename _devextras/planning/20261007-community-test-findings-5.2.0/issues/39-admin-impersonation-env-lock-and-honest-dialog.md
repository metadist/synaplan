<!-- title: Admin: "View as user" cannot be locked off by the operator, any admin can impersonate other admins, and the confirm dialog does not say the admin will see private chats and files -->
<!-- type: Feature -->
<!-- labels: prio:2, area:admin, security -->
<!-- issue-type: Feature -->

## Summary
Impersonation gets an environment lock (`IAM_ADMIN_IMPERSONATION` set by env ⇒ locked in the UI, same pattern as `REGISTRATION_ENABLED`), an option to forbid impersonating other admins, a notification to an admin who was impersonated, and a confirm dialog that states plainly what the admin will see.

---

## Problem / Motivation
Any admin can "View as user" any account, including other admins, and then sees that user's chats, Library and generated files. The default policy "audited" writes start and stop rows to People → Audit. It can be set to "disabled" in System configuration, but that is a database setting with no environment lock, so any admin can switch it back on. The confirm dialog says actions are attributed to the account but not that private chats and files become readable (F29). Outside impersonation no admin screen shows another user's chat content — that is good and should stay.

---

## Goal
An operator can decide at deployment time that impersonation is off and no admin can undo it in the UI; when it is on, the person being impersonated and the impersonating admin both know exactly what it means.

---

## Acceptance criteria
- [ ] `IAM_ADMIN_IMPERSONATION` can be set by environment; when set, System configuration shows it locked with the existing env-lock message and the API refuses changes.
- [ ] New option value `audited-no-admins` (or a second setting) that forbids impersonating accounts with the ADMIN level; default stays `audited`.
- [ ] The confirm dialog reads: "You will see this person's chats, files and generated content as they see them. Every action is attributed to their account and recorded in the audit log." (five locales).
- [ ] An admin who was impersonated sees an in-app notice on next sign-in ("<name> viewed your account on <date>") — and an email when mail is configured.
- [ ] Audit rows include the reason text if the dialog asks for one (optional field).

---

## Notes
- Findings: F29 — community test round on 5.2.0.
- Verified in code: `IAM_ADMIN_IMPERSONATION` is a database-backed select (`audited` / `disabled`) in `backend/src/Service/Admin/SystemConfigService.php` (~line 1620); the `envOverride` mechanism exists for `REGISTRATION_ENABLED` and `GUEST_CHAT_ENABLED` (~line 475) and can be extended. Service: `backend/src/Service/ImpersonationService.php`, controller `AdminImpersonationController.php`, banner `frontend/src/components/ImpersonationBanner.vue`.
- Journey (U10): set the env to `disabled` → System configuration shows the lock → "View as user" is absent from People rows (U11) → unset → set `audited-no-admins` → the action is absent on admin rows, present on others → impersonate a member → the member sees the notice next sign-in.

---

## Screenshots/Logs
—
