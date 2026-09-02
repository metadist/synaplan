# Feature 10 — Landing streamline & lean composer (guest-first UX)

**Release:** 4.0 · **Priority:** P1 · **Status:** Planned (2026-07-05)
**Target:** `web.synaplan.com` (SaaS) + every self-hosted / OSS instance
**Surface:** Anonymous chat landing + the chat composer (`ChatInput.vue`) for all users
**Locales:** `en`, `de`, `es`, `tr` — all four, every new string (no English fallback)

> **Goal:** Make the empty chat screen do the selling. A first-time visitor should
> immediately (a) understand what Synaplan can do, (b) be able to *try it in one
> click*, and (c) meet gentle, informative nudges — not dead padlocks — when they
> reach for a signed-in feature. In parallel, strip the composer down to a single
> lean row: kill "Easy Mode", fold Model / Tools / Knowledge / Attach into the
> `+` menu, and surface the active model as a tiny caption under the input.

This is a **frontend-only** feature (no schema, no new API). It reuses the
already-shipped guest-session + marketing-news infrastructure
([`../20260624-anonymous-landing-marketing-news.md`](../20260624-anonymous-landing-marketing-news.md)).

---

## 0. TL;DR — what changes vs. today

| Area | Today | After Feature 10 |
|------|-------|------------------|
| Guest padlocks (sidebar + composer) | Amber `mdi:lock-outline` badge on every gated control; click opens the full-screen `GuestFeatureGateModal` | **No lock badges.** Click opens a small anchored **hint popover** — "what this does" in 1–2 sentences + "Sign up free" CTA |
| Empty landing (blog OFF) | Just welcome text | Welcome text **+ 3 one-click example-prompt buttons** (ChatGPT-style, our icons) that *execute* on click |
| Empty landing (blog ON) | Welcome text + marketing-news cards | Unchanged — cards show, example buttons hidden (mutually exclusive) |
| Blog / marketing news default | Already OFF (`MARKETING_NEWS.ENABLED='0'`) | Stays OFF; the example buttons are the default empty state |
| Composer secondary row | `Model · Tools · Knowledge · [Easy Mode]` pills below input | **Removed.** Everything folds into the `+` menu |
| "Easy Mode" | Toggle in composer + Settings → App Mode + nav filtering | **Removed completely** (store, toggle, settings section, nav gating) |
| `+` button | Opens file-selection modal only | Opens a **context menu**: Attach files · Tools · Knowledge folder · Model. Exempt from the lock rule — the menu always opens |
| Model indicator | `Model` pill showing the selected name | Shown as **"Default"** inside the `+` menu; when changed, the chosen model name renders **under the input in ~8px caption** |
| Composer position (empty chat) | Sticky at the **bottom** even on the blank landing | **Centered hero** on the empty screen (welcome → input → examples); **docks to sticky-bottom on first send** / when a chat has history |

Everything is additive/behavioural on the frontend. No `VITE_*` runtime flags
(per `AGENTS.md`). The one server-controlled input — `marketingNews.enabled` —
already exists in runtime config and stays admin-controlled, OFF by default.

---

## 1. Why (the problem)

The current guest experience shows the user a wall of **amber padlocks** (sidebar
Files/Channels/AI-Setup, composer Attach/Enhance/Model/Tools). Padlocks say
"you can't" before the user knows "what for". They read as friction, not
invitation, and the full-screen gate modal is a heavy interruption for a curious
first click.

Meanwhile the empty landing is a bare "Welcome / Type your message…" — a blank
box is the worst first-run prompt. New users don't know what to type, so they
bounce. ChatGPT/Claude solve this with tappable example prompts; we have none
(unless a self-hoster turns the optional blog feed on).

Finally the composer carries a **four-pill secondary row plus an Easy-Mode
toggle** — a mode most users never need, that fragments the UI (two layouts to
maintain and test) and pushes the real actions (Model/Tools/Knowledge) into a
crowded row. Consolidating them under `+` is leaner and matches the mental model
of "attach / configure this message".

---

## 2. Current state (what exists — do NOT rebuild)

| Piece | File | Notes |
|------|------|------|
| Composer | `frontend/src/components/ChatInput.vue` | `+` = `triggerFileUpload`; amber locks via `isGuestMode`; secondary row gated on `!appModeStore.isEasyMode`; Model/Tools/Knowledge pills + Easy-Mode button |
| Guest gate modal | `frontend/src/components/guest/GuestFeatureGateModal.vue` | Full-screen; already has per-feature copy (`guest.featureGate.features.*`) and register/login CTAs |
| Sidebar locks | `frontend/src/components/SidebarV2.vue` | `mdi:lock-outline` badge + `handleNavClick` → opens `GuestFeatureGateModal` for `requiresAuth && isGuestMode` |
| Nav model | `frontend/src/composables/useNavItems.ts` | `requiresAuth`, `gateFeature`, `lockedInEasyMode` per item |
| App mode | `frontend/src/stores/appMode.ts` | `easy`/`advanced` in localStorage; consumed by ChatInput, useNavItems, SettingsView |
| Model dropdown | `frontend/src/components/ModelDropdown.vue` | "Default" option + per-model list; emits `update:modelValue` |
| Tools dropdown | `frontend/src/components/ToolsDropdown.vue` | Commands (`/pic`, `/vid`, …) + Thinking/Voice toggles |
| Knowledge picker | `frontend/src/components/KnowledgeFolderPicker.vue` | RAG folder scope (auth only) |
| Marketing news | `frontend/src/components/MarketingNews.vue` + `configStore.marketingNews.enabled` | Admin master switch, OFF by default; renders in `state-empty` |
| Empty state | `frontend/src/views/ChatView.vue` `data-testid="state-empty"` | Welcome text + `MarketingNews` when `!auth && enabled` |
| Guest send path | `ChatView.vue` `handleSendMessage` | Already consumes a guest message + shows limit modal at 0 |

---

## 3. Change (1) — locks become informative hint popovers

### 3.1 Behaviour

- **Remove every amber lock badge** (`mdi:lock-outline`) from the sidebar rail and
  the composer. The controls stay visible (slightly de-emphasised is fine, e.g.
  the existing `opacity-50`), but no padlock iconography.
- Clicking a gated control **as a guest** opens a **small anchored popover** (not
  a full-screen modal) positioned next to the clicked element, containing:
  1. A short **"what this does"** line (1–2 sentences), already localized.
  2. A one-line **motivator** + a primary **"Sign up free"** button and a subtle
     "Log in" link.
- The popover is dismissable (click-outside, `Esc`, close button), non-blocking,
  and never covers the control the user was exploring.
- Signed-in users are unaffected (controls work normally).

### 3.2 New component — `GuestHintPopover.vue`

A lightweight, teleported, anchored popover (reuses the `--brand`/token styling of
the current gate). Props:

```ts
defineProps<{
  isOpen: boolean
  featureKey: string          // 'files' | 'channels' | 'aiSetup' | 'models' | 'tools' | 'attach' | 'enhance' | 'knowledge'
  anchor: HTMLElement | null   // element to position against
}>()
defineEmits<{ close: [] }>()
```

- Copy is read from `guest.featureGate.features.<featureKey>` (extend the map — see
  §7). Title/CTA reuse `guest.featureGate.subtitle/registerButton/loginButton`.
- Positioning: compute from `anchor.getBoundingClientRect()` with viewport
  clamping (same math already used for the SidebarV2 nav flyout); flip
  above/below and left/right as space allows; recompute on resize/scroll; clean up
  listeners in `onUnmounted`.
- Mobile: if there's no room to anchor, fall back to a bottom sheet (centered
  card) — still small, still non-blocking.

> **Decision:** keep `GuestFeatureGateModal.vue` **only** for the *message-limit*
> reached case if still wired there; the per-feature curiosity click now uses the
> popover. (Audit: today the limit path uses `GuestSignupModal`, so the gate modal
> can likely be deleted once all callers move to the popover — confirm in impl.)

### 3.3 Wiring

- **SidebarV2**: drop the lock `<Icon>`; in `handleNavClick`, for
  `requiresAuth && isGuestMode` open `GuestHintPopover` anchored to the nav button
  (via `navBtnRefs`) with `featureKey = item.gateFeature` (add `channels` /
  `aiSetup` keys to the nav items so copy is specific, not generic `settings`).
- **ChatInput**: drop the `mdi:lock-outline` overlays on Attach/Enhance/Model/
  Tools; the `emit('guestFeatureGate', key)` calls now drive the popover anchored
  to the pressed button. (Model/Tools now live in the `+` menu — see §5.)
- **ChatView**: replace the single `featureGateOpen/featureGateKey` +
  `GuestFeatureGateModal` with the popover state (`isOpen`, `featureKey`,
  `anchorEl`), fed by `@guest-feature-gate` from both ChatInput and SidebarV2.

---

## 4. Change (2) — one-click example prompts on the empty landing

### 4.1 Behaviour

- On the empty chat screen, when **not authenticated** *(see §4.4 for the
  authenticated-new-chat decision)* **and** `marketingNews.enabled === false`
  (the default), show **three example-prompt buttons** under the welcome text,
  each with a small leading icon.
- Clicking a button **executes** the prompt immediately (ChatGPT behaviour) —
  prefill the composer and fire the existing send path.
- When `marketingNews.enabled === true`, the example buttons are **hidden** and the
  marketing-news cards render instead — the two are mutually exclusive (blog is the
  self-hoster opt-in; examples are the default).

### 4.2 The three prompts (canonical EN; localized ×4)

| # | Icon (mdi) | Prompt (EN) |
|---|-----------|-------------|
| 1 | `mdi:music-note` (or `mdi:volume-high`) | *"Create a poem about a cat and convert it into an MP3."* |
| 2 | `mdi:file-word-box` | *"Write an article about the Florida Everglades and put it into a Word document for download."* |
| 3 | `mdi:movie-open` | *"Make a video invitation for a one-day company retreat in London, and write the schedule below it."* |

These deliberately showcase the three "wow" output types (audio, document,
video) that map to Synaplan's media/routing strengths — reinforcing the value
prop on the very first screen.

### 4.3 New component — `ExamplePrompts.vue`

```ts
const emit = defineEmits<{ pick: [prompt: string] }>()
// Renders 3 token-styled cards/buttons (surface-card + icon + one-line prompt),
// responsive: stacked on mobile, 3-up (or 3-row) on desktop; unique :key per item;
// prompt strings from i18n examplePrompts.items[].
```

- Style with existing tokens/utilities (`surface-card`, `pill`, `txt-*`, `var(--brand)`) —
  no Tailwind colors, no one-off CSS. Icons via `@iconify/vue` (`Icon`).
- Emits `pick` with the resolved prompt string.

### 4.4 Execution wiring (ChatView)

- Render in `state-empty`:
  - `<ExamplePrompts v-if="showExamplePrompts" @pick="handleExamplePick" />`
  - keep `<MarketingNews v-if="!auth && marketingNews.enabled" />`
  - `showExamplePrompts = !authStore.isAuthenticated && !configStore.marketingNews.enabled`
- `handleExamplePick(prompt)`: set the composer text and send. Simplest reuse:
  `chatInputRef.value?.setInputText(prompt)` then call the same `handleSendMessage`
  the composer uses — OR add a tiny `submitText(text)` expose on ChatInput that
  fills + `sendMessage()`. Prefer the latter so file/command/enhance reset logic
  stays inside the composer.
- **Guest cost note:** executing a prompt consumes one guest message (intended —
  it *is* the trial). The existing limit modal already handles the 0-remaining
  case, so no extra guard needed.

> **Open decision (default: guests only):** should signed-in users starting a
> brand-new chat also see example prompts? Recommended **yes, but as a lighter
> variant** (examples relevant to their own capabilities), gated behind the same
> `marketingNews.enabled === false`. If we keep it guests-only for v1, the code is
> a one-line condition change later.

---

## 5. Change (3) — remove Easy Mode, fold the row into the `+` menu

### 5.1 Remove "Easy Mode" completely

- Delete the Easy-Mode toggle button from `ChatInput.vue`.
- Delete `frontend/src/stores/appMode.ts` and every import/usage:
  - `ChatInput.vue` (`appModeStore`, the `v-if="!isEasyMode"` row guard).
  - `useNavItems.ts` — drop `lockedInEasyMode`, `isItemLocked`, and the easy-mode
    filter; advanced (full) nav becomes the only behaviour. Guest gating stays.
  - `SidebarV2.vue` — drop `isItemLocked` usage + the "switch to Advanced"
    confirm dialog branch in `handleNavClick`.
  - `SettingsView.vue` — remove the App-Mode section.
  - `frontend/tests/e2e/tests/navigation.spec.ts` — drop/adjust easy-mode cases.
  - i18n — remove `settings.appMode.*` keys (all four locales).
- **Decision to confirm:** "remove completely" is read as *delete the concept*
  (store + settings + nav filtering), not just hide the toggle. This is the leaner
  outcome and removes a whole second layout from test/maintenance. Flagging
  explicitly because it touches Settings + navigation E2E.

### 5.2 The `+` context menu

The `+` button (bottom-left of the input shell) becomes a **menu trigger**
(teleported popover, same anchoring approach as the dropdowns). It contains, in
order:

1. **Attach files** — opens the existing `FileSelectionModal` (auth) / hint
   popover (guest).
2. **Tools** — the current `ToolsDropdown` content (commands + Thinking/Voice
   toggles). Render inline in the menu, or open the existing dropdown from the
   menu item; prefer inline to avoid nested poppers.
3. **Knowledge folder** — the current `KnowledgeFolderPicker` (auth only; guest →
   hint popover).
4. **Model** — model selection. Header row shows **"Default"** until the user
   picks a specific model.

**Lock-rule exception:** the `+` menu **always opens** for guests (it is *not*
gated). Individual items inside that require auth (Attach, Tools, Knowledge, and
Model for guests) show the §3 hint popover when tapped. This gives guests a way to
*see* what's available without a wall.

- Remove the entire secondary actions row (`section-chat-secondary-actions`).
- The `+` menu opens **upward** (input is at the bottom); reuse `.dropdown-up`
  styling; click-outside + `Esc` close; unique keys; cleanup on unmount.
- Mobile: the menu is a compact upward sheet; labels stay visible (no `sm:inline`
  hiding), consistent with the current mobile-labels rule.

### 5.3 Model shown as "Default" + tiny caption

- Inside the `+` menu, the Model entry is labelled **"Default"** when
  `selectedModelId === null`, else the chosen model's name.
- When the user selects a **specific** model, render a **tiny caption under the
  input field** (≈8px / `text-[8px]`, `txt-muted`) e.g. `Model: <name>` so the
  choice is discoverable without opening the menu. Hidden when on Default (keeps
  the default screen clean). Localize the "Model:" label.
- Keep `selectedModelId` state + reset-on-chat-switch logic exactly as today; only
  the *presentation* moves.

### 5.4 Centered empty-state composer (input starts high, docks on first use)

**Behaviour:** on the empty chat screen the composer is **vertically centered** as
part of the landing hero (welcome line → input → example prompts, as one block).
The **moment the first message is sent** (or any chat with history is opened), the
composer reverts to its normal **sticky-bottom** position and the conversation
scroll takes over. First-time users get a focal, inviting "hero" input instead of
a blank box stuck to the bottom edge.

**Why this specific mechanism (a state swap, NOT an animated fly-down):**
`ChatInput.vue` is today `position: sticky; bottom: 0` and, on iOS, tracks the
soft keyboard via the visual viewport (see the top-of-file comment about
`env(safe-area-inset-bottom)` and the mobile tab bar). All of that battle-tested
positioning logic must keep running **unchanged in the state it already runs in
today** (a chat with messages). So we do not animate the live input flying down —
we swap which container owns it based on whether the chat is empty.

**Layout model:**

- **Empty + eligible landing** (`messages.length === 0 && !isLoadingMessages`, and
  for guests `!marketingNews.enabled`): the chat area renders a centered hero
  column — `welcome` heading, the composer, then `ExamplePrompts` — vertically
  centered (`flex flex-col items-center justify-center`, capped width e.g.
  `max-w-2xl`). The composer here is **not** sticky.
- **Any messages / history / streaming:** exactly today's layout — messages scroll
  region + sticky-bottom composer. Zero change, zero regression.

**Implementation approach (pick one, prefer A):**

- **A — single composer, conditional placement (recommended).** Keep one
  `<ChatInput>` instance and move it in the template between the centered hero
  wrapper and the normal bottom slot via `v-if`/`v-else` on the empty condition,
  OR keep it in one place and toggle a `variant`/`centered` prop that flips the
  wrapper classes (drop `sticky bottom-0`, apply the centered container). One
  instance = no duplicated state, no re-mount flicker of the textarea. Add a
  `centered?: boolean` prop to `ChatInput.vue` that only affects the **outer
  wrapper classes** (`sticky bottom-0 …` vs. centered), leaving every internal
  behaviour identical.
- **B — two mount points.** Simpler markup but risks losing draft text / focus on
  the swap and doubles the component in tests. **Avoid** unless A proves awkward.

**Transition:** a subtle fade/opacity + small translate on the *hero wrapper* is
fine (`Transition`), but **do not** FLIP-animate the live input element. The
switch fires once (first send), so it doesn't need to be reversible or smooth in
both directions.

**Mobile keyboard guardrail (the one real risk):** focusing a *centered* input
must not yank the layout when the soft keyboard opens. Since the centered variant
is **not** sticky and has no `env(safe-area-inset-bottom)` handling, the browser's
default scroll-into-view is acceptable there; the keyboard-tracking code only
applies in the sticky-bottom variant (its current, tested home). Verify on a real
iPhone: (1) focus centered input → type → keyboard doesn't push examples
off-screen awkwardly; (2) send → composer docks to bottom and the existing
keyboard/tab-bar logic resumes correctly.

**Accessibility:** the centered composer keeps the same `data-testid`, labels, and
autofocus expectations; example prompts remain reachable/tabbable below it.

**Scope note:** this is guest-first but the centered empty state is harmless for a
**signed-in user starting a brand-new chat** too (no messages yet) — recommend
enabling it for that case as well (it's the same `messages.length === 0`
condition), which also makes the new-chat feel consistent for everyone. Confirm in
§11.

---

## 6. Additional improvements (proposed, in scope of "make it leaner")

1. **Specific guest copy per surface.** Add `channels` and `aiSetup` feature keys
   (today both fall back to the generic `settings` string) so the popover says
   *"Connect WhatsApp, email and your website widget…"* / *"Configure AI models,
   prompts and routing…"* instead of one vague line.
2. **Conversion analytics (if an events util exists).** Fire lightweight events on
   `example_prompt_click` (with which prompt) and `guest_hint_open` (with
   featureKey) so we can measure the new funnel. No new deps — reuse whatever
   telemetry the app already has, or skip if none.
3. **First-message reveal.** Ensure the example buttons + welcome disappear the
   instant a message is sent (they already live under `messages.length === 0`, so
   free — just verify with the new component).
4. **Composer vertical rhythm.** With the secondary row gone, tighten the input's
   bottom padding so the single-row composer doesn't leave a dead gap (esp. mobile
   with the tab bar).
5. **Accessibility.** Popover + `+` menu get `role="dialog"`/`menu`, focus trap
   light-touch, `aria-expanded` on triggers, and `Esc`/click-outside — same bar as
   existing dropdowns.
6. **Right-to-none of the old modal.** Once callers migrate, delete
   `GuestFeatureGateModal.vue` and its now-dead i18n block (keep the `features.*`
   strings — they move to the popover).
7. **Prefill-vs-send toggle (future).** Keep `submitText()` and `setInputText()`
   both exposed so we can A/B "auto-send" vs "fill and let them press send"
   without a refactor.

---

## 7. i18n — all four locales (`en`, `de`, `es`, `tr`)

New / changed keys (add to `frontend/src/i18n/{en,de,es,tr}.json`):

```jsonc
{
  "examplePrompts": {
    "heading": "Try one of these",           // optional small heading
    "items": {
      "poemMp3":   "Create a poem about a cat and convert it into an MP3.",
      "everglades":"Write an article about the Florida Everglades and put it into a Word document for download.",
      "retreat":   "Make a video invitation for a one-day company retreat in London, and write the schedule below it."
    }
  },
  "guest": {
    "featureGate": {
      "features": {
        "channels":  "Connect WhatsApp, email and your website chat widget to Synaplan.",
        "aiSetup":   "Configure AI models, prompts and routing to fit your workflow.",
        "attach":    "Upload documents and images to use as a knowledge base for your AI.",
        "knowledge": "Scope a chat to one of your knowledge folders for focused answers."
        // (files/models/tools/enhance/memories/settings already exist — reuse)
      }
    }
  },
  "chatInput": {
    "plusMenu": { "label": "Add", "attach": "Attach files", "model": "Model", "tools": "Tools", "knowledge": "Knowledge folder" },
    "modelCaption": "Model: {name}"
  }
}
```

Remove (all four): `settings.appMode.*` and the composer `settings.appMode.easy`
usage. Use the canonical product terms (chat widget / AI assistant) per
`AGENTS.md`. Provide real translations (de/es/tr), not English copies — a missing
key silently falls back to English.

---

## 8. Files touched

| File | Change |
|------|--------|
| `components/ChatInput.vue` | Remove locks + secondary row + Easy-Mode; add `+` context menu (Attach/Tools/Knowledge/Model); model "Default" + 8px caption; anchor-emit for hint popover; expose `submitText()`; add `centered?` prop that flips only the wrapper classes (non-sticky centered vs. sticky-bottom) |
| `components/guest/GuestHintPopover.vue` | **New** — anchored, non-blocking guest hint + signup CTA |
| `components/guest/GuestFeatureGateModal.vue` | Delete once all callers migrate (or keep only if still needed for a non-feature path) |
| `components/ExamplePrompts.vue` | **New** — 3 one-click example prompt buttons |
| `views/ChatView.vue` | Render `ExamplePrompts` in `state-empty` (blog-off, guest); centered hero wrapper for the empty state that hosts the composer (`centered` variant) + welcome + examples, docking to sticky-bottom once `messages.length > 0`; `handleExamplePick`; swap gate modal → popover state fed by both ChatInput + SidebarV2 |
| `components/SidebarV2.vue` | Remove lock badges + easy-mode confirm; open hint popover anchored to nav button |
| `composables/useNavItems.ts` | Drop `lockedInEasyMode`/`isItemLocked`/easy filter; add `channels`/`aiSetup` gateFeature keys |
| `stores/appMode.ts` | **Delete** |
| `views/SettingsView.vue` | Remove App-Mode section |
| `components/ModelDropdown.vue` | Reused inside `+` menu (minimal/no change) |
| `components/ToolsDropdown.vue` / `KnowledgeFolderPicker.vue` | Reused inside `+` menu (minimal/no change) |
| `i18n/{en,de,es,tr}.json` | Add example prompts, plus-menu, model caption, channels/aiSetup/attach/knowledge gate copy; remove `settings.appMode.*` |
| `tests/e2e/tests/navigation.spec.ts` | Adjust for removed easy mode |

No backend, no migration, no OpenAPI change → **no `generate-schemas`** needed.

---

## 9. Testing & the gate (non-negotiable — `AGENTS.md`)

Frontend-only, so the required gate is:

```bash
make -C frontend lint && docker compose exec -T frontend npm run check:types && make -C frontend test
```

### Vitest (new/updated)
- `ExamplePrompts.spec.ts` — renders 3 buttons with localized text; `pick` emits
  the correct prompt string; unique keys.
- `GuestHintPopover.spec.ts` — opens with the right `featureKey` copy; close on
  `Esc`/click-outside; register/login links present.
- `ChatInput.spec.ts` (extend) — no lock icons; no secondary row; `+` opens the
  menu; menu contains Attach/Tools/Knowledge/Model; model caption appears only
  when a specific model is chosen; guest tap on gated item emits
  `guest-feature-gate`.
- `ChatView` (extend) — `state-empty` shows examples when guest+blog-off, shows
  MarketingNews when blog-on, shows neither when authenticated (per §4.4
  decision); `handleExamplePick` triggers a send.
- `ChatView` centered-composer (extend) — empty chat renders the composer in the
  **centered** variant (non-sticky) with welcome + examples; after a send /
  with existing messages it renders the **sticky-bottom** variant; the single
  `ChatInput` instance keeps its draft/state across the swap.
- Stub Pinia/i18n/`MessageText` per the `AGENTS.md` frontend-test note.

### E2E (Playwright)
- Guest lands → sees 3 example prompts → clicks one → message is sent + assistant
  responds → examples disappear.
- Guest clicks a sidebar/composer gated control → hint popover (not full modal) →
  "Sign up free" routes to `/register`.
- `+` menu opens for a guest; gated item inside shows the popover.
- Easy-mode E2E removed/updated; nav still reaches all items directly.

### Manual / visual
- Dark + light mode for popover, `+` menu, example cards, 8px caption.
- Mobile (≤375px) stacking + upward `+` menu + no dead gap below the composer.
- Blog ON path: cards show, examples hidden (admin toggles
  `MARKETING_NEWS.ENABLED`).
- **Centered composer (real iPhone):** focus the centered input → keyboard opens
  → examples don't jump off-screen awkwardly; send → composer docks to bottom and
  the existing keyboard/safe-area/tab-bar behaviour resumes correctly. Also verify
  the swap doesn't lose typed draft text or focus.

---

## 10. Definition of done

- Empty guest landing shows **welcome + 3 one-click example prompts** (blog OFF,
  the default); clicking one **executes** it; they vanish after the first message.
- With the blog master switch ON, the **news cards** show and the example buttons
  are hidden (mutually exclusive).
- **No amber padlocks** anywhere; every former-locked control opens a **small,
  informative, non-blocking hint popover** with a "what it does" line + "Sign up
  free" — in all four locales.
- The composer is a **single lean row**: no Easy-Mode, no secondary pill row. The
  `+` menu holds Attach / Tools / Knowledge / Model and **always opens** for
  guests; gated items inside show the hint popover.
- Model shows **"Default"** in the menu; a chosen model appears as an ~8px caption
  under the input.
- The empty chat presents a **centered hero composer** (welcome → input →
  examples) that **docks to sticky-bottom on the first send** / when history
  exists; the sticky-bottom variant keeps today's iOS keyboard + safe-area
  behaviour byte-for-byte, and the swap preserves draft text/focus.
- "Easy Mode" is **gone** — store, toggle, Settings section, and nav gating —
  advanced (full) UI is the only mode; signed-in behaviour otherwise unchanged.
- i18n complete in `en`/`de`/`es`/`tr`; tokens/utilities only (no Tailwind colors);
  full frontend gate green; Vitest + E2E cover the new flows.

---

## 11. Open decisions (confirm before build)

| # | Decision | Recommended default |
|---|----------|---------------------|
| 1 | Remove Easy Mode *concept* entirely (store+settings+nav) vs. just hide the toggle | **Remove entirely** (leaner; one layout to test) |
| 2 | Example prompts for signed-in new chats too | **Guests only in v1**, gated on blog-off; easy to extend |
| 3 | Example click = auto-send vs. fill-and-focus | **Auto-send** (user said "executed"); keep `setInputText` as fallback |
| 4 | Hint popover replaces `GuestFeatureGateModal` everywhere | **Yes** — delete the modal once callers migrate |
| 5 | Model caption font | `text-[8px]` `txt-muted`, hidden on Default |
| 6 | Centered empty-state composer for **signed-in** new chats too (not just guests) | **Yes** — same `messages.length === 0` condition; consistent new-chat feel for everyone |
| 7 | Empty-state transition | **Simple state swap** (subtle fade on the hero wrapper); explicitly **not** a FLIP fly-down of the live input |
