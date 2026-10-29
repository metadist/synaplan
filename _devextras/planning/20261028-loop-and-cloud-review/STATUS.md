# Status — loop and cloud-file review

Plan: [`00_master_plan.md`](./00_master_plan.md). A walk is a dated entry
here, not a chat message. §0 is ticked on
[#2860](https://github.com/metadist/synaplan/pull/2860). This branch is
from `main` and does not repeat that tick.

| Step | State | Note |
| ---- | ----- | ---- |
| RV0 | in review | This plan, against `main` `fa64e4c82` |
| RV1 | planned | Round mode drops `scope: files` |
| RV2 | planned | Messages session reuses `call-1` once the usage hook is `on`. A silent shadow compare can hide it |
| RV3 | in review | [#2865](https://github.com/metadist/synaplan/pull/2865). A Messages stream releases the database connection before every provider stream, including one with no server tools. Live today |
| RV4 | planned | Pickup copy leaves `BSOURCEINSTANCE` null. Live where platform links are on |
| RV5 | planned | A repeated listing spends the file limit again. Live, same |
| RV6 | planned | `delivered` needs a recorded download, and a claimed row must expire. Live, same |
| RV7 | planned | Regression lock: do not mark an inbound watch upload as an own write. Before `cloud_files` |
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

## 2026-10-29 — RV3

A Messages stream gives the database connection back before every
provider wait. The direct path releases before `stream()`. A tool
round that writes a usage row reopens the connection, and the next
provider stream releases it again. A later chunk of the same stream
does not. A connection inside a transaction stays open. RV3 is
[#2865](https://github.com/metadist/synaplan/pull/2865).
