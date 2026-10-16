# Sprint 2 — Tree and Apps (UX03–UX07)

**Goal:** five rail sections that each mean one thing, and one Apps directory
for everything that connects from outside.

**User-flow:** J-UX-2 (connect Telegram), J-UX-3 (take over a visitor).

## UX03 — Navigation tree

- `useNavItems.ts`: Manage children regrouped into `assistants` (Assistants,
  Shortcuts, Tasks, Approvals, AI settings) and `apps` (All apps, Connected,
  Chat widgets). Operate label → **Admin**.
- `useNavSections.ts`: rail keys `chats`, `library`, `assistants`, `apps`,
  `operate` (key kept for stable test ids, label "Admin").
- New routes `/apps`, `/apps/:appId`, `/tasks`, `/approvals`; redirects for
  every retired path (query preserved) and rows in `redirects.spec.ts`.
- Test ids stay: `btn-sidebar-v2-nav-channels` is the Apps rail button.

## UX04 — Apps directory

- `frontend/src/apps/catalog.ts`: one entry per app — `id`, `category`,
  `icon`, i18n keys, `isAvailable()`, `isConnected()` loader, `panel` component.
- `views/AppsView.vue` (grid by category, search, *Connected* filter) and
  `views/AppDetailView.vue` (what it is, three steps, connect panel, status,
  advanced).
- First apps: Telegram, WhatsApp (instance status, assistant binding, phone
  verification — **no mock numbers**), Email to Synaplan, Mailbox automation.

## UX05 / UX06 — Remaining apps

Microsoft 365, Dropbox, WebDAV folder, Calendar, Custom tools, Tool servers
(MCP), Higgsfield, Nextcloud / ownCloud / Outlook (steps that start in the
other app), Claude Code (user view in three steps; admin gateway settings move
to Admin › AI), Synaplan Desktop, API & developers, plugin extensions.

## UX07 — Chat widgets conversations

- Widgets page gets tabs *Widgets* and *Conversations*. Conversations is the
  former Live support (all widgets, *Waiting for you* filter preselected,
  waiting count badge on the tab).
- `/channels/widgets/live-support` redirects to `?tab=conversations`.
- Dead widget branch in `ToolsView.vue` removed.

## Exit criteria

1. J-UX-2 and J-UX-3 walked in the browser.
2. A connected app is found in ten seconds via Apps › Connected (U2).
3. Each app states in one sentence what it can do and what disconnect does (U3, U7).
4. Unavailable apps are absent; empty Connected list offers *Browse apps* (U5, U11).
5. Grid and detail pass light, dark, V2, 320 px (U9).
