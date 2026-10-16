<!-- title: Docs: three different menu paths for Synaplan Desktop are in circulation — verify #2359 against the 5.3.0 rail and derive the path from one shared label -->
<!-- type: Bug -->
<!-- labels: prio:3, documentation, area:nav -->
<!-- status: shipped -->
<!-- issue-type: Bug -->

## Problem
The Desktop docs page said "Manage → Developer & devices → Desktop (/channels/desktop)" for pairing and the list of paired computers. Issues #2188 / #2326 and desktop app PR #44 settled on "Manage → Channels → Synaplan Desktop". The testers report that the 5.2.0 UI has neither: the rail reads Chats, Library, Assistants, Channels, Operate; Desktop sits under Channels → Synaplan Desktop, and Developer & devices holds only API Keys, API Documentation and Coding clients. The Desktop page lists macOS, Windows and Linux but says "There is no installer yet".

---

## Expected
Every doc, the desktop app's pairing text and the README name the path the current rail actually shows, generated or checked from one shared label so the next regrouping cannot break them again. The Desktop page says what can be installed today or hides platforms that cannot.

## Actual
1. `docs/DESKTOP.md` (after #2359, in 5.2.1): "Manage → Channels → Synaplan Desktop".
2. Rail in 5.2.0 per the testers: no "Manage" entry.
3. Desktop page: three platforms listed, "There is no installer yet".

---

## Steps to reproduce
1. Open `docs/DESKTOP.md` and the Anthropic-compatible API doc; note the paths.
2. Open the app; follow the path from the rail.

---

## Notes
- Findings: F3 — community test round on 5.2.0 (first seen on 5.0.6). Partly addressed on `main` by #2359 `fix(nav): point stale menu paths at Manage…` — this issue is first a **verification**: does the 5.3.0 rail show "Manage"? If yes, close with a note; if the rail label differs, fix docs, app pairing text and README together.
- Prevention: a docs test that greps `docs/**/*.md` for `→` paths and checks the first segment against the rail's i18n keys (`frontend/src/i18n/locales/en/core.json` or wherever the rail labels live), so a rename fails CI instead of drifting.
- Desktop page copy: either link real installers or show only platforms that have one (U11 — no dead control).

Verification:
1. One path string in docs, README, app pairing text and the UI.
2. The docs path test exists and is green.

---

## Screenshots/Logs
Three paths in circulation (5.2.0): "Manage → Developer & devices → Desktop", "Manage → Channels → Synaplan Desktop", "Channels → Synaplan Desktop".
