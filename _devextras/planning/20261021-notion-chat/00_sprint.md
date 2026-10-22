# Notion in chat

**Status:** In progress. **Classification:** backend steps `backend-only`, the Apps panel `ota-candidate`.
**Binding:** UX contract U1–U12. Copy below is the English source; the five locales ship in the same change.

## Goal

A person connects their own Notion under Apps and then reads and edits it from chat. Reading works as soon as the connection is on. Writing works only after they turn on "The AI may change Notion", and then without an extra approval card. Delete and move stay blocked. A pasted Notion link is read through the connection.

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
3. She turns on **The AI may change Notion**. The card says the AI can create and edit pages and cannot delete or move them. Chat: "Create a page called Meeting notes." The answer says the page was created and includes the Notion link. She opens it from the chat in one click.
4. She turns the switch off. The card says the AI will no longer change pages, and pages already created stay. **Disconnect** says the AI can no longer read or change Notion, and existing pages stay.

### Wireframe (the card)

- Not connected: "Connect Notion so the AI can read your pages in chat." + **Connect Notion**
- Needs sign-in again: "Notion needs you to sign in again." + **Reconnect**
- Connected: status, switch **The AI may change Notion**, the one-sentence consequence, two example prompts, **Try it in chat**, **Disconnect**
- Could not load: "Notion could not be loaded. Try again." + **Try again**
- Flag off: the app is absent (no card, URL unknown)

## Exit criteria

1. J-NOTION-1 walked in the browser (click, type, find, undo).
2. A created or edited page is findable in ten seconds: the chat answer names what happened and links to the Notion page.
3. Kind-specific consequence copy in all five locales: writing on, writing off, and disconnect each say what the AI will and will not do, and that existing pages stay.
4. Empty (not connected), error (sign-in failed, load failed) and flag-off (app absent, API 404) states.
5. Light, dark, glass design, and 320 px.
