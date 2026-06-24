# Feature 1 · UX — Detached Media Scheduling (DAG spawn, Jobs tray, toaster)

**Release:** 4.0 · **Priority:** P0 · **Status:** Planned
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
- A **professional "spawned-off-the-DAG" visual** makes the detach moment clear.

---

## 3. The experience, end to end

### 3.1 The "spawn-off" moment (the signature interaction)

When the planner emits a DAG and a node resolves to a long-render media
capability, the task card is born *already knowing* it will detach.

Sequence in the task-plan bubble:

```
[ user prompt ]
┌─────────────────────────────── Task plan ───────────────────────────────┐
│  ◐ Video from your image            running · spawning background job…    │
│     ░░░░░░░░░░░░░░░░░░░░  (shimmer)                                        │
└──────────────────────────────────────────────────────────────────────────┘
        │  (≈800ms: card lifts, a subtle "fly-to-tray" chip animates
        │   toward the tray launcher, which pulses + increments its badge)
        ▼
┌─────────────────────────────── Task plan ───────────────────────────────┐
│  ⧖ Video from your image     running in background · 12%   [View in tray] │
│     ▓▓▓░░░░░░░░░░░░░░░░░  rendering 12%                                     │
└──────────────────────────────────────────────────────────────────────────┘
```

- **Motion:** the card does NOT disappear. It stays in the conversation as the
  canonical home of the result. A small "ghost" chip animates from the card to
  the tray launcher (≈600–800ms, `prefers-reduced-motion` → no fly, just badge
  increment + a one-line state change). This visually *teaches* the spawn.
- **Copy:** state line transitions `running` → `running in background`. A subtle
  `[View in tray]` affordance appears.
- **Composer:** never blocked. The "thinking" indicator clears as soon as the
  job is spawned (the turn is logically complete; the render continues detached).

### 3.2 While it runs

- The in-conversation card shows a live **progress bar** (reuses the existing
  `TaskCard` `progressPercent`/`showProgress`) + provider-coarse status
  ("queued", "rendering", "finalizing") + elapsed time.
- The **tray launcher** in the shell shows a small animated badge with the count
  of active jobs (e.g. a pulsing dot + "2").
- The user can open another chat, start a new render, or do anything — multiple
  jobs track independently and concurrently.

### 3.3 Completion → pulled back

On terminal success:

- The in-conversation card flips `running → done`, the skeleton/bar is replaced
  by the actual media (image thumb / `<video controls>` / audio player), exactly
  as media renders today.
- An **actionable toaster** appears (bottom, via the existing
  `NotificationContainer`): a thumbnail + "Your video is ready" + **View**.
  - Clicking **View** (or the toast body) → routes to the owning chat if not
    already there, scrolls the card into view, and plays a brief **highlight
    pulse** on the card.
- The tray launcher badge decrements; the job moves to the tray's "Recent"
  section (kept for the session / 24h).

On failure / timeout:

- Card flips to `failed` with the **localized, non-leaky** message from
  `MediaErrorMessageBuilder`, plus the existing **Retry with <model>** affordance.
- A toaster: "Couldn't create your video" + **Details** (jumps to the card).

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

### New (frontend)

| Component / module | Responsibility |
|---|---|
| `stores/mediaJobs.ts` (Pinia) | Source of truth for all jobs (active + recent). Subscribes to Centrifugo `media_job.update`, hydrates from `GET /media/jobs` on load, exposes `activeCount`, `jobsForMessage()`, `cancel()`. Patches the `history` store's matching `TaskCard` in place. |
| `components/jobs/JobsTrayLauncher.vue` | Shell button + animated active-count badge; opens the tray. Lives in `SidebarV2` (and mobile top bar). |
| `components/jobs/JobsTray.vue` | The slide-over panel (Active/Recent groups, rows, empty state). |
| `components/jobs/JobRow.vue` | One job row (icon, prompt, chat, progress, Open/Stop/Download). |
| `components/jobs/JobCompletionToast.vue` *(or extend toast)* | Rich, **actionable** toast (thumbnail + label + View). See §6. |
| `composables/useJobNavigation.ts` | `jumpToJob(job)`: route to chat → wait for render → scroll card into view → trigger highlight pulse. |

### Modified (frontend)

| File | Change |
|---|---|
| `components/multitask/TaskCard.vue` | Add `running in background` sub-state visuals, the fly-to-tray ghost animation hook, `[View in tray]` affordance, and a highlight-pulse class toggled by `useJobNavigation`. |
| `stores/history.ts` | `TaskCard` gains `jobKey?: string` + `backgrounded?: boolean`; reload hydration re-attaches live cards for messages with active jobs (evolves the flatten rule — see §7). |
| `composables/useNotification.ts` | Extend `Notification` with optional `action?: { label, onClick }` and optional `thumbnailUrl`/`icon` so a toast can be clickable. (Today toasts are message-only.) |
| `components/NotificationContainer.vue` | Render the optional action button / thumbnail; clickable body. |
| `components/SidebarV2.vue` | Mount `JobsTrayLauncher`. |
| `services/api/chatApi.ts` + `types/chatStream.ts` | Recognise the new `task_update` `state:'running'` carrying `job_id` / `backgrounded`. |

### Backend (contracts consumed here — defined in `01`)

- `GET /api/v1/media/jobs?message_ids=…` and `/jobs/{id}` → `toStatusArray()`.
- `POST /api/v1/media/jobs/{id}/cancel`.
- Realtime `media_job.update {job_id, message_id, node_id, chat_id, state,
  percent?, file?, error?, prompt?, kind?}` on the per-user channel.

---

## 5. State & data flow

```
spawn:   handler → MediaJobService.create → SSE task_update{state:running, job_id, backgrounded}
                                              │
 frontend: history.TaskCard{state:running, jobKey, backgrounded}  +  mediaJobs.add(job)
                                              │
 live:    Centrifugo media_job.update ──▶ mediaJobs.patch(job) ──▶ history card patched (percent)
                                              │
 done:    media_job.update{state:done,file} ─▶ card→done(url)  +  toast(actionable)  +  tray Recent
                                              │
 reload:  GET /media/jobs?message_ids ──▶ re-attach running cards  +  resubscribe
 fallback: Centrifugo down → poll /media/jobs/{id} (mediaJobs store)
```

The `mediaJobs` store is the single front-end owner; `TaskCard` rendering stays a
projection so the in-conversation card and the tray row never disagree.

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
- failure: error styling + **Details** → jump to the failed card.

## 7. Reload / navigation resilience (evolves the flatten rule)

The multitask spec's current rule is: *cards are a streaming-time affordance;
history is flattened on reload* (it explicitly lists "reconstructing live cards
from history" as a non-goal). Async jobs require a **scoped exception**:

- **Finished** turns still flatten exactly as today (text + media in the bubble).
- A message that has an **active** `MediaJob` re-attaches a **live card** on
  reload (hydrated from `GET /media/jobs?message_ids=…`), so a render in flight
  survives F5 / navigation and still resolves in place.
- Once terminal, it flattens on the next load like everything else.

This keeps history clean while honouring "come back effortlessly".

## 8. Motion & visual language

- **Spawn fly-to-tray:** 600–800ms, ease-out, a small translucent chip with the
  kind icon; tray launcher does a single pulse + badge tick. Respect
  `prefers-reduced-motion` (skip the fly; keep state-text + badge).
- **Progress bar:** reuse `task-card__progress` track/fill tokens.
- **Highlight pulse on jump:** 1.2s, two soft brand-tinted pulses on the card
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
- **Fast image** → resolves within `MEDIA_JOB_IMAGE_INLINE_FAST_MS`; no fly-off,
  no tray noise (feels like today).
- **Cancelled** → card + row show neutral "Stopped"; respect provider billing
  reality in copy.
- **Centrifugo down** → polling fallback keeps cards + tray live (slower cadence).
- **Result lost (Redis eviction before persist)** → degrade to a clear "couldn't
  confirm — please retry" card, never an infinite spinner.

## 12. Mapping to backend sprints (`01`)

| `01` sprint | UX delivered here |
|---|---|
| B (detach chat path) | Spawn-off card sub-state + `[View in tray]` (static), composer stays free. |
| C (realtime + persist) | `mediaJobs` store, actionable completion toaster, in-place resolve, fly-to-tray motion. |
| D (resilience + tray + cancel) | Global Jobs tray + launcher/badge, jump-to-card + highlight, reload hydration, Stop. |
| E (cross-channel) | (Web UX stable; WhatsApp/Email delivery is channel-side.) |
| F (rollout) | Polish pass, reduced-motion/a11y audit, E2E, i18n completeness. |

## 13. Open design questions

1. Tray launcher placement: sidebar footer vs. top-bar icon (or both responsive)?
2. Should Recent persist across reloads (needs the 24h Redis record) or be
   session-only? (Leaning: show terminal jobs from the 24h window.)
3. Desktop notification opt-in in 4.0, or strictly in-app toaster? (Backlog.)
4. Per-card "Stop" vs tray "Stop" — keep both (consistent) — confirm.
