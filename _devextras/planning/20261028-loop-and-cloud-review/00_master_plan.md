# Loop and cloud-file review — 2026-10-28

**Status:** Plan. §0 is not ticked. No fix starts until the owner ticks it.
**Base:** `main` at `fa64e4c82` (28 Oct 2026, 15:28 Europe/Berlin).
**Rows it guards:** [21 research quality](../20261017-research-quality/00_master_plan.md),
[22 architecture update](../20261021-architecture-update/00_master_plan.md),
[9 cloud file sharing](../20261013-cloud-file-sharing/00_master_plan.md).
**Goal:** Close the defects found by reading those three tracks against
`main`, before research is switched on, before `AI.USAGE_HOOK` leaves
`legacy`, and before a second cloud reuses a pickup copy.

This track does not replace rows 9, 21 or 22. It records what those
tracks must fix. The shared tool loop stays on row 22 (AU24).

| Step | What | Release class | Kind |
| ---- | ---- | ------------- | ---- |
| RV0 | This plan | `no-app-impact` | docs |
| RV1 | Round mode keeps a files-only search | `backend-only` | fix |
| RV2 | A Messages session does not reuse usage call ids | `backend-only` | fix |
| RV3 | A Messages stream releases the database connection | `backend-only` | fix |
| RV4 | A pickup copy remembers its cloud | `backend-only` | fix |
| RV5 | A repeated folder listing does not spend the limit twice | `backend-only` | fix |
| RV6 | "Sent" requires the download | `backend-only` | fix |
| RV7 | A watch ignores the file it just wrote | `backend-only` | fix |
| RV8 | A live research step renews its lease | `backend-only` | fix |
| [`STATUS.md`](./STATUS.md) | Step log | — | — |

One pull request per step, from `main`. Subjects start with `fix`.
None of these pull requests flips `RESEARCH.ENABLED`, `AI.USAGE_HOOK`,
or `cloud_files`.

---

## 1. What is already on main

Read against `fa64e4c82`. The roadmap lines for rows 9, 21 and 22 were
behind that commit. This plan corrects them. It does not re-verify any
other row.

**Research (row 21).** The run kernel is in
`backend/src/Service/Research/Run/`: brief, plan, gather, read, reflect,
write, fact-check, deliver, follow-ups, budgets. The admin Research tab,
the web-chat entry and the saved-task step are merged. The Tools menu
shows Research only when the module is on. `RESEARCH.ENABLED` stays off.
Default lead mode is round mode (`LeadModeResolver`): tool mode runs only
for a model on the conformance list, and that list starts empty. Still
open on that track, and not repeated as steps here: the model bake-off,
the files-only live walk, OpenAI `/v1/chat/completions` parity (page
reading, knowledge search, the wrap-up prompt), per-host politeness, and
measured hybrid search.

**Architecture (row 22).** `AiCallPipeline` is under `AiFacade`. The
hooks through usage and request timing are on `main` (#2844, #2845,
#2846). The Messages gateway joins the pipeline. `AI.USAGE_HOOK` is
seeded `legacy`. One tool path (AU24) has not started.
`GatewayToolLoop` is 1,431 lines. `OpenAiGatewayToolLoop` is 667 lines.

**Cloud files (row 9).** Copy tickets, pickups, source events, the 7-day
reaper, watches and the lifecycle delete guard are in this repo.
`cloud_files` still answers 404 when the module is off. Platform links
seed **on** (`PlatformLinksConfigSeeder` writes `ENABLED=1`). Copy,
pickup and delivery therefore run on a normal install while the bird
and the watches stay off. The Synaplan web app sends files to a cloud
and links platforms. Bird status and watches are APIs for the cloud apps.

---

## 2. What is live today, and what waits on a flag

| Defect | Live on a normal install | Waits on |
| ------ | ------------------------ | -------- |
| RV3 Messages stream holds a database connection | Yes. `POST /v1/messages` | — |
| RV4 pickup copy has no source instance | Yes, wherever platform links are on | — |
| RV5 listing spends the limit again | Yes, same | — |
| RV6 delivered without a download | Yes, same | — |
| RV2 session call-id collision | Billing stays correct in `legacy` and in `shadow` | `AI.USAGE_HOOK=on` under-bills |
| RV1 files-only reflect search dropped | No new research run starts | `RESEARCH.ENABLED` |
| RV8 lost lease pays the step twice | Same | `RESEARCH.ENABLED` |
| RV7 watch loops on its own upload | Watches 404 | `cloud_files` |
| Two tool loops disagree | Yes, for `/v1/chat/completions` vs `/v1/messages` | Stays on row 22 AU24. See §4 |

`shadow` still writes the real usage row and then compares. A second
Messages turn in one session will log `ai.usage_mismatch`. The
architecture soak (seven days of shadow with zero mismatches) cannot
go green until RV2. Flipping the hook to `on` before RV2 writes a
zero-cost row for that second turn.

---

## 3. Decisions (§0)

Proposed. Not ticked.

| # | Decision | Why |
| - | -------- | --- |
| D1 | [ ] **RV4, RV5, RV6 and RV3 go first.** They run with today's flags. | Platform links seed on. The Messages API is the Desktop and Claude Code path. |
| D2 | [ ] **RV2 merges before `AI.USAGE_HOOK` is set to `on` anywhere.** Shadow may start; it will report the mismatch until RV2. | `on` drops later turns in a session to a zero-cost receipt. |
| D3 | [ ] **RV1 and RV8 merge before `RESEARCH.ENABLED` is turned on.** | Round mode is the default, and the files-only walk has not been run. |
| D4 | [ ] **RV7 merges before `cloud_files` is turned on.** | Watches are off today. |
| D5 | [ ] **The shared tool executor stays on row 22 (AU24).** This plan does not open a second loop. AU24 must keep the Messages wrap-up prompt and the `TOOLS` usage row, which the OpenAI loop does not have. | Two catalogs already diverged on purpose (`OpenAiGatewayToolCatalog`). Copying one into the other makes that worse. |
| D6 | [ ] **One `fix` pull request per step, from `main`.** No flag change in the diff. | A flag flip hides whether the fix is the thing that shipped. |

---

## 4. The two tool loops (not a step here)

`GatewayToolLoop` (`POST /v1/messages`) injects a wrap-up after web
search (`WRAP_UP_AFTER_SEARCH`), pings the stream every 15 seconds, and
writes a `TOOLS` usage row for MCP and native tools.

`OpenAiGatewayToolLoop` (`POST /v1/chat/completions`) has the same 16-tool
cap, 12k result cap and 240 second wall. It has no wrap-up prompt and no
`TOOLS` usage row. Its catalog injects `web_search` whenever Brave is
available, which the Messages catalog does not.

Row 21 already left the OpenAI route as a follow-up (RS2, RS3). AU24
is the place those two loops become one executor: owned versus client
tools, the caps, the wall, and one usage adapter. Until that lands,
new tool behavior goes into a shared type both loops call. It does not
land as another copy inside the 1,431-line class.

`AiCallSource::isJoined()` returns true for every source. That is the
AU12 decision. The comment above it still says Messages and live voice
stay outside the pipeline. RV2 replaces that comment.

---

## 5. Steps

### RV1 — Round mode keeps a files-only search

`ResearchDecisionSchema` and `LoopActions` accept scope `files`.
`EvidenceLedger::planSearch()` stores only `web` and `both`.
`ReflectStage::queue()` ignores the false return. `hasPlannedSearch()`
skips scope `files`. `ReflectStage::hasWork()` treats only an unread
**web** page as a reason to continue.

The first plan still searches files: `PlanStage` writes the scope onto
the point, and `GatherStage` honors anything other than `web`. The hole
is the **next** round. Tool mode (`LeadSearch`) already searches files.
A files-only run whose lead says `scope: files` ends with
`nothing_queued` and a report that never searched.

**Change.** `planSearch()` accepts `files`. `hasPlannedSearch()` counts
an unrun files query. `hasWork()` counts an unread file source the
reader can open. A dropped action is an event, not a silent false.

**Test.** A reflect decision `{type: search, scope: files}` leaves that
query on the point. With reads left and an unread file source,
`hasWork()` is true. With no unread source and no unrun query, it is
false.

**Do not.** Turn research on. Change tool-mode `LeadSearch`.

### RV2 — A Messages session does not reuse usage call ids

`MessagesGateway::sessionKey()` is `x-claude-code-session-id` when the
client sends one, otherwise a hash of the user and the first user
message. The turn id is `messages-` plus that key, so it is stable for
the whole session (`MessagesGateway` around the `executeComplete` /
`executeStream` call sites).

`AiPipelineMeter` numbers calls `call-1`, `call-2`, and resets the
counter on every request. `TurnUsageLedger::addContribution()` stores
each `(turnId, callId)` once (`HSETNX`). `close()` runs once
(`SET NX`). The second request in the session reuses `call-1`. In
`on` mode `fulfillJoinedOnMode()` gets a null close and returns a
zero-cost `RecordedUsage`.

**Change.** The turn id on an `AiCall` is unique per HTTP request
(a new id, not the session key). Each provider call id is unique
across requests (a UUID, or the request id plus a counter). The
session key stays the key for conversation memory. Replace the stale
`isJoined()` comment.

**Test.** Two `executeComplete` calls with the same session id, hook
mode `on`, scripted translator. The second usage row is not
`0.000000` / zero tokens. A third call in one request still sums into
that request's turn. `legacy` still writes one row per call, as
`UsageHookTest` already locks.

**Do not.** Set `AI.USAGE_HOOK` to `on` or `shadow` in a seeder,
migration, or env file.

### RV3 — A Messages stream releases the database connection

`ChatReplyGenerator` calls `DatabaseConnectionRelease::release()`
before `chatStream()`, and wraps the first chunk with
`releaseOnFirstCall()`. `MessagesApiController::streamResponse()`
runs `GatewayToolLoop` for up to 240 seconds and returns the
connection only when the response ends
(`StreamedResponseConnectionRelease`, kernel terminate).

**Change.** Release before `executeStream()`, with the same helper
and the same "first chunk releases again" wrap the chat path uses.
The test follows `ChatReplyGeneratorConnectionReleaseTest`: the
connection is closed before the translator is asked for the stream,
and a later chunk does not require it to be open.

**Do not.** Close a connection that is inside a transaction. Do not
release on every SSE chunk.

### RV4 — A pickup copy remembers its cloud

`FileUploadService` sets `BSOURCEINSTANCE` from the upload options.
`PickupService::placeCopy()` sets source, remote id and etag, and
leaves the instance null. `FileRepository` refuses to claim an
unscoped row once the owner has a second identity for that client.
A pickup copy from cloud A can then miss a status or overwrite call,
or look like cloud B's file. The rule on row 9 is that a cloud file
is `(instance, remote id)`.

**Change.** `placeCopy()` sets the source instance from the platform
instance that claimed the pickup. The held-copy row already stores
that instance id; the `File` row must store it too.

**Test.** Extend the pickup test that stores a working copy. Assert
`sourceInstance` equals the claiming instance. A second instance with
the same remote id does not reuse that file.

**Do not.** Backfill old rows in this step. Row 9 CF2.9 already
describes the one-time claim for unscoped rows. Name the leftover in
STATUS if pickup copies from before RV4 are still null.

### RV5 — A repeated folder listing does not spend the limit twice

`PickupService::listing()` adds to `acceptCount` and `acceptBytes`
for every item, including one it already accepted. A retry of the
same listing doubles the count. Later files come back `over_limit`
while the run is still inside 50 files and 200 MB.

**Change.** Accepting a remote id is idempotent for that pickup.
A second listing of the same id returns the same `upload`, `reuse`
or `skip` and does not add to the counters again. A new id still
counts.

**Test.** List the same two files twice. Counters stay at 2 files
and the sum of their sizes. A third new file still counts.

### RV6 — "Sent" requires the download

`CloudDeliveryService::result()` records `delivered` while the row
is still `queued`. Nothing checks that the download ticket was
taken. The copies list can show the file as sent when no byte left
Synaplan. Pickups close with `CloudPickupRepository::finishIfOpen()`
(`UPDATE … WHERE state IN (queued, claimed)`). Deliveries are a
read-modify-write, so two results can also race.

**Change.** `claim()` moves the row from `queued` to `claimed` in
the same conditional update. `result(delivered)` applies only from
`claimed` (and the existing late success from `unconfirmed`). A
result on `queued` leaves the state `queued`. The first terminal
state wins.

**Journey.** The copies list (`GET /api/v1/files/copies`, the
existing Sent to row). No new screen. After a real claim and
download, the row says sent. After a result that skipped the
download, the row stays pending until the sweep says it was not
confirmed. The sentence already on that row is the one the person
reads. Flag-off for `cloud_files` is unchanged: this path is
platform links.

**Test.** `result(delivered)` on a queued row does not change the
state. After claim, it does. Two concurrent results: one wins, the
other sees the terminal state.

### RV7 — A watch ignores the file it just wrote

`LoopGuard` suppresses a remote id when a copy-out wrote it in the
last 10 minutes (`CloudDeliveryRepository::writtenRecently()`).
`WatchUpload` stores a searchable copy and does not write that
ledger. The cloud's next change event runs the watch again.

**Change.** A watch upload records the same "we wrote this remote
id" fact the guard already reads, for the same 10 minutes. A
delivery and a watch upload share that record. Translate's language
suffix rule stays.

**Test.** A watch whose upload just recorded remote id `5678` returns
`own_write` for that id. An upload from a different identity does
not.

**Do not.** Enable `cloud_files`.

### RV8 — A live research step renews its lease

`ResearchUnitWorker` claims a step, runs every model call, then
checkpoints. The reaper treats an expired claim as a lost worker,
releases it, and dispatches the same step again from the last saved
state (`ResearchReaper::reapClaim()`, attempt 1). The first worker's
checkpoint then fails. The step is paid twice. Attempt 2 writes
whatever the ledger holds and finishes `failed` / interrupted,
without the fact-check. Round steps get the remaining budget plus
four minutes. Write and check steps get four minutes
(`LEASE_MS = 240_000`).

**Change.** The worker renews the claim before each model call, for
the same lease length, only while its token still holds the claim.
A renewal that loses the token stops the step before the next paid
call. The reaper is unchanged: an actually dead worker is still
redispatched once and then interrupted. The interrupted outcome
sentence keeps saying the run was interrupted. It must not read as
a finished, fact-checked report (the delivery path already prefixes
a non-complete report with the outcome sentence).

**Test.** A claim renewed inside the lease is not in
`expiredClaims()`. A worker whose renewal fails does not call the
model again. A claim that is not renewed still redispatches once.

**Do not.** Lengthen the lease and call that the fix. Turn research
on.

---

## 6. Checked, and not a defect

- **Bird status cache.** `CloudFileStatus` caches for 60 seconds, and
  the key includes `CloudFileStatusCache` generation. A file change
  bumps that generation, so the next status call misses the cache.
- **A research report whose chat is gone.** `ResearchReportDelivery`
  marks the run delivered when the chat is missing or belongs to
  someone else, so a deleted chat does not retry. A throw during
  post does not mark it delivered, so a database error still retries.
- **WebDAV `..` in a folder.** `WebDavDestinationProvider::ensureFolder()`
  walks the folder the person's own connection configured. The
  credentials are that account's. It does not cross into another
  person's files.
- **`isJoined()` returning true.** AU12 joined every source. Only the
  comment is stale (RV2).

---

## 7. Exit

The track is done when RV1–RV8 are merged, each with the test named
above green in the unfiltered backend suite, and STATUS names the
pull request. Research stays off. The usage hook stays `legacy`.
`cloud_files` stays off. The three source tracks keep their own
remaining work.
