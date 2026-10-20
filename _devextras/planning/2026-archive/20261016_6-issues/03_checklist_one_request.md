# Sprint 03 — Getting-started checklist, one request

**Issue:** [#2461](https://github.com/metadist/synaplan/issues/2461)
**Kind:** The issue says "do not implement before the open decisions are
answered." They are answered below. The code that matches those answers
is already on `main`. This sprint confirms it.
**Class:** `no-app-impact` when the checks pass. `ota-candidate` plus
`backend-only` paths when a fix changes both; the pull request's
classification is the stricter one (`ota-candidate` if `frontend/src/**`
changes).
**Branch, only if a fix is required:** `dev/20261016-s3-checklist`.

## Goal

Mounting an empty chat, with the checklist not dismissed, issues at most
one checklist request. Chat history is not queued behind a per-app status
fan-out. The checklist shows the same items and done states as today.
Apps still shows the same connected badges.

## Decisions (the issue's three questions)

| # | Question | Answer |
| - | -------- | ------ |
| 1 | Endpoint shape | **(b)** One checklist endpoint, `GET /api/v1/getting-started`, returning item states. **(a)** `GET /api/v1/apps/connected` is not added. Apps keeps `loadConnectedAppIds` because it needs each app, and that fan-out must not run from the empty chat. **(c)** is rejected: two endpoints on the empty chat would miss the goal. |
| 2 | Where feature gates live | In `GettingStartedStatusService`. A module that is off, and a check that failed, omits the item. The Vue side hides an omitted item. It does not call the module's status URL. |
| 3 | Cache | One fetch per empty-chat mount. No session cache in this track. One request meets the acceptance criteria. A cache is a second behaviour (stale "not done" after the person connects an app) and stays out. |

## User-flow

Journey **J-GS-1**. A new account, checklist not dismissed, empty chat.

1. They open Chat. The checklist appears after its one request, with the
   steps that apply to this server (a switched-off module is absent, not
   a dead row).
2. They dismiss it. The next empty chat does not show it and does not
   need the checklist to block the thread.
3. They open Apps. Connected badges match what the checklist called done
   for apps. Apps may make its own per-app requests; the empty chat must
   not.

## What `main` already does

- `frontend/src/components/chat/GettingStartedChecklist.vue` `onMounted`
  calls `httpClient('/api/v1/getting-started', …)` and nothing else for
  status. A thrown request leaves the checklist hidden (it is never shown
  as all open).
- `frontend/tests/unit/components/chat/GettingStartedChecklist.spec.ts`
  expects that URL as `httpClient.mock.calls[0][0]`.
- `backend/src/Controller/GettingStartedController.php` documents the
  four items. Absent means hidden. False means still to do.
- `backend/tests/Unit/Service/Chat/GettingStartedStatusServiceTest.php`
  and `GettingStartedRulesTest.php` cover the rules.
- `frontend/src/apps/status.ts` `loadConnectedAppIds` remains for Apps.

## The one step

1. Read the checklist `onMounted` and confirm it does not import
   `loadConnectedAppIds`, `listFiles`, or `agentsApi.list`.
2. Run:

   ```bash
   docker compose exec -T frontend npm run test -- tests/unit/components/chat/GettingStartedChecklist.spec.ts
   make -C backend test
   ```

   The backend suite is unfiltered when this sprint is about to commit.
   For a confirmation, the two GettingStarted test files may be filtered
   first; a green filter is not a commit gate because this sprint should
   not commit.

3. In the browser, on an empty chat with the checklist visible, count
   the requests whose path contains `getting-started` and confirm there
   is no burst of `/api/v1` status calls for Telegram, WhatsApp, widgets,
   mailbox, MCP, and the rest from this mount. Record the count in
   `STATUS.md`.

4. **Pass:** comment on #2461 quoting the three decisions and the SHA.
   Close the issue. No branch. Stop.

5. **Fail:** the test that fails is the one that counts outgoing URLs
   from the checklist mount and expects a single `/api/v1/getting-started`.
   Fix the mount. Do not add `GET /api/v1/apps/connected`. Keep OpenAPI
   and the generated Zod schema in sync if the response changes. Loop,
   master plan §3. Commit type `fix`.

## Quality gate

Confirmation: the Vitest file, the two PHPUnit files, the browser count.

Fix: `make ci-local`, `make -C frontend generate-schemas` if annotations
change, `make test-e2e` because empty chat is user-visible. Copilot. CI
green. `node scripts/mobile-impact.mjs --base <parent> --head HEAD`.

## Exit, if a fix is required

1. J-GS-1 walked (U10).
2. The checklist is on the empty chat; dismissed, it stays gone (U2, U3).
3. Dismiss is the existing control. No new copy unless a new sentence is
   required; then all five locales.
4. A failed checklist request hides the card. It does not show every step
   as open (U8). Flag-off modules are absent rows (U11).
5. Light, dark, 320 px (U9).

## Out of scope

A connected-apps endpoint. A Pinia cache. Changing Apps' fan-out.
Sharing the checklist response with Apps.
