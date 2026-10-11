# Sprint 4 — Library and account (UX10–UX11)

**Goal:** the Library is one surface with tabs; account pages have correct
titles and empty states with an action.

**User-flow:** J-UX-1 (upload the first file from the checklist).

## UX10 — Library

- `FilesTabs.vue` renders real tabs: Files, Incoming, Generated, Workspace
  (flag), Search. Subtitle per tab.
- Incoming and Generated render their translated empty-state actions.
- Vector storage leaves the Library sidebar; Admin › AI links to it.

## UX11 — Account

- `/statistics` header "My usage", second header removed.
- Memories and Feedback empty states with one action each; the
  "memories disabled" overlay links to `/settings/chat`.

## Exit criteria

1. Upload, find in Files tab, open Incoming / Generated — walked.
2. Every Library tab reachable in one click from any other (U2).
3. Empty-state copy in five locales (U3).
4. Empty states with action; Workspace tab absent when flag off (U5, U11).
5. Light, dark, V2, 320 px (U9).
