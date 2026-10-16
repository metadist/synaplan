<!-- title: Chat: the new-chat screen shows store promotions but not the active model, assistant, tools or knowledge folder; let admins hide the promotions -->
<!-- type: Feature -->
<!-- labels: prio:2, area:chat, area:admin -->
<!-- issue-type: Feature -->

> The knowledge-folder chip shipped in #2380. The assistant pin and the banner button shipped in #2383 (issue 20). This draft still owns the empty-chat heading, the tools summary, an Assistants section on the model chip, and the promotion toggles.

## Summary
The empty chat names the active model and assistant in its heading, shows the configured tools and the active knowledge folder as chips around the composer, and lets the admin hide the App Store / Google Play / GitHub cards and the "Embed AI Chat on Your Website" promotion.

---

## Problem / Motivation
The empty chat shows three cards: one explaining the model and assistant switch, plus App Store, Google Play and GitHub promotions; an "Embed AI Chat on Your Website" promotion also appears above the composer after replies; the heading does not name the active model (F17). The model chip in the composer is a 5.2.0 improvement, but configured tools are still hard to discover (F7). The composer does not show which knowledge folder is active (F40). On a company instance the store promotions are noise.

---

## Goal
A person opening a new chat knows in one glance which model and assistant will answer, which tools can run, and which folder will be searched; an operator can make the screen theirs.

---

## Acceptance criteria
- [ ] Heading: "<Assistant name>" when pinned, else "<Model name>"; the card that explains switching stays.
- [ ] Composer chips: model (exists), assistant section on that chip (own + shared, "None" — the pin itself shipped in #2383), tools summary ("3 tools · Web lookup on"). The knowledge-folder chip with × shipped in #2380; do not add a second one.
- [ ] Admin → Branding (or System configuration): "Show app and GitHub cards on the new-chat screen" and "Show the website-widget promotion" toggles, default on for the hosted product, off in open-source mode if the product owner agrees — decide in the PR.
- [ ] Flag off ⇒ cards absent, no empty slot (U11).
- [ ] All five locales; widget unaffected.

---

## Notes
- Findings: F7, F17, F40 (chip) — community test round on 5.2.0.
- Frontend: the empty-chat view, `ChatInput.vue` chip row; config via runtime config (`useConfigStore()`), not `VITE_*`.
- Journey (U10): new chat → heading names the model → pick a knowledge folder under + → chip appears → × → chip gone → admin turns the store cards off → reload → cards gone.

---

## Screenshots/Logs
—
