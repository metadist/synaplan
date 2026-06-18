# Sprint 4 — Parallel Execution: Design Spike & Decision

**Decided 2026-06-08.** Approach: **hybrid** — text nodes stay sequential +
token-streaming; heavy media nodes (image/video/text2sound) run **concurrently**.
Gated by `MULTITASK_PARALLEL_ENABLED` (default OFF → today's sequential behaviour,
unchanged).

## Why hybrid (not full async)

- For the canonical "one heavy media task ∥ some text" shape (e.g. dog+mp3), the
  critical path is the media node, so hybrid captures ~all the wall-clock win
  (~23s → ~14s). Full async only beats hybrid for the rarer "several independent
  LLM text nodes" shape.
- Full async would have to rework the **shared** `chat`/`chatStream` provider path
  used by ALL normal chat (platform-wide blast radius) and multiplex concurrent
  SSE token streams. Hybrid leaves the proven text-streaming path untouched.

## Findings (codebase)

- Providers (`GoogleProvider`, `OpenAIProvider`, …) use Symfony
  `HttpClientInterface` (lazy/async-capable in principle).
- BUT `AiFacade::generateImage/generateVideo/synthesize` are **blocking
  end-to-end**: they issue the request, read the response, then download + save
  the file inline. They are not structured as "kick off → collect", so true
  in-process lazy-async would require splitting these shared methods (medium risk,
  and the download/save still blocks).
- Text path: `ChatRunner` → `AiFacade::chatStream` streams tokens (blocking loop).

## Chosen mechanism: subprocess offload for media nodes

Run each independent media node as a **separate `bin/console` process**
(Symfony `Process`), concurrently, while text nodes run inline in the request
process (preserving token streaming). This needs **no changes to shared
AiFacade/provider internals** — the subprocess just reuses the existing
`MediaGenerationRunner` path in a clean process with its own DB/HTTP connections
(safe under FrankenPHP, unlike `pcntl_fork`).

- New command `app:multitask:run-media-node` (hidden, internal):
  `--user-id`, `--capability` (image_generation|video_generation|text2sound),
  `--prompt`, `--params` (JSON), `--language`. It builds a minimal synthetic
  message + single-node context, runs the existing runner, and prints the
  resulting file descriptor `{path,type,local_path}` (+ any error) as JSON.
- Trade-off accepted: ~100–300 ms spawn overhead per media node (negligible vs a
  ~14 s image), and no live progress *inside* a media card during generation
  (media cards already show skeleton → done; images don't token-stream).

## Execution model (DagExecutor parallel strategy)

Wave-based scheduling; at each step the "ready set" = nodes whose deps are all
`done`:

1. For each ready node:
   - **media kind** → launch its subprocess (background), record the handle. Emit
     `task_update: running` immediately (card shows skeleton).
   - **text/other kind** → run inline (streaming) as today.
2. Before running any node whose deps include an in-flight media node (and before
   `compose_reply`), **await** those subprocesses, parse results, set NodeResults,
   emit `task_file` + `task_update: done/failed`.
3. **Concurrency cap** (`MULTITASK_MAX_PARALLEL`, default 3) bounds simultaneous
   subprocesses to protect provider rate limits (esp. Groq/Gemini) and memory.
4. **Deterministic assembly**: results are keyed by node id and assembled in plan
   order regardless of completion order (ResultAssembler already does this).
5. Failure isolation unchanged: a failed media subprocess → that node failed →
   dependents skipped; whole-plan failure → legacy fallback.

Sequential mode (flag OFF) = the existing topological loop, byte-for-byte.

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| Subprocess can't reach DB/providers | It boots the full kernel (same env/secrets); reuse existing services. Tested in CI test stack. |
| Rate-limit 429 storm | Hard concurrency cap; cap default 3; configurable. |
| Spawn overhead dominates for tiny plans | Only media nodes offload; plans with no independent media run inline. |
| Non-determinism | Assemble by node id in plan order; tests assert deterministic output across N runs. |
| FrankenPHP worker safety | Separate OS processes (not fork) — clean, isolated. |
| Hung provider blocks the wait | Per-subprocess **timeout** (config, default 120 s) → node failed, isolated. |

## Scope / steps

1. `MultitaskRoutingConfig`: `isParallelEnabled()` (exists) + `maxParallel()` +
   `nodeTimeoutSeconds()` (new BCONFIG keys, defaults 3 / 120).
2. `app:multitask:run-media-node` console command (reuses MediaGenerationRunner).
3. `DagExecutor`: extract a scheduler; add the parallel wave strategy using
   Symfony Process for media nodes; keep sequential as default.
4. Tests: scheduler unit tests with a fake "process runner" (no real spawning) —
   chains, independent media ∥ text, cap respected, timeout → failed, determinism,
   sequential-equivalence when flag off. Plus the existing suite stays green.
5. Live smoke: dog+mp3 with flag on → wall-clock < sequential; cards still render.

## Non-goals (now)

- Concurrent independent **text** nodes (full async) — follow-up if multi-text
  plans become common.
- Cross-channel parallel delivery (Sprint 5).
