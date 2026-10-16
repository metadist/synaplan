<!-- title: Settings: per-user text size / UI scale, accessibility mode (stronger contrast, reduced motion) and font choice; larger smallest labels by default -->
<!-- type: Feature -->
<!-- labels: prio:2, area:profile -->
<!-- issue-type: Feature -->

## Summary
Personal Preferences gain a Display section: text size / UI scale (90–150 %), an accessibility mode (stronger contrast, visible focus rings, reduced motion), font family (system, sans, serif, dyslexia-friendly), and optionally a wide layout; the smallest labels in the default theme move from 10–12 px to at least 12–13 px.

---

## Problem / Motivation
There is no accessibility, contrast or text-size option: the settings search finds nothing, and personal Preferences cover only language, theme and time zone (fonts exist only as admin Branding settings). Measured at 2560 px / 100 %: message text 16 px, sidebar 13–15 px, timestamps, cost and message buttons 12 px, usage meter 10 px. Open WebUI's defaults are smaller still, but users can scale them; the gap is the missing scale setting (F10, F34). The testers' cover note also asks for a true OLED black / dark grey theme — separate issue.

---

## Goal
A person on a 27-inch display or with reduced vision makes the UI readable in two clicks, and the setting follows them across devices.

---

## Acceptance criteria
- [ ] Preferences → Display: Text size (slider or 5 steps) applied through a root `font-size` / `--ui-scale` token so every `rem`-based size scales; Accessibility mode toggle; Font family select; settings stored server-side (profile), applied before first paint (no flash).
- [ ] Accessibility mode: text contrast ≥ 7:1 for body text, focus rings always visible, `prefers-reduced-motion` honoured and forced, no information conveyed by colour alone (the Library status column gets icons + text).
- [ ] Default theme: no text below 12 px; usage meter and timestamps 12 px; checked at 2560 px and 320 px.
- [ ] The settings search finds "text size", "contrast", "font" (command palette `#`).
- [ ] Widget: respects the host page's `prefers-reduced-motion`; scale stays widget-config-driven.
- [ ] Five locales; light + dark; axe scan green in E2E.

---

## Notes
- Findings: F34, F10 — community test round on 5.2.0; comparison §"User-facing customization".
- Code: `frontend/src/composables/useTheme.ts` (theme), `frontend/src/style.css` tokens (`:root` / `.dark`), `style-v2.css` (glass design overrides — verify the scale there too), settings pages under `frontend/src/components/settings/` (split into pages in #2375), profile API for persistence.
- Journey (U10): Preferences → Display → Text size 125 % → chat, sidebar and Library scale; sign in on another browser → same size; Accessibility mode → focus rings visible when tabbing the composer.

---

## Screenshots/Logs
Measured (5.2.0, 2560 px): message 16 px, sidebar 13–15 px, timestamps / cost / buttons 12 px, usage meter 10 px.
