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
| Pages Synaplan created or changed | Notion card → "Recently changed by Synaplan" |
| Followed pages, their state and errors; pause, resume, stop | Notion card → "Followed pages" |
| Searchable copy of a followed page | Files, folder named on the row (default "Notion"), source badge "Notion" |
| Notion connection needs a new sign-in | "Sign in again" badge on the Apps tile |
| Add a library file to a Notion page | Files → row action (Notion glyph) → toast with **Open in Notion** |
| Saved-task result into Notion | Saved Tasks → Steps → **Save to Notion** |
| Shared, read-only connection | Admin: Notion card switch. Members: Notion card note + **Use it in chat** |

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

## Extension (2026-10-27)

Classification: backend `backend-only` (plus migration `Version20261028090000`: `BNOTIONACTIVITY`, `BNOTIONWATCHES`), scheduler (`app:notion-watches:poll` in the tasks lane, `container-runtime.sh`), frontend `ota-candidate`.

- **File into Notion.** `NotionFilePush` uploads through Notion's file-upload tools and appends the block. Needs the person's own connection with writing on.
- **Save to Notion step.** `save_to_notion` capability (`SaveToNotionRunner`): text and files of earlier steps on a new page under the linked page. The step is offered only while Notion is connected; the validator requires a Notion page link. A Saved Task honours the step approval gate.
- **Follow a page.** Up to 20 per person, checked about every 15 minutes. "Keep searchable" refreshes a copy in Files (source `notion`, vectorised); "Start a saved task" runs a manual saved task when the page changes (the first check only records the version). Every check ends in a recorded state with a plain reason.
- **Shared connection.** An admin shares their own connection read-only (`BCONFIG NOTION/SHARED_SERVER`). Members without their own connection read through it in chat (`mcp_fetch` only); writes always need the person's own connection. Their own connection wins.
- **Recently changed by Synaplan.** `McpClient` records successful create/update/file calls on Notion servers (`BNOTIONACTIVITY`).
- **Meeting notes and database questions.** The planner gets a fixed query-meeting-notes step and a fetch-then-rows plan for a named database.
- **Reauth badge** on the Apps tile.

### J-NOTION-2 — Follow, find, undo (Dana, web)

1. Notion card → paste a page link → **Follow page** (searchable). Toast names the folder; the row links to the copy in Files.
2. Files shows the copy with the Notion badge; search finds its text.
3. **Pause** on the row: "Paused. Synaplan does not check this page." **Stop following** says the copy stays and Notion is unchanged.

Exit bullets: first run guided by the card's empty states; findable from the card, Files and the Apps tile; owner, sharing, effect and stop on the card itself; outcome copy says what did and did not happen; light, dark, 320 px and five locales checked.
