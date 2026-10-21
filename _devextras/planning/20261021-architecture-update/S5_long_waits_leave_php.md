# S5 — Long waits leave PHP

Master plan §5 rows AU27–AU32 and AU31b; the owner's remark: requests that would keep
PHP busy for 30 seconds or minutes must leave PHP idle, and a watchdog directs
them to the logic. Contracts: [`03_contracts.md`](./03_contracts.md) §6–§9.
Measurements that justify the design: [`02_measurements.md`](./02_measurements.md)
§4.3 (Go relay) and §4.4 (PHP pump). Loop:
[`01_vibe_coding_loop.md`](./01_vibe_coding_loop.md).

**The idea in three sentences.** PHP prepares a turn in a short request and
writes the provider request into a queue. A few PHP pump processes hold all
provider streams at once (curl_multi) and write finished client frames into
the run's Redis Stream, with the same decoders and translators as today. The
Go watchdog holds the client connection and copies those frames, so no web
worker waits — and with either part off, everything runs as today.

**Kinds of long wait and where each goes:**

| Wait | Today | After S5 |
| ---- | ----- | -------- |
| A streamed answer (seconds to minutes) | a PHP worker, `set_time_limit(0)` | pump + watchdog (AU30, AU31) |
| A tool call inside a streamed turn | the same worker, blocking | a Messenger job (`ToolStepCommand`), the pump continues the turn (AU30) |
| A video / image render | a Messenger job by default; inline `sleep()` polling for dependent multitask nodes and the direct generation path | always a job; the multitask node waits as a paused node (AU27) |
| Waiting for a person (approval, ask-the-user) | already a paused run, resumed by a job | unchanged |
| Turn preparation (sorter ≈ 1 s, embeddings, memory search) | the stream worker | still the `streams` pool, short; moving it to workers is master plan §9 #1 |

---

## AU27 — No web request waits for a render (backend-only)

### Goal

- Do: the inline media path for a multitask node that another node depends
  on (`force_inline_media`, `MediaJobDetach::allowed()`) becomes a job; the
  node waits as a paused node and is resumed when the job ends, the way
  approvals resume (`ResumeApprovalCommandHandler` pattern).
- Do: `MediaGenerationService::generateVideo()` (the direct path,
  `MediaGenerationService.php:528`) goes through the job.
- Do: `tests/Architecture/LongWaitGateTest.php`: `sleep(`, `usleep(`,
  `set_time_limit(` outside `MessageHandler/`, `Command/` and a listed set
  of transports, with a shrink-only baseline; the streaming translators'
  `set_time_limit(0)` are on it until AU31.
- Do not: change what a person sees in chat for a render (status, the
  `running` placeholder, the finished file).

### Vibe brief

> Implement AU27 as a `fix(media)`. Regression test first: a multitask plan
> with an image node feeding a second node — on the parent the web request
> polls (assert the provider poll is called from the request); on the branch
> the request returns at once and the plan finishes from the worker. Media
> characterization (`MediaTurnCharacterizationTest`) diff quoted: only the
> inline cases change. `make ci-local`, `make test-e2e` (multitask, media specs).

### Exit criteria

1. Regression test red on the parent, green on the branch.
2. `LongWaitGateTest` baseline: only the streaming transports remain.
3. Media and multitask E2E specs green.

---

## AU28 — Spike: watchdog relay and stream pump end to end (STATUS entry; branch never merged)

### Goal

On a branch `spike/au28-relay-pump`, prove the whole S5 shape for one route
(`/v1/chat/completions`, plain chat, no tools) and decide go / no-go:

- the attach directive in PHP (`03` §8), a pump prototype with the real
  OpenAI chat-completions decoder, the run as a Redis Stream, the watchdog in
  relay mode copying frames;
- the upstream: an OpenAI-compatible endpoint pointing at the spike's Go stub
  (`spike/relay`, `--mode upstream`), so 500 streams cost nothing;
- 8 PHP workers in worker mode.

### Measurements (all into STATUS)

| # | What | Go if |
| - | ---- | ----- |
| G1 | 500 concurrent 10 s streams through watchdog + pump on 8 workers | all complete; API probe p95 ≤ 50 ms |
| G2 | Watchdog hop added to TTFB (M5) | ≤ 5 ms p95 |
| G3 | Pump CPU per event with the real decoder (M3) | ≤ 0.2 ms |
| G4 | Kill the pump mid-stream | the client gets the terminal frame within `idleTimeoutSec`; no run stays "running" |
| G5 | Kill the watchdog mid-stream | the client errors; the run finishes; a re-attach shows the full answer |
| G6 | Client disconnects (gateway) | the upstream request is cancelled within one pump tick |
| G7 | Frames byte-identical to the in-request path for the same scripted answer | identical except ids and timestamps |

**No-go** on any of G1–G7 that a design change cannot fix within the spike:
S5 stops after AU27; the scaling story is S0's split pools plus AU5's
released connections, with larger stream pools per node, and the owner decides.

---

## AU29 — Provider streams as a request plus an incremental decoder (backend-only)

### Goal

- Do: every streaming provider (Anthropic, OpenAI chat completions and
  Responses, Google Gemini, the OpenAI-compatible base and its subclasses,
  Mistral, Ollama, Cloudflare where it streams) and the Messages translators
  expose `buildStreamRequest(AiCall)` and `streamDecoder(AiCall)` (`03` §7);
  their blocking loops call the decoder.
- Do: decoder tests from recorded SSE fixtures, fed whole **and** split at
  random byte boundaries (seeded): the chunks must be identical — the guard
  against a decoder that only works when the network splits nicely.
- Do not: change a provider request, a chunk, or an SSE frame.

### Vibe brief

> Implement AU29 as a `refactor(ai)`, one provider per commit. Provider
> request snapshots and every stream transcript unchanged. The split-feed
> test for each decoder with 200 seeds. Mutation: make one decoder drop a
> partial line at a buffer boundary, see the split-feed test fail.
> `make ci-local`, `make test-e2e`.

### Exit criteria

1. Every streaming provider has a decoder with split-feed tests.
2. Snapshots unchanged; gates green.

---

## AU30 — A stream pump reads provider streams outside the web workers (backend-only)

**Ask first:** §0 #18 (the `pump` role in the compose files).

### Goal

- Do: the pump role, the job queue, the run Redis Stream, `ToolStepCommand`,
  `FinishTurnCommand`, heartbeat, reaper and cancel of `03` §7–§8.
- Do: `RUNTIME.STREAM_PUMP` (`off` | `gateways` | `all`, global, default
  `off`) and `RUNTIME.STREAM_PUMP_PERCENT` (canary 0–100).
- Do: in this step the PHP stream worker still holds the client and tails
  the run stream itself. No capacity is gained yet; what is proven is that
  the pump produces the same answer.
- Do: **dual-mode equality**: `StreamTranscriptCharacterizationTest`, the
  widget, `/v1/chat/completions` and `/v1/messages` transcripts run with the
  pump off and on and must be identical (`01` §4).
- Do: failure drills as tests: pump gone ⇒ terminal frame; tool step fails ⇒
  the existing tool-error sentence; deadline ⇒ the existing cut-off copy.
- Do: the queue and run-stream tests of `03` §7–§8: no provider key or auth
  header in any Redis key (scan after a turn); an expired job body and a pump
  that dies before `GETDEL` both end their runs in a terminal entry; a queued
  run past its claim deadline is failed; entries carry `frame` / `terminal` /
  `gap`; a run past `RUNTIME.RUN_STREAM_MAX_BYTES` ends with the recoverable
  terminal and a complete stored reply; an attach from `N` returns only the
  frames after `N`.
- Do not: change an SSE event, a stored message, a usage row.

### Vibe brief

> Read `03` §7–§8 and the AU28 STATUS entry. Implement AU30 as a
> `feat(runtime)` (an operator gains a new role and switch). Dual-mode tests
> first, red until the pump writes the right frames. One channel per commit:
> gateways, then widget, then web chat. `make ci-local`, `make test-e2e` with
> the pump `off` and again with `all` (both runs in STATUS),
> `make test-e2e-full`, `make perf-runtime`.

### Exit criteria

1. Dual-mode equality on every channel.
2. `make test-e2e` green in both modes.
3. Drills green; no stuck "running" after any of them.

---

## AU31 — The watchdog holds AI streams so PHP never waits (backend-only)

**Ask first:** §0 #15, #19 (already ticked for AU6, re-confirm the relay).

### Goal

- Do: relay mode of `03` §6 and §8: Caddy sends the long routes to
  `127.0.0.1:8097` when `SYNAPLAN_RELAY=1`; the watchdog forwards to PHP with
  `X-Synaplan-Relay: 1`; PHP answers the attach directive when the pump
  handles the turn, otherwise the normal response, passed through byte for
  byte.
- Do: admission by stream slots (`WATCHDOG_STREAM_SLOTS`): full ⇒ the AU3
  `server_busy` 503, so AU4's client handling applies unchanged.
- Do: keep-alive comments, terminal guarantee, cancel on disconnect for
  gateways, `wait_ms` into the request timeline, `POST /drain`.
- Do: the streaming translators drop `set_time_limit(0)` for pump turns;
  `LongWaitGateTest` baseline empties.
- Do not: parse a frame; touch a turn the pump does not handle.

### Vibe brief

> Implement AU31. Go tests first: attach token check, copy loop, keep-alive,
> terminal on silence, cancel, admission, drain, pass-through byte for byte
> (a non-attach PHP response must reach the client unchanged, headers
> included). Then the PHP directive and the Caddy route. Load case in
> `make perf-runtime`: ≥ 500 streams of 10 s per server, API probe p95 ≤ 50 ms,
> hop ≤ 5 ms p95. Go tests also cover the `03` §8 contract: close on a
> `terminal` entry while the pump is healthy, `XREAD` from `0-{fromSeq}`,
> `id:` lines only when `sseIds` is true, `gap` ends with the directive's
> terminal. Walk J-AU-1 (full server). J-AU-6 needs AU31b; walk it again here
> with the relay on. `make -C sidecars/synaplan-watchdog test`,
> `make ci-local`, `make test-e2e` with relay on and off, `make test-e2e-full`.

### Exit criteria

1. Budgets of master plan §6 met with relay and pump on.
2. J-AU-1 walked, and J-AU-6 again with the relay on, in STATUS.
3. E2E green with relay on and off.
4. Rollout in two switches of different reach (§0 #24):
   `RUNTIME.STREAM_PUMP` is installation-wide (BCONFIG), so it ramps by
   `RUNTIME.STREAM_PUMP_PERCENT` on every server at once — safe without the
   relay, because each server's PHP stream worker tails the run (AU30).
   `SYNAPLAN_RELAY` is per server (env): one web node, 14 days, its capacity
   page and the event ring in STATUS before the next node.

---

## AU31b — An answer that loses its connection continues in the open page (ota-candidate)

### User-flow

Journey **J-AU-6**. Ben's answer is streaming when the server holding his
connection restarts (a deploy) or his network drops for a moment. Today the
open page ends the answer locally and reloads the turn from history when it
is stored (`frontend/src/services/chat/stream/handlers/memberError.ts:46–50`,
`recoverInterruptedTurn()`); live re-attachment happens only after a reload
or a navigation.

```text
  … the answer is streaming …
  ◌ Reconnecting — your answer continues in a moment.        (status line, ≤ 3 tries)
  … the answer continues from the next word, nothing repeated.

  after three tries:
  ⚠ The connection to Synaplan was lost. Your answer is still being written
    and appears here when it is ready. [ Reload the answer ]
```

### Goal

- Do: on a transport drop of a member turn with a known run id, the stream
  transport re-attaches (`GET /api/v1/messages/stream/attach?from=<last id>`)
  up to three times with backoff, and the handler continues the same message;
  only after that the existing history recovery runs.
- Do: the widget does the same for its run (`widgetsApi.ts` already has the
  attach call).
- Do: a Playwright `@ci` spec that cuts the connection mid-answer (route
  abort on the stream request) and sees the answer continue without a
  repeated word; Vitest for the cursor and the retry schedule.
- Do not: re-attach incognito drafts (they have no run); change SSE events.

### Vibe brief

> Copy in five locales and the status line first (U1). Implement in the
> transport and the member error handler, not in `ChatView.vue`. Works with
> today's in-request streams too (the run buffer exists now), so this step
> can land before AU31. Walk J-AU-6 on the dev stack by restarting the backend
> container mid-answer, light / dark / 320 px; enter the walk in STATUS.
> `make ci-local`, `make test-e2e`, layout suite.

### Exit criteria (with the UX contract §6 bullets)

1. J-AU-6 walked (U10).
2. Findability: the answer continues in the same bubble; nothing to look for (U2).
3. Copy says what still happens ("is still being written") in five locales (U3, U8).
4. Incognito and no-run turns keep today's behaviour; a run that ended while
   disconnected shows its stored answer (U5, U8).
5. Dark + V2 + 320 px (U9).

---

## AU32 — Chat, widget and both gateways stream through the watchdog by default (backend-only)

### Goal

- Do: after the 14-day soak on all web nodes: `SYNAPLAN_RELAY=1` in the
  compose files' defaults, `RUNTIME.STREAM_PUMP=all` seeded for new installs
  and set for existing ones by a migration `UPDATE` (AGENTS.md: BCONFIG
  defaults are bootstrap-only), the ZSET run buffer retired (attach reads the
  stream only), `docs/ARCHITECTURE.md` chat-stream section rewritten.
- Do not: remove the kill switches.

### Exit criteria

1. Fresh install (`make up` on an empty database): streams go through the
   watchdog; existing install after migration: the same.
2. Rollback drills, both in STATUS:
   - **one server**: `SYNAPLAN_RELAY=0` on one web node (env, a restart of that
     node only) brings back the PHP stream worker for its clients; the pump
     keeps serving all servers;
   - **the installation**: `RUNTIME.STREAM_PUMP=off` (BCONFIG, every server at
     once, no restart) sends new turns back to in-request generation
     everywhere, while turns already in the pump finish there.
3. Gates green.

---

## S5 exit

- A server holds ≥ 500 streams; the API never waits for them; no web worker
  holds a stream; every stream ends in a terminal frame.
- The architect's scaling question answered with production numbers
  (AU35).
