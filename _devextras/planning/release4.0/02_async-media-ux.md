# Feature 1 · UX — Detached Media Scheduling (DAG spawn, Jobs tray, toaster)

**Release:** 4.0 · **Priority:** P0 · **Status:** In progress — Sprint B shipped; in-conversation surface decided (Option B, dedicated banner)
**Companion:** [`01_async-media-jobs.md`](./01_async-media-jobs.md) (backend/architecture)
**Builds on:** [Multitask UX & Streaming Protocol Spec](../20260606-routing/09_multitask_ux_spec.md)

> The product promise: **when the DAG routing decides a task is a long render, the
> user sees it gracefully "spawn off" as a tracked background job, keeps working
> immediately, and is pulled back to the exact card with the finished media the
> moment it's ready.** This document is the design contract for that experience.

---

## 1. Design goals

1. **Make the invisible visible — elegantly.** Users should *understand* that the
   system parallelised their request (spawned a job off the plan) without being
   shown machinery. Confidence, not complexity.
2. **Never a dead end.** No spinner that might run forever, no card that silently
   dies. Every job visibly progresses and visibly resolves.
3. **Continue working is the default.** Detaching is not an interruption; the
   composer stays focused, the next message can be sent instantly.
4. **Come back effortlessly.** One click from a finished-toast or the tray lands
   the user on the precise card carrying the media — even across chats / reloads.
5. **Feel premium.** Smooth, restrained motion; consistent tokens; dark-mode
   safe; accessible. No jank, no layout shift.

## 2. Locked decisions (from product, 2026-06-22)

- Detach **all** media (image + video + audio).
- **Global** Jobs tray (across all chats), reachable from the app shell.
- Completion shows an **actionable toaster**; clicking jumps to the chat + card
  with the produced media.
- A **professional "spawned-off" visual** makes the detach moment clear.

---

## 2b. Surface decision — dedicated banner (Option B), locked 2026-06-27

The in-conversation surface for a detached render is a **dedicated status banner
on the assistant message** (`MediaJobStatus.vue`), **not** a sub-state of the
multitask `TaskCard`. This was evaluated against the original `TaskCard`
sub-state idea and chosen because:

- It is **one surface for every detach path** — single `/vid` `/pic` commands,
  natural-language media requests, and (later) multitask DAG media nodes all
  render the same banner. No risk of a `TaskCard` *and* a separate status widget
  fighting for the same bubble.
- It is **reload-trivial**: the banner is driven by the persisted `media_job`
  message meta + the poll/realtime status, so F5 / navigation "just works"
  without reconstructing live DAG cards from history.
- It keeps the multitask card contract **unchanged** (cards stay a
  streaming-time affordance that flattens on reload, per the multitask spec) —
  no scoped exception to that rule is needed.

**What this means for this document:** wherever the original draft described a
`TaskCard` "running in background" sub-state or a "fly-from-card-to-tray"
animation, the canonical surface is now the banner. The **global Jobs tray**,
**actionable completion toaster**, **jump-to-message**, reload resilience,
cancel, and a11y/i18n goals are all **unchanged** — they simply read from the
`mediaJobs` store and target the message that carries the banner.

### Status (what is already built)

| Area | State |
|------|-------|
| Backend job backbone, worker, reaper, deadline enforcement (Sprint A) | ✅ shipped + hardened |
| Detach **image + video + audio** uniformly behind `MEDIA.ASYNC_JOBS_ENABLED` (Sprint B) | ✅ shipped |
| `MediaJobStatus.vue` banner — running/failed/overdue/stalled/lost, elapsed, last-checked, **manual refresh**, progress, max-wait, model | ✅ shipped (Tailwind-only, token-driven, dark-mode safe) |
| Poll endpoint + Zod client (`/api/v1/media-jobs/{jobKey}`) | ✅ shipped |
| Reload contract: file attached to message + `media_job` meta → banner/video survive F5 | ✅ shipped |
| Advancer hardening: transient-retry, lock re-dispatch, callable-safe Redis serialize, `--recover` | ✅ shipped |
| Realtime push (Centrifugo `media_job.update` on `user:{id}`), `mediaJobs` store, actionable completion toaster (Sprint C) | ✅ shipped |
| Global Jobs tray + launcher/badge, jump-to-message highlight pulse, cancel | ⏳ Sprint D |

---

## 3. The experience, end to end

### 3.1 The "spawn-off" moment (the signature interaction)

When a media render is detached (a `/vid` `/pic` command, a natural-language
media request, or later a DAG media node), the assistant message immediately
shows the **`MediaJobStatus` banner** in place of an empty/streaming bubble.

```
[ user prompt ]
┌──────────────────────────── assistant message ───────────────────────────┐
│  ◐ Generating your video…                              [ Check status now ]│
│  This runs in the background and may take a few minutes. You can keep      │
│  chatting — this message updates when it's ready.                          │
│  Job still running · running for 0m 6s                                     │
│  Last checked 6s ago · next check in ~25s                                  │
│  Videos may take up to 20 minutes.   ▓▓▓░░░░░░░  12%   ·  Using veo-3.1…    │
└────────────────────────────────────────────────────────────────────────────┘
        │  (the tray launcher pulses + increments its active-jobs badge)
        ▼  user keeps typing — composer was never blocked
```

- **The banner is the canonical home of the result.** It stays on the message;
  on completion it is replaced *in place* by the actual media (see §3.3).
- **Copy:** a clear "Generating your {type}…" title + the background-work hint,
  so the user understands the system parallelised the work — confidence, not
  machinery.
- **Composer:** never blocked. The "thinking" indicator clears the moment the
  job is spawned (the turn is logically complete; the render continues detached).
- **Motion:** no per-card fly animation. The detach is taught by (a) the banner
  appearing instantly and (b) the tray launcher badge ticking up (a single
  pulse). Both respect `prefers-reduced-motion`.

### 3.2 While it runs

The banner (`MediaJobStatus.vue`, already shipped) shows, live:

- title + spinner, **elapsed time** ("running for 2m 15s"), **last-checked age**
  ("Last checked 12s ago · next check in ~25s") and a **manual "Check status
  now"** button — so the user is never staring at a frozen widget.
- a **progress bar** when the provider reports a percent, the **max-wait**
  guidance per type (video 20 min / image · audio a few minutes), and the model.
- distinct, localized, non-leaky sub-states: **overdue** (past max wait,
  resolving), **worker-stalled** (queue worker likely down — contact admin),
  transient **poll error** (retrying), and **job-no-longer-tracked**.
- The **tray launcher** badge (Sprint D) shows the count of active jobs.
- The user can open another chat, start another render, reload — jobs track
  independently and the banner rehydrates from persisted meta + status.

### 3.3 Completion → pulled back

On terminal success:

- The banner is replaced **in place** by the actual media (image thumb /
  `<video controls>` / audio player). This already works on reload via the
  persisted file; Sprint C makes it instant via realtime push (today it lands
  on the next poll tick).
- An **actionable toaster** appears (bottom, via the existing
  `NotificationContainer`): a thumbnail + "Your video is ready" + **View**.
  - Clicking **View** (or the toast body) → routes to the owning chat if not
    already there, scrolls the **message** into view, and plays a brief
    **highlight pulse** on it.
- The tray launcher badge decrements; the job moves to the tray's "Recent"
  section (kept for the session / 24h).

On failure / timeout:

- The banner flips to its **failed** state with the **localized, non-leaky**
  message from `MediaErrorMessageBuilder` (type-specific title) — already
  implemented — plus the **Retry with <model>** affordance (Sprint C/D).
- A toaster: "Couldn't create your video" + **Details** (jumps to the message).

### 3.4 The global Jobs tray

A slide-over panel (right side), opened from the shell launcher:

```
┌──────────────── Background jobs ─────────────────┐
│  Active (2)                                       │
│  ┌────────────────────────────────────────────┐  │
│  │ ⧖ Video · "sun over the sea"     ▓▓▓░░ 38%  │  │
│  │   chat: Marketing ideas        [Open] [Stop]│  │
│  └────────────────────────────────────────────┘  │
│  ┌────────────────────────────────────────────┐  │
│  │ ◐ Image · "logo concept"        queued      │  │
│  │   chat: Brand work             [Open] [Stop]│  │
│  └────────────────────────────────────────────┘  │
│                                                   │
│  Recent                                           │
│  ┌────────────────────────────────────────────┐  │
│  │ ✓ Video · "intro clip"   ▸ 2 min ago        │  │
│  │   chat: Launch          [Open] [⤓ Download] │  │
│  └────────────────────────────────────────────┘  │
│                                                   │
│  (empty state: "No background jobs. Long renders  │
│   like videos will appear here so you can keep     │
│   working while they finish.")                     │
└───────────────────────────────────────────────────┘
```

- Each row: kind icon, short prompt, owning chat name, live state/progress, and
  actions: **Open** (jump-to-card), **Stop** (cancel, while cancellable),
  **Download** (when done).
- Grouped **Active** / **Recent**. Active sorted by most-recently-updated.
- Real-time: rows update live via the same Centrifugo events that drive cards.

---

## 4. Component inventory

### Already shipped (frontend)

| Component / module | Responsibility |
|---|---|
| `components/MediaJobStatus.vue` | **The canonical in-conversation surface.** Renders running/failed/overdue/stalled/lost states, elapsed + last-checked, manual refresh, progress, max-wait, model. Tailwind-only + design tokens, dark-mode safe. Emits `completed` (adds the media part) + `update:mediaJob`. |
| `composables/useMediaJobPoll.ts` | 25s poll with terminal latch, manual `refreshNow()`, 404→lost, transient-error surfacing. (Polling is the fallback once Sprint C push lands.) |
| `services/api/mediaJobApi.ts` | Zod-validated `GET /api/v1/media-jobs/{jobKey}`. |
| `utils/messageMapper.ts` + `stores/history.ts` (`MediaJobInfo`) | Map/reconcile `mediaJob` on the message; terminal state is final (anti-flicker). |

### New (Sprint C/D)

| Component / module | Responsibility |
|---|---|
| `stores/mediaJobs.ts` (Pinia) | Source of truth for all jobs (active + recent). Subscribes to Centrifugo `media_job.update`, hydrates from `GET /media/jobs` on load, exposes `activeCount`, `jobsForMessage()`, `cancel()`. Patches the matching message's `mediaJob` in place (the banner is the projection). |
| `components/jobs/JobsTrayLauncher.vue` | Shell button + animated active-count badge; opens the tray. Lives in `SidebarV2` (and mobile top bar). |
| `components/jobs/JobsTray.vue` | The slide-over panel (Active/Recent groups, rows, empty state). |
| `components/jobs/JobRow.vue` | One job row (icon, prompt, chat, progress, Open/Stop/Download). |
| `components/jobs/JobCompletionToast.vue` *(or extend toast)* | Rich, **actionable** toast (thumbnail + label + View). See §6. |
| `composables/useJobNavigation.ts` | `jumpToJob(job)`: route to chat → wait for render → scroll the **message** into view → trigger highlight pulse. |

### Modified (Sprint C/D)

| File | Change |
|---|---|
| `composables/useNotification.ts` | Extend `Notification` with optional `action?: { label, onClick }` and optional `thumbnailUrl`/`icon` so a toast can be clickable. (Today toasts are message-only.) |
| `components/NotificationContainer.vue` | Render the optional action button / thumbnail; clickable body. |
| `components/ChatMessage.vue` | Wire the `mediaJobs` store push updates into the existing banner; add the highlight-pulse class toggled by `useJobNavigation`. |
| `components/SidebarV2.vue` | Mount `JobsTrayLauncher`. |

> **TaskCard is intentionally untouched.** Per the Option B decision (§2b), the
> multitask `TaskCard` keeps its current streaming-time contract; detached media
> surfaces via the banner on the message, never as a card sub-state. When DAG
> media nodes are detached (later sprint), the node simply yields a message-level
> `media_job` that drives the same banner + tray.

### Backend (contracts consumed here — defined in `01`)

- `GET /api/v1/media/jobs?message_ids=…` and `/jobs/{id}` → `toStatusArray()`.
- `POST /api/v1/media/jobs/{id}/cancel`.
- Realtime `media_job.update {job_id, message_id, node_id, chat_id, state,
  percent?, file?, error?, prompt?, kind?}` on the per-user channel.

---

## 5. State & data flow

```
spawn:   handler → MediaJobService.create → SSE complete{mediaJob:{job_id,type,state:running}}
                                              │   (+ persisted as message media_job meta)
 frontend: message.mediaJob = running  → MediaJobStatus banner renders  + mediaJobs.add(job)
                                              │
 live:    Centrifugo media_job.update ──▶ mediaJobs.patch ──▶ message.mediaJob patched (percent)
          (fallback today: 25s poll /media-jobs/{id} from the banner itself)
                                              │
 done:    media_job.update{state:done,file} ─▶ banner→media in place + toast(actionable) + tray Recent
                                              │
 reload:  GET messages (media_job meta + attached file) ──▶ banner/media rehydrate; resubscribe
 fallback: Centrifugo down → the banner's own 25s poll keeps it live
```

The `mediaJobs` store is the single front-end owner; the **`MediaJobStatus`
banner is a projection** of the message's `mediaJob`, so the in-conversation
surface and the tray row never disagree. (Until Sprint C, the banner self-polls;
the store + push simply replace that poll as the primary update path.)

---

## 6. Actionable toaster — the `useNotification` gap

Today `useNotification` toasts are **plain strings with no click target**, so
"click the toast to jump to the card" is not currently possible. Minimal,
backwards-compatible extension:

```ts
export interface Notification {
  id: string
  type: 'success' | 'error' | 'warning' | 'info'
  message: string
  duration?: number
  // NEW (all optional → existing callers unchanged):
  thumbnailUrl?: string
  action?: { label: string; onClick: () => void }
}
```

`JobCompletionToast` behaviour:
- success: thumbnail + "Your {kind} is ready" + **View** → `useJobNavigation.jumpToJob`.
- longer `duration` for media (e.g. 8s) so the user can react; persists in tray
  regardless.
- failure: error styling + **Details** → jump to the failed message (its banner).

## 7. Reload / navigation resilience (evolves the flatten rule)

With Option B the multitask flatten rule needs **no exception** — the banner is
not a DAG card, so the "don't reconstruct live cards from history" non-goal is
untouched. Reload resilience is already implemented and simpler:

- **Finished** turns flatten exactly as today (text + media in the bubble); the
  completed render is attached to the message as a `File` (+ legacy file field),
  so it renders on reload like any generated media.
- A message with an **active** `MediaJob` rehydrates the **banner** from the
  persisted `media_job` meta and resumes status updates (poll today, push in
  Sprint C), so an in-flight render survives F5 / navigation and resolves in
  place.
- Terminal state is final on the client (anti-flicker guard), so a stale running
  snapshot can never downgrade a finished banner.

This keeps history clean while honouring "come back effortlessly".

## 8. Motion & visual language

- **Spawn cue:** the banner appears instantly; the tray launcher does a single
  pulse + badge tick (no per-card fly animation). Respect
  `prefers-reduced-motion` (keep state-text + badge, drop the pulse).
- **Progress bar:** the banner's brand-filled track (`bg-brand` on a
  `--border-light` track), already implemented with tokens — no hardcoded hex.
- **Highlight pulse on jump:** 1.2s, two soft brand-tinted pulses on the message
  border (token `--brand`), no layout shift.
- **Tray:** slide-over from the right, 240ms; backdrop scrim on mobile, none on
  desktop (non-modal so users keep working).
- **Tokens only:** colors/spacing/radius from `style.css` variables; verify
  light + dark. No hardcoded hex.

## 9. Accessibility

- Tray is a labelled dialog/region; focus trap only on mobile (modal); desktop is
  non-modal and keyboard reachable from the launcher.
- Active-count badge has an `aria-label` ("2 background jobs running").
- Toaster is `role="status"` (polite) for success, `role="alert"` for failure;
  the action button is a real `<button>`.
- Progress conveyed as text ("rendering 38%") not color alone.
- All motion gated by `prefers-reduced-motion`.

## 10. i18n

All strings in **all four** locales (`en`, `de`, `es`, `tr`) under a new
`jobs.*` namespace (+ additions to `taskPlan.*`):

- `taskPlan.state.backgrounded` ("running in background"), `taskPlan.viewInTray`.
- `jobs.tray.title`, `jobs.tray.active`, `jobs.tray.recent`, `jobs.tray.empty`,
  `jobs.row.open`, `jobs.row.stop`, `jobs.row.download`, `jobs.inChat`.
- `jobs.toast.ready` ("Your {kind} is ready"), `jobs.toast.view`,
  `jobs.toast.failed`, `jobs.toast.details`, `jobs.relativeTime.*`.
- Cancel-billing nuance copy (Higgsfield): `jobs.cancel.queuedOnly` ("can only
  be stopped before rendering starts").

## 11. Edge cases

- **Multiple media nodes in one DAG** → multiple cards spawn; each tracks
  independently; tray lists each; badge counts all.
- **Job finishes while user is on the same card** → no toast jump needed; card
  resolves in place; still logged to tray Recent (toast optional/suppressed if
  card is on screen).
- **Fast image** → currently detaches uniformly and shows the banner briefly,
  resolving on the next status update. Once Sprint C push lands this feels
  near-instant. (The `MEDIA_JOB_IMAGE_INLINE_FAST_MS` inline-fast grace window is
  **deferred** — see note below; it is the planned optimisation to let a sub-second
  image resolve in the same turn with no banner. Tracked for Sprint C/F.)
- **Cancelled** → card + row show neutral "Stopped"; respect provider billing
  reality in copy.
- **Centrifugo down** → polling fallback keeps cards + tray live (slower cadence).
- **Result lost (Redis eviction before persist)** → degrade to a clear "couldn't
  confirm — please retry" card, never an infinite spinner.

## 12. Mapping to backend sprints (`01`)

| `01` sprint | UX delivered here |
|---|---|
| B (detach chat path) | ✅ **Done.** `MediaJobStatus` banner (running/failed/overdue/stalled/lost + elapsed/last-checked/manual-refresh/progress), composer stays free, reload-resilient, image+video+audio. |
| C (realtime + persist) | ✅ **Done.** `mediaJobs` store + Centrifugo `media_job.update` push as the primary completion path (banner self-poll is the fallback), actionable completion toaster (deep-links to the chat), instant in-place resolve via the shared `applyMediaJobUpdateToMessage` helper. (Tray-badge pulse moves to Sprint D with the tray.) |
| D (resilience + tray + cancel) | Global Jobs tray + launcher/badge, jump-to-message + highlight pulse, multi-job hydration endpoint, Stop/cancel. |
| E (cross-channel) | (Web UX stable; WhatsApp/Email delivery is channel-side.) |
| F (rollout) | Polish pass, reduced-motion/a11y audit, E2E, i18n completeness. |

## 13. Open design questions

1. Tray launcher placement: sidebar footer vs. top-bar icon (or both responsive)?
2. Should Recent persist across reloads (needs the 24h Redis record) or be
   session-only? (Leaning: show terminal jobs from the 24h window.)
3. Desktop notification opt-in in 4.0, or strictly in-app toaster? (Backlog.)
4. Cancel/"Stop" placement: tray row only, or also a Stop affordance on the
   banner? (Leaning: tray in Sprint D; add to the banner if users expect it.)
