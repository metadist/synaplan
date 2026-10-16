# Sprint 3 — Assistants and AI settings (UX08–UX09)

**Goal:** the Assistants section holds only what a user builds or decides;
instance machinery moves to Admin.

**User-flow:** J-UX-4 (create a task directly).

## UX08 — Assistants, Shortcuts, Tasks, Approvals

- Gallery: intro sentence, filter chips with counts, chip tooltips, empty
  chips hidden (except *Mine*).
- `/prompts` → **Shortcuts** with example and `EmptyState`.
- Tasks: **New task** dialog (name, instruction, schedule) creates the topic
  prompt then the saved task via existing endpoints. Empty state: one
  sentence + *New task*.
- Approvals: route guard (flag off ⇒ 404), intro names the kinds of actions.

## UX09 — AI settings

- `/ai/models` page titled **AI settings**, tabs *Default models* and *Topics*
  (`/ai/instructions` content, visible regardless of the Assistants flag).
- Admin-only model tabs (embedding runs, model catalog), routing and the
  Claude Code gateway settings appear under Admin › AI.
- `/ai/routing`, `/ai/task-prompts`, `/ai/instructions`, `/ai/providers`
  redirect.

## Exit criteria

1. J-UX-4 walked in the browser.
2. A created task appears at the top of Tasks immediately (U2).
3. Disable / delete copy states what stops and what stays (U3).
4. Empty, error and flag-off states for Tasks and Approvals (U5, U8, U11).
5. Light, dark, V2, 320 px (U9).
