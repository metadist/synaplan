# Notion in chat

**Status:** In progress. **Classification:** backend steps `backend-only`, the Apps panel `ota-candidate`.
**Binding:** UX contract U1–U12. Copy below is the English source; the five locales ship in the same change.

## Goal

A person connects their own Notion under Apps and then reads and edits it from chat. Reading works as soon as the connection is on. Writing works only after they turn on "The AI may change Notion", and then without an extra approval card. Delete, move and overwrite stay blocked. A pasted Notion link is read through the connection.

## User-flow

Pointer: this file. Journey: **J-NOTION-1**.

### Where things live

| Thing | Where |
| ----- | ----- |
| Connect, writing switch, disconnect | Apps → Notion (`/apps/notion`) |
| The page the AI created or changed | Link in the chat answer, which opens Notion |
| Turn writing off, disconnect | Same Notion card |

### J-NOTION-1 — Connect, read, write, undo (Dana, web)

1. Apps → Notion. Empty state: one sentence and **Connect Notion**. She signs in at Notion and lands back on `/apps/notion` with "Notion is connected."
2. Chat: "Summarize my Roadmap page." The answer comes from her Notion, not from a sign-in page.
3. She turns on **The AI may change Notion**. The card says the AI can create and edit pages and cannot delete, move or overwrite them. Chat: "Create a page called Meeting notes." The answer says the page was created and includes the Notion link. She opens it from the chat in one click.
4. She turns the switch off. The card says the AI will no longer change pages, and pages already created stay. **Disconnect** says the AI can no longer read or change Notion, and existing pages stay.

### Wireframe (the card)

- Not connected: "Connect Notion so the AI can read your pages in chat." + **Connect Notion**
- Needs sign-in again: "Notion needs you to sign in again." + **Reconnect**
- Connected: "Signed in to the Notion workspace Acme as Ada (ada@example.com)." (falls back to "Notion is connected." when Notion does not answer), "The AI sees only what this Notion account can see.", switch **The AI may change Notion**, the one-sentence consequence, two example prompts, **Try it in chat**, **Disconnect**
- Could not load: "Notion could not be loaded. Try again." + **Try again**
- Flag off: the app is absent (no card, URL unknown)

## Exit criteria

1. J-NOTION-1 walked in the browser (click, type, find, undo).
2. A created or edited page is findable in ten seconds: the chat answer names what happened and links to the Notion page.
3. Kind-specific consequence copy in all five locales: writing on, writing off, and disconnect each say what the AI will and will not do, and that existing pages stay.
4. Empty (not connected), error (sign-in failed, load failed) and flag-off (app absent, API 404) states.
5. Light, dark, glass design, and 320 px.

## Hardening (2026-10-27)

The hosted Notion MCP server grew from about 15 to 65 tools (calendar, agent sessions, skills, memory, folders, attachments, data-source DDL). The tool hints were a deny-list, so every new write tool reached the planner unguarded.

- `NotionToolHints` is now an allow-list. Known read tools (search, fetch, comments, users, teams, data-source and meeting-note queries, recent / favorite / shared / private pages, backlinks) and write tools (create / update page, comments, database, view, folder, duplicate, restore) keep their hints. Delete, move, calendar, agent sessions, skills, memory, uploads and attachments are always blocked. An unknown tool passes only when Notion declares it read-only and not destructive.
- `NotionWriteGuard` (called by `McpClient::callTool`, so every path is covered) refuses `replace_content`, `apply_template`, `allow_deleting_content`, trashing a data source and `DROP COLUMN`, and drops `allow_async` so a write answers with its real result instead of a background task id.
- `app.notion.com` links are read through the connection like `notion.so` links.
- The rename shortcut understands all five locales (es / fr / tr / en "rename", de "benenne … um").
- Apps → Notion names the signed-in account and workspace (`GET /api/v1/apps/notion/account`, `notion-get-self`, cached per sign-in for an hour, never fails the card).

### Open decision

Connector decision S6 says writes need an approval card; this track skips it once writing is on. The product owner should confirm the one-switch model or ask for the card back.

### Follow-ups (not built, need a decision or a schema change)

- Push a Synaplan file into Notion (needs a signed public URL or the upload flow, currently blocked).
- Notion as a saved-task output channel.
- Watch a page or database for changes (polling).
- Index Notion pages for RAG.
- Organisation-wide connection (ROADMAP row 18).
- "Recently changed by Synaplan" list (needs a table).
- Reauth badge on the Apps tile.
