# Sprint 6 — Tours and close-out (UX13–UX14)

**Goal:** a new user is guided from an empty install to a useful result; the
new tree is locked by tests and documented.

**User-flow:** J-UX-1 end to end.

## UX13 — Tour content and getting started

- Tours for Chats, Library, Assistants, Apps, Admin (3–5 steps each).
- Getting-started checklist on the empty chat: upload a file, create an
  assistant, connect an app; admins also *set up an AI provider*. Each item
  ticks itself from real data; the card can be dismissed (stored as tour id
  `checklist.dismissed`).

## UX14 — Close-out

- E2E: journeys J-UX-1…5 (`@ci`), redirect matrix, nav specs updated.
- Docs: `docs/FRONTEND_CONVENTIONS.md` (EmptyState, tours, Apps catalog).
- `.github/mobile-impact-policy.json` lists the new paths.
- `20260914-navigation-consolidation` NV10–NV23 marked as replaced.

## Exit criteria

1. J-UX-1 walked from a fresh database.
2. The checklist names where each result is found (U2).
3. Checklist and tours in five locales (U3).
4. Dismissed checklist stays dismissed across devices (U5).
5. Light, dark, V2, 320 px (U9).
