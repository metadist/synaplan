# Release 4.0 — Master Plan

**Status:** Planning (started 2026-06-22)
**Themes:**

1. **"Long-running work must never block the user."** Make Synaplan feel instant
   even when the underlying AI work (video renders, big documents, multi-step
   plans) takes minutes — the user fires a request, keeps working, and results
   land when ready, wherever the user is. *(Feature 1)*
2. **"One home for every file."** A fast, complete, well-organised file world
   where uploads, integration pushes (Synamail/Nextcloud/OpenCloud), and **all
   AI-generated media** live together — with vectorization status and knowledge
   group obvious at a glance. *(Feature 2)*

These themes meet at a seam: an async render's result is delivered to the user
*and* saved as a findable file in one step.

This is the hub document for the 4.0 release. Each feature has its own detailed
plan file in this folder. Features are independently shippable behind flags; the
release ships when the P0 features are GA and the rest are at least
flag-on-internally. More features will be added to the [index](#feature-index)
as we scope them.

---

## Why this release exists (the trigger)

A real request — *"Kannst Du ein Video von diesem Bild erzeugen … `https://…/pexels-photo.jpeg`"* — now routes correctly to Higgsfield image-to-video (fixed in the
[Higgsfield bridge follow-ups](../20260618-higgsfield-bridge-followups.md) work),
**but the render takes ~5–8 minutes** and the current pipeline runs it
**synchronously inline** in the chat request. That:

- holds an SSE connection open for the whole render (proxy/timeout-fragile),
- blocks a FrankenPHP worker for minutes (worker-pool exhaustion under load),
- leaves the user staring at a "Generating video… (320s)" spinner, unable to
  trust whether it will finish, and
- is impossible to explain to end users ("just wait 8 minutes and don't reload").

The fix is architectural, not cosmetic: **detach long jobs from the request,
run them on a background worker, and deliver the result via realtime push** so
the user can keep working and get notified when it's done. This was already
anticipated — see Higgsfield follow-up **#8 (async)** and the half-built
`MediaJob` backbone (below). Release 4.0 finishes and generalises it.

---

## What already exists (do NOT rebuild)

The codebase already contains a **well-designed but unwired** durable job
backbone. The anchor feature finishes and wires it rather than inventing a new
one.

| Component | State | Notes |
|---|---|---|
| `App\Service\Media\MediaJob` | ✅ built | Redis-backed value object: full state machine (`queued→submitting→running→finalizing→completed/failed/cancelled/timed_out`), heartbeat, deadline, percent, `providerRef`, `result`, and `chatId/messageId/nodeId/trackId` linkage. |
| `App\Service\Media\MediaJobStore` | ✅ built | Redis persistence + indexing (`findStale`, `findByMessage`). |
| `App\Service\Media\MediaJobService` | ✅ built | State-machine transitions + `toStatusArray()` client projection (`running/done/failed/cancelled`) + per-type deadlines. |
| `MediaJobDispatcher` | ❌ missing | Referenced in `MediaJobService` docblock; never created. **No worker advances jobs.** |
| Wiring into chat/multitask | ❌ missing | `MediaJobService::create()` has **zero callers**. The chat path still calls `MediaGenerationHandler` → `AiFacade::generateVideo()` **synchronously**. |
| `App\AI\Interface\SupportsAsyncVideo` | ✅ built | `startVideoOperation / pollVideoOperationOnce / downloadVideoRaw / cancelVideoOperation`. Implemented by `HiggsfieldProvider` **and** `GoogleProvider` (Veo). |
| `MediaGenerationService` + `MediaController` (`/api/v1/media/video/start`, `/video/jobs/{id}`) | ✅ built, narrow | A **second**, older async path: Symfony-**cache**-backed, **Veo-only**, **client-poll-driven** (each GET advances one step). Used by external API/Nextcloud, **not** the chat UI. To be consolidated onto `MediaJob`. |
| Realtime transport (Centrifugo) | ✅ built | `RealtimeClient` (frontend) + human-takeover push already use it — a ready channel for "your video is done" events. |
| Frontend task cards | ✅ built | `history` store `TaskCard` (state/percent/url) + `TaskCard.vue` skeleton/progress bar, fed today only by in-stream `task_update` events. |

**Net:** ~60% of the backend async backbone is already there. The work is
finishing the worker/dispatcher/reaper, switching the chat path to
fire-and-detach, and building the realtime + reload-resilient frontend UX.

---

## Release principles (apply to every feature)

1. **Never block the request on slow work.** Anything that can exceed ~10s
   becomes a detached job. The request returns a `running` placeholder fast.
2. **Always reach a terminal state.** Every job ends `completed/failed/cancelled/
   timed_out` — guarded by a heartbeat + reaper. No silent 95%-forever cards.
3. **Result follows the user.** Completion is pushed (Centrifugo) and persisted
   to the message, so it lands whether the user stayed, navigated away, or
   reloaded — and on any channel (Web/WhatsApp/Email/API).
4. **Clear, honest, non-leaky messaging.** Status and errors are user-friendly
   and localized (reuse `MediaErrorMessageBuilder`); never leak provider/system
   internals.
5. **Additive + flagged.** Additive migrations only; every behaviour change sits
   behind a `BCONFIG` flag with a safe default and a synchronous fallback.
6. **Zero regression on the fast path.** Plain chat, slash commands, and widgets
   keep their current latency and behaviour.
7. **Gate-green every step** (`make lint && make -C backend phpstan && make test
   && docker compose exec -T frontend npm run check:types`).

---

## Locked product decisions (2026-06-22)

These were decided with the product owner and are now fixed for 4.0:

1. **Detach ALL media** — images **and** video/audio become background jobs (not
   video-only). Images are usually fast, so a fast image still resolves inline-
   quick; but the *mechanism* is uniform, so nothing long ever blocks.
2. **Global Jobs tray** — one tray for the whole app (across all chats), always
   reachable from the shell, showing every in-flight and recently-finished
   render with live progress.
3. **Actionable completion toaster** — when a job finishes, a toast pops; clicking
   it **jumps the user back to the exact chat + card that carries the produced
   media** (opens the chat if needed, scrolls to and highlights the card).
4. **Professional "spawned-off-the-DAG" visualisation** — when the planner's DAG
   routing decides a node is a long render, the UI clearly and elegantly shows
   that the task was *spawned off as a background job* (a deliberate, polished
   transition from inline card → tracked background job), so users understand
   the system is working for them while they continue.

Full design in [`02_async-media-ux.md`](./02_async-media-ux.md).

## Feature index

| # | Feature | File | Priority | Status |
|---|---|---|---|---|
| 1 | **Async media generation ("fire & continue")** — backend/architecture | [`01_async-media-jobs.md`](./01_async-media-jobs.md) | P0 | Planned |
| 1·UX | **Async media UX** — DAG spawn visual, global Jobs tray, actionable toaster, jump-to-card | [`02_async-media-ux.md`](./02_async-media-ux.md) | P0 | Planned |
| 2 | **File management world** — one home for every file: sources, vectorized status, groups, generated-media gallery, **auto-foldered "AI generated" library** ([§11](./03_file-management.md#11-ai-generated-files-auto-foldering--categorization)) | [`03_file-management.md`](./03_file-management.md) | P0 | Planned |
| 3 | **Image & first-boot optimization** — multi-arch (arm64) base image, baked dev deps, custom `bge-m3` Ollama image; fast `docker compose up` on Mac | [`04_image-build-optimization.md`](./04_image-build-optimization.md) | P1 | Planned |
| 4 | _TBD — to be added as we scope the rest of 4.0_ | — | — | Backlog |

> "We have more things to implement" — this index is the place to add them.
> Each new 4.0 feature gets a `0N_<slug>.md` and a row here. Candidate backlog
> seeds (not yet committed): background jobs for long document generation,
> push/desktop notifications, cross-channel async delivery hardening, and the
> deferred Higgsfield billing-refund correctness item.

> **Synergy note:** Features 1 and 2 share a seam — when an async `MediaJob`
> completes, the *same* finalize step both delivers the result to chat/tray AND
> registers a `BFILES` row, so every generated video/image/audio lands in the
> File management world's Generated gallery. That finalize step also stamps the
> artefact's **origin kind** so it auto-folders into the **"AI generated"**
> library by category (Images/Videos/Audio/Documents/Calendar — Feature 2
> [§11](./03_file-management.md#11-ai-generated-files-auto-foldering--categorization)).
> Build Feature 1 Sprint C/E and Feature 2 Sprint A together.

---

## Anchor feature at a glance (full detail in `01_async-media-jobs.md`)

```
User sends "video from <image>"  ──▶  StreamController / TaskPlanExecutor
        │                                        │
        │  (long-render capability detected)     ▼
        │                               MediaJobService::create(queued)  ──▶  Redis
        │                                        │
        ▼                                        ▼
  SSE returns FAST with a                MediaJobDispatcher → Messenger
  "running" card carrying job_id                 │
        │                                        ▼
        │                          worker: submit → poll → finalize (short steps)
        │                                        │   (heartbeat each step)
   user keeps working                            ▼
        │                               markCompleted(file) / markFailed
        │                                        │
        ▼                                        ▼
  Centrifugo push  ◀───────────────────  "job terminal" event
        │
        ▼
  card updates in place + toast "Your video is ready"
  (also persisted to BMESSAGES → survives reload / shows on any channel)

  reaper (scheduled): times out stale jobs past deadline → markTimedOut
```

---

## Sprint sequencing (anchor feature)

The detailed plan breaks the anchor into 6 sprints (A–F). High level:

- **A — Finish the durable backbone:** `MediaJobDispatcher` + Messenger advance
  handler + reaper (scheduled), provider-agnostic via `SupportsAsyncVideo`,
  wired for Higgsfield + Veo. Backend-only, no UX change yet.
- **B — Detach the chat path:** chat/multitask media routes create a job and
  return a `running` card immediately (flag `MEDIA_ASYNC_JOBS_ENABLED`).
- **C — Realtime completion + frontend store:** Centrifugo push, in-place card
  update, completion toast, file persisted to the message.
- **D — Reload/navigation resilience + Jobs tray + cancel.**
- **E — Consolidate legacy `MediaGenerationService`; cross-channel async
  delivery; billing at terminal state (#1146) + cancel-refund correctness.**
- **F — Rollout, flags-on, docs, observability/admin job view.**

See [`01_async-media-jobs.md`](./01_async-media-jobs.md).

---

## Definition of done (release)

- A 5–8 min video render never blocks the chat request; the user can send the
  next message immediately.
- Closing the tab, navigating away, or reloading does **not** lose the result —
  it appears in place (and as a notification) when ready.
- Every job provably terminates (completion, failure, or reaper timeout); no
  stuck cards.
- The legacy cache-based video-job path is gone (one job system).
- Every AI-generated file (image/video/audio/Word & Office docs/calendar) is
  auto-foldered into the **"AI generated"** library by category, with no manual
  filing (Feature 2 §11).
- AI-generated files count toward the user's storage quota (shown separately) and
  are deletable, with deletion always quota-correct (Feature 2 §12).
- Full gate green; E2E covers "send video request → keep chatting → result
  lands".
```
