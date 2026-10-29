# Status — loop and cloud-file review

Plan: [`00_master_plan.md`](./00_master_plan.md). A walk is a dated entry
here, not a chat message. §0 ticked 2026-10-29 (owner: start the plan;
D1–D6 as proposed).

| Step | State | Note |
| ---- | ----- | ---- |
| RV0 | merged | [#2856](https://github.com/metadist/synaplan/pull/2856), [#2857](https://github.com/metadist/synaplan/pull/2857), against `main` `fa64e4c82` |
| RV1 | merged | [#2868](https://github.com/metadist/synaplan/pull/2868). A files-only search stays on the point and the next round runs it. Before `RESEARCH.ENABLED` |
| RV2 | merged | [#2867](https://github.com/metadist/synaplan/pull/2867). A Messages request gets its own usage turn. The session key stays the key for conversation memory. Before `AI.USAGE_HOOK=on` |
| RV3 | merged | [#2865](https://github.com/metadist/synaplan/pull/2865). A Messages stream releases the database connection before every provider stream, including one with no server tools. Live today |
| RV4 | merged | [#2860](https://github.com/metadist/synaplan/pull/2860) — pickup copy stores the claiming platform instance on the file. Live where platform links are on |
| RV5 | merged | [#2861](https://github.com/metadist/synaplan/pull/2861). A repeated listing returns the same upload, reuse or skip and does not spend the limit again. Live, same |
| RV6 | merged | [#2862](https://github.com/metadist/synaplan/pull/2862). A copy counts as sent only after the download. A claimed row expires unconfirmed. Live, same |
| RV7 | in review | [#2870](https://github.com/metadist/synaplan/pull/2870). An inbound watch upload is not an own write. A new version of the same remote id stays accepted. Before `cloud_files` |
| RV8 | planned | Calls in one step whose waits sum past the lease pay the step twice. Waits on `RESEARCH.ENABLED` |

## 2026-10-28 — review

Read-only pass of rows 9, 21 and 22 on `main` at `fa64e4c82`. No model
was called. No flag was changed. #2853 (the architecture target
picture) landed after that read and does not change the findings.
The eight defects are in the master plan with the file and the test
that proves each one. Three things that looked like defects are not:
the bird-status cache generation, marking a missing chat delivered,
and a WebDAV folder of `..` on the person's own connection.

Rows 9, 21 and 22 in `ROADMAP.md` were behind that commit (cloud files
"no product code yet", research "plan only", architecture "AU4 in
progress"). The same change corrects those three state lines. Other
rows were not re-verified.

## 2026-10-28 — Copilot on #2856

Six findings on the plan, all accepted. No product code. §0 stays
unticked.

- A null `close()` in shadow compares the caller's own metadata, so a clean soak is not proof of RV2.
- RV3 releases before every provider stream. A `TOOLS` usage write between rounds reopens the connection.
- RV6 requires a recorded download before `delivered`, including the notify path in `copy()` and a late result after `unconfirmed`.
- A claimed delivery with no result expires on the same clock as a queued one.
- The first draft of RV7 is withdrawn. An inbound watch upload is not an own write. A new version of the same remote id stays accepted.
- RV8 covers a call that outlasts the 240 second lease. Renewing only before the call leaves the duplicate payment.

## 2026-10-28 — Copilot on #2857

Two findings on the corrected plan, both accepted. No product code.
§0 stays unticked.

- RV3 also releases on the direct stream. `tool_loop` is false when the catalog is empty, and `executeStream()` then calls `stream()` without entering `GatewayToolLoop`. The test includes a request with no server-side tools.
- RV8 bounds the whole step, not each call on its own. `CheckStage` makes one call per batch, `StageCall::once()` can retry, and the worker checkpoints only after the step returns. Two waits that each fit under 240 seconds can still outlast the lease. The deadline is the time left on the claim, or the worker renews before every call in the step.

## 2026-10-29 — §0 ticked, RV4

The owner started the track. D1–D6 stand as proposed. RV4 goes first
among the live defects: `placeCopy()` stores the claiming platform
instance on the file. A second cloud with the same remote id gets its
own copy.

Pickup copies stored before this change still have a null
`BSOURCEINSTANCE`. CF2.9 claims an unscoped row only when that owner
has one identity for the client. An owner with two clouds of one
client keeps those older copies unscoped. This step does not backfill
them.

## 2026-10-29 — RV5

A repeated listing of a remote id returns the same upload, reuse or skip.
The file count and the byte count stay on the first decision. A new id
still counts. RV5 is [#2861](https://github.com/metadist/synaplan/pull/2861).
RV4 is [#2860](https://github.com/metadist/synaplan/pull/2860).

## 2026-10-29 — RV6

A copy counts as sent only after the cloud downloaded the file. A claim
hands out the download and stays pending. A claimed row with no result
expires with the same unconfirmed sentence as a queued one. RV6 is
[#2862](https://github.com/metadist/synaplan/pull/2862).

## 2026-10-29 — RV3

A Messages stream gives the database connection back before every
provider wait. The direct path releases before `stream()`. A tool
round that writes a usage row reopens the connection, and the next
provider stream releases it again. A later chunk of the same stream
does not. A connection inside a transaction stays open. RV3 is
[#2865](https://github.com/metadist/synaplan/pull/2865).

## 2026-10-29 — RV2

A Messages request gets its own usage turn. The session key stays the
key for conversation memory. Provider call ids do not restart at 1, so
a second request in the same session is not a zero-cost row when the
usage hook is on, and a shadow compare reads that request's ledger.
RV2 is [#2867](https://github.com/metadist/synaplan/pull/2867).
`AI.USAGE_HOOK` stays `legacy`.

## 2026-10-29 — RV1

A files-only search stays on the plan point. The next round runs it
when this run may search files. An unread file keeps the round while
reads remain. A search the plan cannot hold is recorded, with the
reason, instead of being dropped in silence. RV1 is
[#2868](https://github.com/metadist/synaplan/pull/2868).
`RESEARCH.ENABLED` stays off.

## 2026-10-29 — RV7

An inbound watch upload is not recorded as our own write. The same
remote id with a new version is accepted. The same version is already
handled. A cloud that sends `own_write=1`, and a recent copy-out of
that remote id, are still skipped. Storing the upload does not add a
copy-out row. RV7 is
[#2870](https://github.com/metadist/synaplan/pull/2870).
`cloud_files` stays off.
