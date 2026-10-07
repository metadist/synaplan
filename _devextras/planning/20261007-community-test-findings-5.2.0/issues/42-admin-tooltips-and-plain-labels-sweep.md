<!-- title: Admin: copy and tooltip sweep — "Manual" pills, "Free OK", "Unique key: BSERVICE + BTAG + BPROVID", "Yours today", env-locked settings, group role dropdown, Routing's "Open Task Prompts" link -->
<!-- type: Bug -->
<!-- labels: prio:3, area:admin -->
<!-- issue-type: Bug -->

## Problem
Several admin controls read like developer output or need a one-line explanation on hover. Bundled because each is copy or a tooltip, no logic.

---

## Expected
Every admin control has plain-language labels, a hover explanation where the label alone is not enough, and no database column names.

## Actual
1. People → Groups: the grey "Manual" pills look like buttons but are labels meaning the group or membership was created by hand; no tooltip (F22).
2. People → Groups: a member's role (Member / Manager) can only be chosen when adding the person; changing it means remove and re-add (F22).
3. Edit Models: "Free OK" and "Unique key: BSERVICE + BTAG + BPROVID" show database names to admins (F23).
4. Web search: "Allow users to choose their provider" has no explanation; Sign-in & registration: the empty circle vs green check has no legend (F23).
5. Usage meter: "Yours today" is unclear (F8 — currency logic is a separate issue).
6. Settings set by environment (`REGISTRATION_ENABLED`, `GUEST_CHAT_ENABLED`) show as locked; the message is clear but does not say where to change them for this deployment (F12).
7. Routing page: the "Open Task Prompts" link goes to the Assistants list (F41).

---

## Steps to reproduce
Open each screen named above.

---

## Notes
- Findings: F22, F23, F8 (label), F12, F41 (link) — community test round on 5.2.0.
- Pattern to copy: the rail icons already have good tooltips; "Manual" pills must not use an interactive utility (`.pill`) for a static badge (AGENTS.md red flag).
- Env-lock copy: `SystemConfigService` knows the key is env-set (`envOverride`); the message can name the variable and point at `docs/CONFIGURATION.md` ("Set `REGISTRATION_ENABLED` in your deployment's environment, then restart the backend").

Fix direction: tooltips via the shared tooltip component; "Free OK" → "Show even when free" with the existing tooltip text; "Unique key …" → "Identified by provider, model id and tag"; "Yours today" → "Today's usage"; role select on each member row (needs a small `PATCH` endpoint if missing); fix the link target; plain-text style for kind labels; five locales.

Verification:
1. No `B[A-Z]+` column names visible in admin screens (grep the locales for `BSERVICE`, `BTAG`, `BPROVID`).
2. Role change without remove / re-add; audit row written.
3. Locale parity test green.

---

## Screenshots/Logs
Strings: "Manual"; "Free OK"; "Unique key: BSERVICE + BTAG + BPROVID"; "Yours today".
