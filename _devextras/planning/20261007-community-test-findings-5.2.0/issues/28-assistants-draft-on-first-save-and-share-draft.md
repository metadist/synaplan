<!-- title: Assistants: "Create assistant" stores a draft immediately instead of on first save, and a draft cannot be shared for review before the first publish -->
<!-- type: Bug -->
<!-- labels: prio:2, area:admin -->
<!-- issue-type: Bug -->

## Problem
Clicking Create assistant stores a draft right away, so abandoning the builder leaves an empty "Untitled" assistant in the list. Share stays disabled until the first publish, so a colleague cannot look at a draft before it goes live.

---

## Expected
The assistant exists once the person saves it the first time (or the builder cleans up an untouched draft on leave). A draft can be shared with "Can view" so a reviewer can read instructions and try it, without publishing.

## Actual
1. Assistants → Create assistant → go back without typing → an empty draft is in the list.
2. Share button disabled on a saved draft; enabled only after Publish.

---

## Steps to reproduce
1. Assistants → Create assistant → browser back.
2. Create and save a draft → open Share.

---

## Notes
- Findings: F41, F42 (draft sharing) — community test round on 5.2.0.
- Code: assistants store / builder route (`frontend/src/components/assistants/`, the agent store `store.current.draft`), the agent controller's create path, `ShareDialog kind="assistant"` and the IAM access check for draft vs published versions (`backend/src/Service/Iam/`).

Fix direction: create on first Save (the builder works on a client-side draft until then — `emptyAgentDraft()` already exists), or delete an untouched draft on unmount; allow Share on drafts with "Can view" and "Can use (private test chat)" levels while "also let the router pick this assistant" stays publish-only; the reviewer sees a "Draft — not published" badge (U7).

Journey (U10): Create assistant → back → list unchanged; → Create, fill, Save → Share → colleague opens the draft, tries it, sees the badge → Publish → the share keeps working.

Verification:
1. No orphan drafts after abandoning the builder.
2. Draft share visible to the recipient with the badge; router never picks a draft.
3. E2E for both paths.

---

## Screenshots/Logs
—
