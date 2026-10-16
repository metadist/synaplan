# Sprint 04 — Widget visitors do not see internal sources

**Issue:** [#2519](https://github.com/metadist/synaplan/issues/2519)
**Kind:** Bug. Fix directly. This is the first sprint that always has a diff.
**Class:** `ota-candidate`. The pull request also changes `backend/**`.
Run `node scripts/mobile-impact.mjs`. It must not come out
`store-required`.
**Branch:** `dev/20261016-s4-widget-sources`
**Cut from:** the latest code branch in the stack (the plan branch, or a
sprint 01–03 fix branch). Pull request targets that parent.

## Goal

A visitor on a customer site sees the answer, then the composer. They do
not see a Sources block, a `WIDGET:wdg_…` id, "Show the passage", or
"This passage could not be opened." The answer still uses the widget's
crawled pages and files. That retrieval stays on the server.

A visitor sentence that asks to search the web does not run a public web
search. The signed-in app chat and a shared chat link keep their sources
list and their search behaviour.

## User-flow

Journey **J-WG-1**.

1. A visitor opens a host page whose widget has crawled knowledge (the
   report used Roatel, widget `wdg_787cca022ca90b45fb4d41c19048155c`).
2. They ask a question the crawled site can answer.
3. They scroll to the end of the assistant message. The thread ends at
   the answer text. There is no sources row.
4. They reload the host page. The rows stay gone. The answer is still
   about that site, not a generic reply.
5. They type a sentence the server treats as an explicit web search.
   No web-search cards appear. The app chat, with the same sentence and
   search allowed, still searches.

There is nothing to undo: the visitor was never given a control.

Copy is not new. The sprint removes a block. Do not add a "sources
hidden" sentence.

## The change

Two edits. Both are required. Hiding the list and leaving web search on
is incomplete. Turning web search off and leaving `KnowledgeSources`
mounted leaves the screenshot in place.

### 1. Stop painting the list

`frontend/src/components/widgets/ChatWidget.vue`

- Remove the `KnowledgeSources` block under the assistant text
  (the `v-if` on `message.ragSources.length > 0` around the import at
  the template).
- Remove the import and `loadWidgetPassage` if nothing else calls it.
- On the live `complete` handler, stop assigning `lastMessage.ragSources`.
- In `normalizeServerMessage` / `parseRagSources`, stop putting
  `ragSources` on the message the visitor renders. Deleting the helper
  is fine when it has no caller.

Leave the field on the API payload. The server may keep sending it.

Do not change:

- `frontend/src/components/ChatMessage.vue`
- `frontend/src/views/ChatView.vue`
- `frontend/src/views/SharedChatView.vue`
- `frontend/src/components/chat/KnowledgeSources.vue`
- `WidgetSessionsView.vue`
- `WidgetPublicController::ragChunk`

### 2. Refuse web search on a widget turn

`backend/src/Service/Message/Processing/TurnWebSearch.php` `run()`.

When the turn is a widget turn (`$options['is_widget_mode']` or
`$classification['is_widget_mode']`), do not search. That includes a
message `WebSearchTopicPolicy::isExplicitSearchRequest` would accept,
and a task prompt that never set `tool_internet` to false.

Put the opt-out in this method, before `shouldSearch` can return true.
Do not change `shouldSearch` for app-chat callers. Link reading
(`linkedContent->read`) stays: a pasted URL is not a web search.

Widget processing already sets `is_widget_mode` from
`StreamTurnPreparer` and `WidgetPublicController`. Reuse that flag.
Do not add a second flag.

## Test first

### Frontend

New test beside `frontend/tests/unit/components/widgets/ChatWidgetFullscreen.spec.ts`.
Mount `ChatWidget` the same way (`testMode: true`, the heroicon mock, the
transition stub).

Seed one assistant message the way history does: stub the history call
the widget makes so the payload contains five `ragSources` with empty
`fileName`, `groupKey` `WIDGET:wdg_787cca022ca90b45fb4d41c19048155c`,
and real `chunkId`s. One of them has `startLine` 29.

After flush:

- `[data-testid="knowledge-sources"]` is absent
- the text "Show the passage" is absent
- the text `WIDGET:wdg_` is absent

The same assertions cover history, because the seed is a history payload.
If the live `complete` path is reachable in this mount without a model
round-trip, assert it too. If it is not, the history assertion plus the
deleted assignment in review is enough; do not boot a fake stream just
to set a property.

`SharedChatView` tests that show `handbook.pdf` and open a passage stay
green. Do not edit them except to prove they still pass.

### Backend

A unit test on `TurnWebSearch` (or the existing test of this class, if
one exists — extend it, do not invent a second harness):

- `is_widget_mode` true and a message that matches
  `isExplicitSearchRequest` → search is not invoked.
- the same message without widget mode, with search allowed → search
  still runs (the app-chat path).

Use the test double the current tests use for `WebSearchGateway`. Do not
call Brave.

## Quality gate

```bash
make -C backend lint
make -C backend phpstan
make -C backend deptrac
make -C backend test
make -C frontend lint
docker compose exec -T frontend npm run check:types
make -C frontend test
make test-e2e
node scripts/mobile-impact.mjs --base <parent> --head HEAD
```

`make -C frontend generate-schemas` must be a zero diff. This sprint does
not change OpenAPI.

Then the loop: commit `fix(widget): hide internal sources from visitors`,
push, pull request into the parent branch, Copilot reviewer, every
Copilot comment answered, required checks green, STATUS line.

Walk J-WG-1 in the browser on a widget that has crawled pages. If the
local stack has no crawl, use the dev widget with a known knowledge
group and say so in STATUS. The unit test is not a substitute for the
walk when a widget with knowledge is available.

## Exit

1. J-WG-1 walked (U10).
2. The visitor finds the answer at the bottom of the thread. There is no
   second block to hunt for (U2).
3. No new sentence. The app chat's sources list is unchanged, including
   its own show/hide copy in all five locales (U3).
4. The embed has no empty sources state, because the block is absent
   (U5, U11). A search sentence does not produce a failed search card.
5. Light and dark widget chrome, and a narrow host page (U9). The widget
   does not use `.design-v2`; check the embed, not only the app shell.

## Out of scope

How `WIDGET:<id>` chunks are retrieved. Qdrant payloads. A visitor-safe
filename. Restyling `KnowledgeSources` into the embed. Deleting
`ragChunk`. Operator transcripts.
