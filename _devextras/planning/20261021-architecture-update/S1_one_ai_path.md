# S1 — One AI path with hooks

Master plan §5 rows AU9–AU13; architect direction 1. Contract:
[`03_contracts.md`](./03_contracts.md) §1. Loop:
[`01_vibe_coding_loop.md`](./01_vibe_coding_loop.md).

**Shape of the sprint:** pin every path first (AU9), move the existing
pre/post logic into a pipeline without changing a byte (AU10), then let the
pipeline own metering (AU11), bring the paths that bypass the facade in
(AU12), and measure every call (AU13). No screen changes.

---

## AU9 — Characterization of every AI call path (backend-only)

### Goal

- Do: `tests/Characterization/AiPathCharacterizationTest.php` with
  `__snapshots__/ai_paths.json`. One case per path of the master plan F1 /
  STATUS code map: in-app stream, buffered chat (email), widget stream,
  `/v1/chat/completions` buffered and streamed, `/v1/embeddings`,
  `/v1/images/generations`, `/v1/messages` passthrough (Anthropic) and
  translated (OpenAI, Gemini), sorter, memory extraction, vectorize, LLM
  rerank, Perplexity web answer, media image, transcription, synthesis,
  model probe.
- Each case records: provider requests (`ProviderHttpRecorder`,
  `OpenAISdkRecorder`, masked), `BUSELOG` rows written (action, provider,
  model, tokens, cost — rounded), rate-limit checks made, circuit-breaker and
  model-health calls, the value returned.
- Do not: change production code, except a seam that only makes a transport
  recordable, moved verbatim (V4 decision #7 exception).

### Vibe brief

> Read `03` §1 and the STATUS code map of 2026-10-21. Implement AU9 as a
> `test(ai)` pull request. Use the existing recorders and `OfflineHttpGuard`
> so no case reaches the network. Record on the untouched code with
> `UPDATE_AI_PATH_SNAPSHOTS=1`; read every line; list in STATUS the paths
> that write no usage row today (expected: Perplexity web answer, LLM
> rerank, probes) — these become AU11's expected additions. Mutation check:
> remove one `recordUsage()` call, see the snapshot fail, restore.
> `make ci-local`.

### Exit criteria

1. Snapshot covers every path of the list; no network access.
2. The paths without a usage row are listed in STATUS with their file and line.
3. Mutation check recorded. `make ci-local` green.

---

## AU10 — One call pipeline with ordered hooks under AiFacade (backend-only)

**Precondition (AU0b):** the hardening of `03_contracts.md` §1.7 — the
`AiFacade` method map and the pipeline value shapes — is merged. AU10
must not start before it; its capability commits follow the merged map.

### Goal

- Do: `App\AI\Pipeline\` with `AiCall`, `AiCallContext`, `AiCallResult`,
  `AiCallTimings`, `AiCallPipeline` (`run()`, `stream()`), `AsAiCallHook`,
  `AiCallHookInterface`, `StreamingAiCallHookInterface` (`03` §1.1–§1.2).
- Do: every public `AiFacade` method builds an `AiCall` and runs the
  pipeline; `executeWithHealth()` / `observing()` become
  `RequestContextHook`, `CircuitBreakerHook`, `ModelHealthHook` (`03` §1.3).
  `AiFacade` shrinks (size ledger entry drops or disappears).
- Do: `HookContractTest`: hook order pinned; a non-blocking hook may not
  inject `HttpClientInterface`, `AiFacade`, `EntityManagerInterface` or a
  repository (`03` §1.4).
- Do: `make perf-runtime` gains the pipeline case: 1,000 calls with the
  default hooks and a no-op provider, ≤ 1 ms p95 overhead.
- Do: `docs/ARCHITECTURE.md` "AI call pipeline" section.
- Do not: move metering (AU11); change any caller; change a return shape.

### Vibe brief

> Read `03` §1. Implement AU10 as a `refactor(ai)`. `AiPathCharacterizationTest`,
> `StreamTranscriptCharacterizationTest`, `MessageTurnCharacterizationTest`
> and the provider request snapshots must stay byte-identical. One capability
> per commit (chat, chat stream, embed, vision, image, video, speech), each
> green. Unit tests for the pipeline: order, refusal before the call, after
> hooks on failure, hook timings, a blocking hook that returns over its budget in
> `before()` (inject a clock: `onError` applied on return, only logged in `after()`). Mutation: swap two hook priorities, see `HookContractTest` fail.
> `make perf-runtime` before/after. `make ci-local`, `make test-e2e`.

### Exit criteria

1. Every snapshot unchanged.
2. `AiFacade` smaller (lines before → after in STATUS), no new ledger entry.
3. Pipeline overhead ≤ 1 ms p95 (`make perf-runtime`).
4. `make ci-local`, `make test-e2e` green.

---

## AU11 — Every AI call is metered once, in the pipeline (backend-only)

### Goal

- Do: `RateLimitHook` (before), `UsageHook` (after) and `LegacyUsageWriter`
  as `03` §1.3 and §1.5. `AI.USAGE_HOOK` (`legacy` | `shadow` | `on`, BCONFIG
  global). Every caller's AI-action `recordUsage()` call moves into
  `LegacyUsageWriter` unchanged; `AiCallSource` gets the "joined" constant for
  the paths that run through `AiFacade` today.
- Do: part 1 (this pull request) ships `legacy` as the default and lets an
  installation choose `shadow`. Part 2 (`fix(ai): …`, after the seven-day
  production soak in `shadow`) makes `on` the default. Callers keep their
  `LegacyUsageWriter` call: it is a no-op for joined paths under `on` and the
  writer again under `legacy` / `shadow`, so a rollback never leaves a call
  unmetered.
- Do: `/v1/messages` and live voice stay "not joined": their own writers keep
  writing under every mode until AU12.
- Do: the paths AU9 listed without a row get one once joined and `on`;
  probes stay unmetered.
- Do not: change prices, actions or the cost calculation; touch non-AI usage
  (`TOOLS`, `MCP_TOOL`, `COMPUTE_RUNS`, `WEB_SEARCH`, storage, message
  counters — they move with S4).

### Vibe brief

> Read `03` §1.5. Implement AU11 part 1, including `TurnUsageLedger` and the
> per-turn / per-call unit of each `AiAction`. Tests first, per AU9 path **and
> per mode**: exactly today's rows under `legacy`, `shadow` and `on` — for a
> gateway turn with three MCP rounds and a two-round document tool loop that
> is one `MESSAGES` row with the summed tokens, not one per round; under `shadow` the shadow row equals the caller's row (action,
> provider, model, tokens, cost) and a deliberately wrong shadow logs a
> mismatch; `/v1/messages` and live voice write one row under all three modes.
> Snapshot unchanged under `legacy` and `shadow`. Part 2 after the soak: flip
> the default to `on`, re-record with `UPDATE_AI_PATH_SNAPSHOTS=1` — the diff
> may only add rows for the listed paths and remove none; quote it.
> `make ci-local`, `make test-e2e`.

### Exit criteria

1. Part 1: today's rows on every path under every mode — one per turn for
   per-turn actions (multi-round turns included), one per call for per-call
   actions; message-quota counts unchanged; shadow equality; snapshot
   unchanged under `legacy` / `shadow`.
2. Soak: seven days on production in `shadow` without `ai.usage_mismatch`
   (event ring summary in STATUS).
3. Part 2: one row per billable call on every path with `on`; the diff quoted;
   switching back to `legacy` keeps one row per call (test).
4. No AI-action `recordUsage()` call outside `UsageHook` and
   `LegacyUsageWriter` (grep in STATUS); non-AI usage rows untouched.

---

## AU12 — The Messages gateway, live voice and direct provider calls join the pipeline (backend-only)

### Goal

- Do: `MessagesGateway` translators run as the transport of an
  `AiCall(capability: passthrough | chat_stream)` (`03` §1.6); the wire
  stays byte for byte.
- Do: live voice opens and closes one `AiCall(voice_session)`;
  `VoiceLiveUsageService` writes through the pipeline.
- Do: Perplexity web answers through `AiFacade::chat()`; probes with
  `AiCallContext::probe()`.
- Do: metering moves with each path in the same commit (`03` §1.5 step 3):
  the path's source gets the "joined" constant, its own writer becomes a
  `LegacyUsageWriter` call, and the per-mode test of AU11 is extended to it —
  so no commit, and no release, leaves a gateway or voice call unmetered.
- Do: after one release with `on` everywhere, the follow-up removal of
  `LegacyUsageWriter` and the setting (`03` §1.5 step 5) is its own pull
  request, `refactor(ai): metering has one writer and no switch`.
- Do: `AiPathOnlyThroughPipelineTest`: no class outside `App\AI\Pipeline`,
  `App\AI\Provider` and the translators calls a provider's capability method
  directly (reflection scan; shrink-only baseline if any remain).
- Do not: change a Messages wire byte; change what the voice sidecar does.

### Vibe brief

> Implement AU12 as a `refactor(ai)`. The Messages gateway's stream and
> buffered transcripts (record them first if `AiPathCharacterizationTest`
> lacks a case, in a `test(…)` commit) must stay identical; one translator
> per commit. `MessagesGateway` shrinks. Mutation: call the provider directly
> in one place, see `AiPathOnlyThroughPipelineTest` fail. `make ci-local`,
> `make test-e2e` (the gateway specs and the live voice specs named in the
> pull request).

### Exit criteria

1. Every snapshot unchanged; `MessagesGateway` smaller.
2. `AiPathOnlyThroughPipelineTest` green with an empty baseline, or a
   baseline listed in STATUS with a reason per entry.
3. One `BUSELOG` row per billable gateway and voice call under `legacy`,
   `shadow` and `on` (the AU11 per-mode test, extended).
4. `make ci-local`, `make test-e2e` green.

---

## AU13 — Every request and AI call timed from start to end (backend-only)

### Goal

- Do: `AiCallMetricsHook` (`03` §1.3, §9) and `RequestTimelineRecorder`
  (`kernel.request` / `kernel.terminate`, Doctrine middleware for `db_ms`)
  writing the record of `04` §3 to the Redis ring and the counters to
  `metrics:{serverId}:…` (`03` §9).
- Do: `GET /api/v1/admin/capacity/requests` and `…/ai-calls` (admin only,
  Redis only, OpenAPI annotated) — the data for AU33.
- Do: the privacy test of `04` §3 (secrets and content never reach Redis).
- Do: the measuring cost ≤ 0.3 ms per request (`make perf-runtime`).
- Do not: build the UI (AU33); add a table.

### Vibe brief

> Read `04` §3 and `03` §9. Implement AU13 as a `feat(observability)`. Tests
> first: the record's fields for a chat stream, a gateway call and a file
> upload; bounded labels; the privacy test with a prompt, a key and an email
> in the request; the ring's size and TTL. `make perf-runtime`: per-request
> cost ≤ 0.3 ms, pipeline still ≤ 1 ms. `make ci-local`, `make test-e2e`,
> `make -C frontend generate-schemas` and `vue-tsc` (new schemas, no consumer yet).

### Exit criteria

1. Records and counters for every route class; privacy test green.
2. Cost budgets met (`make perf-runtime`).
3. `make ci-local`, `make test-e2e` green.

---

## S1 exit

- Every AI call passes `AiCallPipeline`; metering in one place; every call
  timed; snapshots prove nothing else moved.
- `AiFacade` and `MessagesGateway` smaller; no new ledger entry.
- The architect's first direction answered with numbers: pipeline overhead
  and per-hook time on the capacity page data (AU13).
