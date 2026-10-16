<!-- title: Theme: a true OLED black variant (and a dark-grey option) next to Light, Dark and System -->
<!-- type: Feature -->
<!-- labels: prio:3, area:profile -->
<!-- status: shipped -->
<!-- issue-type: Feature -->

## Summary
Add a "Black (OLED)" theme — pure `#000` page background, near-black card surfaces, hairline borders — selectable in Preferences → Theme alongside Light, Dark and System, with the same WCAG AA guarantees as Dark.

---

## Problem / Motivation
From the testers' cover note: "I love the UI changes (now hoping for a true OLED black or dark grey mode)". The current dark theme is a deep navy / grey; on OLED phones and laptops a true black saves power and is what many people expect from "dark". A dark-grey (lower-contrast) option covers the other preference.

---

## Goal
One more choice in the theme switch that is as finished as Dark: every surface, dropdown, modal, composer, widget and the mobile app render correctly.

---

## Acceptance criteria
- [ ] `useTheme.ts` gains `'black'` (and optionally `'dark-grey'`); the class on `<html>` is `dark` plus a variant class (`theme-black`) so every `.dark` rule still applies and only tokens change.
- [ ] Token overrides in `style.css` (and `style-v2.css` for the glass design) for `--bg-page`, `--bg-card`, borders, elevation; contrast re-measured: text ≥ 4.5:1, icons ≥ 3:1 on the new surfaces; brand colours on black re-checked (the dark brand note in `style.css` explains why the bubble fill differs).
- [ ] System preference mapping: "System" picks Black when the OS reports `prefers-contrast: more` + dark? — decide in the PR; default stays Dark.
- [ ] Theme switch shows four swatches; the setting syncs with the profile like the other display settings.
- [ ] Widget config can opt into Black; the mobile app follows the web setting (ota-candidate).

---

## Notes
- Cover note request — community test round on 5.2.0.
- Code: `frontend/src/composables/useTheme.ts` (`type Theme = 'light' | 'dark' | 'system'`), `frontend/src/style.css` `.dark { … }` block (~line 300), `frontend/src/style-v2.css`, theme switch in Preferences.
- Journey (U10): Preferences → Theme → Black → chat, sidebar, + menu, model picker, modals, Library all pure black with readable text → back to Dark.

---

## Screenshots/Logs
—
