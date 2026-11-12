# 05 — A completion can decline memory

**Issue:** [#2617](https://github.com/metadist/synaplan/issues/2617).
**Commit:** `fix(api): let a completion skip session memory`
**Class:** `backend-only`.
**Branch:** `fix/20261024-completion-incognito`

## Problem

`POST /v1/chat/completions` stores the call as a chat of the API-key
account. The digest job turns that chat into long-term memory. A
one-shot extraction of a third party's record becomes the key owner's
memory. The stream endpoint already skips this when `incognito` is
true. The completions endpoint never reads that field.

Session chats for Claude Code and Synaplan Desktop stay. Those are the
account owner's conversations (#1473, #2023). Dropping source `api`
from the digest would drop those too, so that is not the fix.

## Goal

A completion that carries `incognito: true` creates no chat, no message,
and therefore no digest row. A completion without it still records a
session. Rows already stored stay until they are deleted.

## Contract

`OpenAICompatibleController::dispatchSessionSummary` returns before
enqueueing `SummarizeApiSessionCommand` when the JSON body has
`incognito` true. The same flag on a streaming completion. Absent or
false keeps today's path.

No new scope. No UI. Backend-only, so the UX exit bullets do not apply.
The person finds an unwanted memory where they already look: Memories →
Long-term memory. This sprint does not add a control there.

## Test

With the mark set, `dispatchSessionSummary` does not enqueue
`SummarizeApiSessionCommand` and no `source=api` chat contains the user
text. Without the mark, the command is still enqueued. An incognito
stream still skips memory extraction (the existing assertion, re-run,
not rewritten).

The first assertion is red on current `main`: the body is not read for
`incognito`.

## Files

- `backend/src/Controller/OpenAICompatibleController.php` — read the
  flag once, pass it into `dispatchSessionSummary`.
- The OpenAPI annotation on the completions request body: `incognito`
  boolean, default false, described as "process this call and store
  nothing".
- The controller test that already covers the completions route. Do not
  open a new test class if that one can hold the two cases.
- Regenerate schemas if the annotation change is on a documented body.
  The web app does not call this field; a schema diff that only adds
  an optional property is expected.

## Out of scope

Deleting the 2026-10-12 memories. The issue says the mark does not
remove them.

## Gate

`make ci-local`. No Playwright.
