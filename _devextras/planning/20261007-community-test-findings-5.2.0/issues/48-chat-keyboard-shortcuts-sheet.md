<!-- title: Chat: a keyboard shortcuts sheet on Ctrl+/ (and ?) and key hints next to actions in the command palette -->
<!-- type: Feature -->
<!-- labels: prio:3, area:chat -->
<!-- issue-type: Feature -->

## Summary
`Ctrl+/` (and `?` outside a text field) opens a sheet listing every keyboard shortcut grouped by area; the command palette shows the key binding next to each action it lists.

---

## Problem / Motivation
No shortcuts list: `?` and `Ctrl+/` do nothing. `Ctrl+K` opens a good command palette (`>` commands, `#` settings, `@` files) but shows no key bindings. Open WebUI lists about 29 remappable shortcuts on `Ctrl+/` (F33).

---

## Goal
A person learns the shortcuts without reading docs, and the palette doubles as the cheat sheet.

---

## Acceptance criteria
- [ ] One shortcut registry (composable) that every binding registers with: id, keys (mac / other), group, label i18n key. Palette, sheet and the bindings themselves read from it — no second list to drift.
- [ ] Sheet on `Ctrl+/` and `?` (not while typing), grouped: Navigation, Chat, Composer, Files, Accessibility; closes on Escape; focus returns.
- [ ] Palette rows show the binding as `kbd` chips.
- [ ] Shortcuts respect the OS (⌘ on macOS) and never override browser essentials.
- [ ] Remapping is out of scope for this issue (noted as "later").

---

## Notes
- Findings: F33 — community test round on 5.2.0.
- Code: the command palette component (opened on `Ctrl+K`), existing ad-hoc `keydown` handlers in `ChatInput.vue` and views — move them into the registry.
- Journey (U10): press `Ctrl+/` → sheet → read "New chat: Ctrl+Shift+O" → press it → new chat; `Ctrl+K` → the New chat row shows the chip.

---

## Screenshots/Logs
—
