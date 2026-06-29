# Feature 1 — Async Media Generation ("fire & continue")

**Release:** 4.0 (anchor feature) · **Priority:** P0 · **Status:** Planned
**Supersedes:** [Higgsfield bridge follow-up #8 (async)](../20260618-higgsfield-bridge-followups.md)
**Companion:** [`02_async-media-ux.md`](./02_async-media-ux.md) (the UI/UX spec) — this
file is the backend/architecture half; the UX half is locked there.

> Goal: a user can request a long-running render (image-to-video, long video,
> heavy image) and **immediately keep working**. The render runs on a background
> worker, always reaches a terminal state, and the result is **pushed back and
> persisted** so it lands whether the user stayed in the chat, opened another
> chat, or reloaded — on any channel.

---

## 1. Problem statement (observed)

- Routing is now correct: image-URL-in-text → Higgsfield IMG2VID. ✅
- But Higgsfield DoP Standard takes **~5–8 minutes** for a 5s clip (verified by
  a direct API test: `queued → in_progress → completed`, returned an mp4).
- The chat pipeline runs this **synchronously inline**:
  `StreamController` → `TaskPlanExecutor` → `MediaGenerationHandler::handleStream()`
  → `AiFacade::generateVideo()` → `HiggsfieldProvider::generateVideo()` which
  **blocks in `pollUntilTerminal()`** (`sleep()` loop, cap 12 min).
- Consequences: SSE held open for minutes (proxy/timeout fragile), a worker
  pinned for the whole render, and a UX we cannot honestly explain to users
  ("wait 8 minutes, don't reload").

We must detach the render from the request.

---

## 2. Current architecture (verified)

### Backend — what's there

- **Durable job backbone (built, UNWIRED):**
  - `MediaJob` (Redis value object) — states `queued/submitting/running/
    finalizing/completed/failed/cancelled/timed_out`, `heartbeat()`,
    `deadlineAt`, `percent`, `providerRef`, `result`, and
    `chatId/messageId/nodeId/trackId`.
  - `MediaJobStore` — Redis save/find + `findStale(cutoff)` (reaper) +
    `findByMessage(messageId)` (reload).
  - `MediaJobService` — all transitions (`markSubmitting/markRunning/
    updateProgress/markFinalizing/markCompleted/markFailed/markCancelled/
    markTimedOut`), `toStatusArray()` (client projection), per-type deadlines
    (video 20 min / image 4 min / audio 10 min).
  - **Missing:** the `MediaJobDispatcher` (referenced in docblock) and any
    worker/reaper; `MediaJobService::create()` has **no callers**.
- **Provider async contract (built):** `SupportsAsyncVideo` —
  `startVideoOperation()` (submit, return opaque handle), `pollVideoOperationOnce()`
  (one stateless poll → `{done,videoUri,error,status,percent}`),
  `downloadVideoRaw()`, `cancelVideoOperation()`. Implemented by **Higgsfield**
  and **Google/Veo**. `AiFacade` exposes `startVideoGeneration()` /
  `pollVideoOperation()` / `downloadVideoRaw()` / `cancelVideoOperation()`.
- **Sync media path (in use):** `MediaGenerationHandler` (single chat via
  `InferenceRouter`) and `MediaGenerationRunner` (multitask DAG). Both call the
  blocking `AiFacade::generateVideo()`.
- **Legacy async API (built, narrow):** `MediaGenerationService` +
  `MediaController` `/api/v1/media/video/start` & `/video/jobs/{id}` — Symfony
  **cache**-backed, **Veo-only**, **client-poll-advanced**, used by external
  integrations, not the chat UI. To be consolidated.
- **Realtime push (built):** Centrifugo (`RealtimeClient` on the frontend; used
  by human-takeover) — available to deliver "job done" events.

### Frontend — what's there

- SSE chat stream (`services/api/chatApi.ts`) emits `plan` / `task_update` /
  `task_file` events during the open request.
- `history` store holds `TaskCard` (`state`, `percent`, `url`, `capability`,
  `kind`); `TaskCard.vue` renders skeleton, a live progress bar
  (`showProgress`), per-card **Stop**, and **retry**.
- Today the card is fed **only while the SSE stream is open**. When the stream
  ends, live updates stop — the core gap for "continue working".

### The gap

There is no path where the request returns **before** the render finishes while
something else keeps advancing the job and later updates the (possibly reloaded)
UI. That path is this feature.

---

## 3. Target architecture

```
            create(queued)             dispatch                     push terminal
 request ───────────────▶ MediaJob ───────────▶ Messenger ──▶ worker ──▶ Centrifugo ──▶ client
   │  (returns running card w/ job_id)   │           advance loop          │   (+ persist file
   │                                     │       submit→poll→finalize       │    to BMESSAGES)
   ▼                                     ▼        (heartbeat each step)      ▼
 user keeps chatting              reaper (cron) times out stale jobs   card updates in place
```

### Key decisions

1. **One job system.** `MediaJob` (Redis) is the single source of truth.
   `MediaGenerationService`'s cache-jobs are migrated onto it (Sprint E).
2. **Worker model = self-re-dispatching Messenger steps, not a pinned worker.**
   Each advance does **one** non-blocking step (submit, or a single poll, or
   finalize) then re-dispatches itself with a short delay (`DelayStamp`,
   ~poll interval). This avoids pinning a worker for 8 min and matches the
   `pollVideoOperationOnce()` stateless design. (Alternative — one long worker
   job with internal sleeps off the request path — is simpler but holds a
   worker; rejected for the same worker-pool reason that motivated this work.)
3. **Provider-agnostic.** The advancer only uses `SupportsAsyncVideo` via
   `AiFacade`. Higgsfield + Veo work day one; new async providers need no
   advancer change.
4. **Completion delivery = push + persist.** On terminal success: download +
   save the file (existing `MediaGenerationHandler` persistence helpers /
   `downloadVideoRaw`), attach it to the originating `BMESSAGES` row, then push
   a Centrifugo event. The persisted file is what makes reload/navigation and
   cross-channel work.
5. **Model resolution stays put.** The job records the already-resolved
   `provider/model/modelId` (the migration principle from multitask routing).
   The advancer never re-derives the model.
6. **Reuse `MediaErrorMessageBuilder`** for all user-facing failure text
   (localized, non-leaky) — set as the job `error` projection.

---

## 4. Sprints

Each sprint is independently shippable, gate-green, and flagged.

### Sprint A — Finish the durable backbone (backend only, no UX change)

**Build the worker that the existing `MediaJob*` classes were designed for.**

- `MediaJobDispatcher` — `dispatch(MediaJob)` → Messenger `AdvanceMediaJobCommand{jobKey}`.
- `AdvanceMediaJobCommandHandler` (Messenger, on the existing worker):
  - load job; if terminal or past deadline → stop (reaper handles timeout).
  - state machine per call (ONE step):
    - `queued` → resolve provider via `AiFacade`, `startVideoOperation()`,
      `markRunning(providerRef = opaque handle)`, re-dispatch (delay = poll
      interval).
    - `running` → `pollVideoOperationOnce()`; `updateProgress(percent,status)` +
      `heartbeat()`; if `done` → `markFinalizing` + re-dispatch immediately; else
      re-dispatch (delay).
    - `finalizing` → `downloadVideoRaw()` + save to disk + build file descriptor
      → `markCompleted(result)`. On provider error → `markFailed(builderMessage)`.
  - all provider calls wrapped; exceptions → `markFailed` with
    `MediaErrorMessageBuilder` text (+ raw to logs only).
- `MediaJobReaper` — scheduled task (Symfony Scheduler or cron messenger) every
  ~30s: `findStale(heartbeatCutoff)` + `isPastDeadline()` → `markTimedOut`;
  best-effort `cancelVideoOperation()`.
- `BCONFIG` flag `MEDIA_ASYNC_JOBS_ENABLED` (default **off** this sprint).
- **Tests:** advance handler state transitions with a fake `SupportsAsyncVideo`
  (assert zero `sleep`, one step per call, terminal reached); reaper marks a
  stale job `timed_out`; deadline respected.
- **Gate:** no behaviour change (nothing creates jobs yet).

### Sprint B — Detach the chat/multitask media path

**Switch image-to-video / video (and optionally heavy image) to fire-and-detach.**

- In `MediaGenerationHandler` (and therefore `MediaGenerationRunner`), when
  `MEDIA_ASYNC_JOBS_ENABLED` and the resolved capability is a media render
  (**image, video, and audio** — locked decision: detach all media uniformly):
  instead of the blocking generate call,
  `MediaJobService::create(...)` with the resolved `provider/model/modelId`,
  prompt, `image_url`/reference, `chatId/messageId/nodeId/trackId`, then
  `MediaJobDispatcher::dispatch()` and **return a `running` placeholder**
  (`metadata.media_job = {job_id, type, state:'running'}`, no file yet).
- `StreamController`/`TaskPlanExecutor` surface the placeholder as a `task_update`
  card with `state:'running'` + `job_id` and **close the stream fast**.
- Persist the pending card to `BMESSAGE_TASKS` so a reload knows a job is live.
- Keep the synchronous path as the `else` branch (flag off / unsupported
  provider) — zero-regression fallback.
- **Tests:** with flag on, handler returns running+job_id without calling the
  blocking provider; with flag off, unchanged.

### Sprint C — Realtime completion + frontend background-jobs store

- On job terminal state, the advance handler/`MediaJobService` publishes a
  Centrifugo event to the user's channel: `{job_id, message_id, node_id, state,
  file?, error?}`; **and** attaches the produced file to the `BMESSAGES` row so
  it's durable.
- Frontend: a `mediaJobs` Pinia store subscribes (via existing `RealtimeClient`)
  and patches the matching `TaskCard` in the `history` store in place
  (`running → done` with `url`, or `failed` with localized message).
- Completion **toast/notification** ("Your video is ready") that deep-links to
  the message, shown even if the user is in another chat.
- **Tests (frontend):** store maps a completion event onto the right card;
  toast fires; failed event shows error, not a stuck skeleton.

### Sprint D — Reload / navigation resilience + Jobs tray + cancel

- On chat load, hydrate live jobs: `GET /api/v1/media/jobs?message_ids=…`
  (backed by `MediaJobStore::findByMessage`) → re-attach `running` cards with
  current `percent`; resubscribe Centrifugo.
- **Polling fallback** when Centrifugo is unavailable (reuse the existing
  `/video/jobs/{id}` projection generalised to `MediaJobService::toStatusArray`).
- Global **Activity / Jobs tray** (lightweight): list in-flight renders across
  chats with progress + cancel; mirrors the per-card Stop.
- **Cancel** wired end-to-end: UI → `POST /api/v1/media/jobs/{id}/cancel` →
  `markCancelled` + `cancelVideoOperation()` (note: Higgsfield only cancels
  while `queued`; once `in_progress` it bills — reflect that in UX copy).
- **Tests:** reload re-attaches a running card; cancel transitions job + calls
  provider cancel.

### Sprint E — Consolidate legacy path, cross-channel, billing

- Re-implement `MediaGenerationService::startVideoGeneration/checkVideoJob`
  (the `/api/v1/media/video/*` API) on top of `MediaJobService` (drop the
  `video_job_*` cache scheme). One job system, one projection.
- **Cross-channel async delivery:** WhatsApp/Email media requests are already
  "reply later" by nature — on job completion, deliver the file through the
  channel sender (the push consumer becomes channel-aware). Removes the inline
  blocking there too.
- **Billing at terminal state (#1146):** record `IMAGES/VIDEOS/AUDIOS` usage when
  the job reaches `completed` (and on `cancelled`/`timed_out` per provider
  billing reality). Apply the **Higgsfield refund nuance** from the docs:
  `failed`/`nsfw` and `cancel-while-queued` refund credits → do **not** bill
  those; only bill renders the provider actually charged.
- **Tests:** API path returns identical shape from the new backbone;
  billing recorded once at terminal; refunded states not billed.

### Sprint F — Rollout, observability, docs

- ✅ **Shipped:** flipped `MEDIA.ASYNC_JOBS_ENABLED` built-in default **on**
  (`MediaJobConfig`), seeded the explicit global ON row (`MediaJobConfigSeeder`,
  wired into `app:seed`), and grandfathered existing installs to an explicit
  per-user OFF row via data migration `Version20260629120000` (mirrors the
  multitask routing rollout `Version20260607000000`). Operators get a UI switch
  at **Settings → Processing → Async media generation**
  (`SystemConfigService` `MEDIA_ASYNC_JOBS_ENABLED`), which also clears the
  acting admin's own grandfather row so the change applies to them immediately.
- Admin: a simple jobs view (active/stale/failed counts, recent failures) from
  `MediaJobStore`.
- Metrics/logs: time-in-queue, render duration, timeout rate, failure reasons.
- Docs: update `docs/` + the Higgsfield follow-up doc (#8 → done here);
  developer note in `quick-dev-commands.md` for testing async locally.
- E2E: "send video-from-image → immediately send another message → first
  request's card flips to done when ready".

---

## 5. Data & contracts

- **Storage:** `MediaJob` stays in Redis (rationale already in its docblock:
  high-frequency ephemeral progress writes shouldn't hit Galera). The **durable
  artefact** is the saved file + its `BMESSAGES`/`BMESSAGE_TASKS` reference.
- **No destructive migrations.** `BMESSAGE_TASKS` already persists per-node card
  state; add a nullable `job_key` column (additive) to relink a card to its job
  on reload.
- **New endpoints (additive):**
  - `GET  /api/v1/media/jobs?message_ids=…` → list status projections.
  - `GET  /api/v1/media/jobs/{id}` → single status projection (generalises
    `/video/jobs/{id}`).
  - `POST /api/v1/media/jobs/{id}/cancel`.
- **Realtime event:** `media_job.update` `{job_id, message_id, node_id, state,
  percent?, file?, error?}` on the per-user channel.

## 6. Flags

| Flag (`BCONFIG`) | Default | Purpose |
|---|---|---|
| `MEDIA_ASYNC_JOBS_ENABLED` | **on** (default; existing installs grandfathered off, Sprint F) | Master switch: chat/multitask media (image **+** video + audio) uses jobs vs inline. |
| `MEDIA_JOB_POLL_INTERVAL_SECONDS` | 3 | Advance re-dispatch delay. |
| `MEDIA_JOB_IMAGE_INLINE_FAST_MS` | 1500 | Grace window: if a (fast) image job finishes within this on the first advance, resolve it in the same turn so quick images still feel instant — otherwise it detaches to the tray like any other job. |

## 7. Risks & mitigations

- **Redis loss mid-render** → job vanishes. Mitigation: reaper + the provider
  handle is also re-derivable from the message; a lost `running` card degrades to
  a "couldn't confirm — please retry" message, never a silent hang.
- **Double-advance / races** (reaper + re-dispatch) → use a per-job Redis lock
  (lock infra already exists) so only one advance runs at a time; terminal
  states are idempotent.
- **Centrifugo down** → polling fallback (Sprint D) guarantees delivery.
- **Provider bills on cancel-while-running** (Higgsfield) → UX copy + billing
  rules account for it; don't promise a refund we won't get.
- **Worker backlog** → jobs are short steps; cap concurrent advances; deadline
  reaper bounds worst case.

## 8. Definition of done

- Video-from-image request returns a `running` card in <2s; user can send the
  next message immediately.
- Result lands in place + as a notification after navigation/reload, on Web and
  at least one async channel (WhatsApp or Email).
- Every job provably terminates; reaper proven by test.
- Legacy `video_job_*` cache path removed; single job system.
- Billing correct (charged only when the provider charged us).
- Full gate + E2E green.

## 9. Decisions (resolved 2026-06-22)

1. ~~Detach images too?~~ → **Yes, all media detaches** (image + video + audio),
   uniform mechanism; fast images still resolve quick via the
   `MEDIA_JOB_IMAGE_INLINE_FAST_MS` grace window.
2. ~~Tray scope?~~ → **Global** tray across all chats (see `02_async-media-ux.md`).
3. ~~Completion delivery?~~ → **Actionable toaster** that jumps to the chat + the
   card carrying the media (see `02_async-media-ux.md`).
4. Notifications: in-app toaster for 4.0; browser/desktop push deferred (backlog).
5. Retention: keep terminal job records in Redis **24h** (then the `BMESSAGES`
   file is the durable record). Revisit if the tray needs longer history.
