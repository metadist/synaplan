<!-- title: Prompts: a saved-prompt library on "/" with {{variables}}, sharing with people and groups, and a fixed "Open Task Prompts" link -->
<!-- type: Feature -->
<!-- labels: prio:2, area:chat, area:task-prompt -->
<!-- issue-type: Feature -->

## Summary
People can save prompts with a name, a `/command`, tags and `{{variable}}` placeholders, pick them from the composer by typing `/`, and share them with people and groups through the existing share dialog.

---

## Problem / Motivation
Slash commands are five fixed actions (/pic, /vid, /tts, /search, /help) with no saved prompts (F38). The Routing page's "Open Task Prompts" link goes to the Assistants list and there is no prompt library (F41). Prompts have no sharing because there is nothing to share (F42). Open WebUI ships a Prompts workspace with `/command`, tags, `{{variable}}` and per-item access.

---

## Goal
A recurring instruction ("summarize this for the weekly report in three bullets") is saved once, inserted with two keystrokes, filled in through a small variable form, and shared with the team.

---

## Acceptance criteria
- [ ] A Prompts page (under Assistants rail or Library — decide with the nav consolidation track) lists own and shared prompts with search and tags; empty state is one sentence plus "New prompt" (U5).
- [ ] Editor: name, `/command` (unique per person, validated against the five built-ins), body with `{{variable}}` placeholders, tags, Share (reuses `ShareDialog`, levels view / use / edit as appropriate).
- [ ] Composer: typing `/` lists built-ins and saved prompts together, filtered as you type; choosing one with variables opens a one-step form; the filled text lands in the composer, not sent automatically.
- [ ] Prompts are part of Preferences → Export & import.
- [ ] The Routing page's "Open Task Prompts" link points at the right place.
- [ ] Widget: not shown (U11) unless the widget config opts in.

---

## Notes
- Findings: F38, F41, F42 — community test round on 5.2.0.
- Frontend: `frontend/src/components/ChatInput.vue` (slash handling), a new prompts page and editor, `ShareDialog` with a new `kind="prompt"`. Backend: a prompt entity (migration, ask-first), IAM resource kind for sharing (see `backend/src/Service/Iam/ResourceKind/`), OpenAPI → generated schemas.
- Journey (U10): New prompt "/weekly" with `{{topic}}` → Share with a group → colleague types `/we` → picks it → fills topic → sends → answer; colleague cannot edit it (view/use).

---

## Screenshots/Logs
—
