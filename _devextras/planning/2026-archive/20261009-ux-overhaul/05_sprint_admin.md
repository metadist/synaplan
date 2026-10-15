# Sprint 5 — Admin (UX12)

**Goal:** an administrator sees what needs attention first and finds any
setting by typing its name.

**User-flow:** J-UX-5.

## UX12

- **Overview** (`/admin`): status cards (people, disabled features, models
  needing attention, registrations) and a *Needs attention* list with one link
  per problem. `/admin/features` stays as the detailed System status and is
  linked from the card.
- **AI** (`/admin/setup`): extra tabs *Routing* (former `/ai/routing`),
  *Coding gateway* (former admin block of `/channels/agents`), and a link to
  vector storage under *Knowledge search*.
- **Settings** (`/admin/config`): search field that filters tabs and fields by
  label and key.
- **Partners**: menu entry hidden while the server is not publicly reachable.
- **People**: intro sentences; platform-instances empty state explains how a
  server registers.

## Exit criteria

1. J-UX-5 walked.
2. Every problem on Overview links to its fix (U2).
3. Settings copy in five locales (U3).
4. Search with no hit shows one sentence + *Clear search* (U5).
5. Light, dark, V2, 320 px (U9).
