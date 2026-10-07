<!-- title: Chat: live, sandboxed preview for HTML, SVG and Mermaid output (artifacts) with download -->
<!-- type: Feature -->
<!-- labels: prio:2, area:chat -->
<!-- issue-type: Feature -->

## Summary
When an answer contains a complete HTML page, an SVG or a Mermaid diagram, the chat offers a side panel that renders it live in a sandbox, with Copy, Download and Fullscreen, and updates when the person asks for a change.

---

## Problem / Motivation
A request for a small HTML page returns a highlighted code block with Copy only; nothing renders. Open WebUI opened a live side panel with the working page, versions, Copy, Download and Fullscreen, and updated it after an edit (F37).

---

## Goal
"Make me a small landing page" produces something the person can see and download, not a wall of code.

---

## Acceptance criteria
- [ ] Detection: a fenced block with language `html` (complete document), `svg`, or `mermaid` shows a "Preview" action (`EyeIcon`) on the block.
- [ ] Preview renders in a sandboxed `iframe` (`sandbox="allow-scripts"` only, never `allow-same-origin`, CSP that blocks network). Do not inject the HTML into the parent page. SVG is sanitized before display (no script, no external URLs). Mermaid, if added, is a new npm dependency and stays behind the ask-first note; without it, a mermaid block stays a code block.
- [ ] Panel actions: Copy, Download (`ArrowDownTrayIcon`, file named from the chat title), Fullscreen, Close; versions when the answer is regenerated (pairs with the versions issue).
- [ ] Mobile (320 px): the panel becomes a full-screen sheet.
- [ ] Admin can turn the feature off (feature module pattern, flag off ⇒ no Preview action, U11).

---

## Notes
- Findings: F37 — community test round on 5.2.0.
- Frontend: `MessageText.vue` (code block rendering), a new `ArtifactPanel` component (lazy-loaded, it is heavy). Mermaid would be a new npm dependency — list it for the ask-first review.
- Journey (U10): "build a small HTML page with a heading and a button" → Preview → page renders → "make the button red" → preview updates → Download → file opens in the browser.

---

## Screenshots/Logs
—
