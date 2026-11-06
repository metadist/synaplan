# Assistant onboarding UX — set up a working assistant without guesswork

Row 44. Neighbours, not duplicates: row 25 (assistants that *run* on their
own), row 26 (visual polish and naming), row 42 (template *gallery* —
this track supplies one gallery-ready template and fixes the setup path
every template lands in).

## 0. Decisions for the owner

- [ ] D1 — A scheduled run of an internet-enabled assistant searches
  always (opt-out in the instructions), instead of asking the classifier
  per run. Recommended: yes; the run exists because a person ordered it.
- [ ] D2 — A personal assistant is switched on with **Activate**; the
  publish/version flow (changelog, router pick) appears only when the
  assistant is shared. Recommended: yes.
- [ ] D3 — Sprint scope below, in this order. C3 (form restructure) may
  slip to the next sprint without breaking the sprint goal.

## 1. Findings (walked 2026-11-06 on web.synaplan.com, admin account)

Journey J-AX1: *"Send me the current open-source news every morning."*
One assistant (instructions, internet on, schedule 07:00 Europe/Berlin).
It took ~10 iterations and a code reading to reach a working run. Every
failure below was silent or misleading; a person without code access
would have stopped at F2 — or published it.

| # | Prio | Finding | Evidence |
| - | ---- | ------- | -------- |
| F1 | P1 | The assistant builder is one endless form (Basics, Instructions, Models, Knowledge, 12 skill chips, Triggers, Publish). The test panel is the second grid column without `sticky`, so it scrolls away exactly when the form gets long | `frontend/src/components/assistants/AssistantBuilder.vue:3`, `AssistantTestPanel.vue` |
| F2 | P1 | **First test answer was fully invented and looked perfect**: Linux Kernel 6.12 and Rust 1.83 (both Nov 2024) presented as "last 24 hours" on 2026-11-06. Only a small model-written disclaimer at the end admitted no live search ran. Nothing in the system marks an answer as "no live search performed" | test run 2026-11-06 09:41; disclosure building blocks exist (`Service/Disclosure/AiDisclosure.php`) but do not fire here |
| F3 | P1 | Whether a search runs is decided by three invisible mechanisms: a fixed phrase list, a sorter vote, the instructions. The natural German request "Nutze jetzt deine Websuche: …" matches **no** pattern — the German list knows "Suche im Internet nach …", "Mach eine Websuche", "Websuche:" (`WebSearchTopicPolicy::EXPLICIT_SEARCH_PATTERNS`, DE lines 95–101) — so the run fell to the classifier vote and silently lost | `Service/Message/WebSearchTopicPolicy.php:88-110`, `Service/Message/Classification/AgentPinRoute.php:160-186`, `Service/Message/Processing/TurnWebSearch.php:63-69` |
| F4 | P1 | The same intent produced three different outcomes in a row (invented news → "Websuche heute nicht verfügbar" → unusable generic results). The deciding reason is logged (`trigger_reason`, `TurnWebSearch.php:84-100`) but never shown | test runs 2026-11-06 09:41–09:57 |
| F5 | P1 | Web search is an instance setting (provider + key). The assistant form offers "Look things up on the internet" with no indication whether search works on this instance. On the walked instance Brave was selected with "Key stored — not verified" until an admin ran the hidden test query on **Operate → System configuration → Web search**. Users cannot override (`WEB_SEARCH_USER_OVERRIDE_ALLOWED` seeded `0`) | admin screenshots 2026-11-06 09:51; `Seed/PlugsConfigSeeder.php:60-65`; the per-user truth already exists in `Service/SelfAware/PlatformCapabilityInventory.php:211-218` (incl. the admin path) — it is just not surfaced in the builder |
| F6 | P2 | The draft test panel renders plain text lines only — no steps, no sources — while the real chat shows both. During the walk it was impossible to see whether a search had run | `AssistantTestPanel.vue` (`lines` as text) vs chat timeline/sources |
| F7 | P2 | A schedule can be switched **On** while the assistant is still an unpublished draft ("Saved under Automations → Saved tasks"), and the publish block (version note, router pick) is required ceremony for a purely personal assistant | screenshots 2026-11-06 09:46–10:01 |
| F8 | P2 | "Ask it to" is a bare textarea. That its wording decides over search (F3) is nowhere said; there is no "run this schedule now" test — the first real run is tomorrow 07:00 | builder Triggers section; Saved Tasks can run on demand (`docs/FEATURES.md:50`) |
| F9 | P3 | Result delivery for a schedule hides behind the "Email me" skill chip among 12 equal-looking chips | builder "What it may do" |
| F10 | — | What works and stays: once forced, Brave + page reading produced a correct digest (8 items, all sourced, dates checked). Cost of one good run: 11,392 tokens ≈ $0.02 (Usage block). The engine is fine; the path to it is not | test run 2026-11-06 10:01 |

### 1b. Second review of the same page (assistant builder, since 5.6.4)

An earlier review round of this page (before this walk) found the page
barely clearer than in its first version. Items re-checked against the
2026-11-06 screenshots where possible; the rest is taken over as
reported. Three things did land since: the test panel has an input with
Send, "No published versions yet" explains that versions exist, and the
Usage block exists (valuable for B2B). The rest is still open:

| # | Prio | Finding | Evidence |
| - | ---- | ------- | -------- |
| F11 | P1 | **Instructions starts empty** (one boilerplate sentence). Laypeople do not know what to write; there is no guidance, no template, no help | builder Instructions (walk 2026-11-06 confirms: the working text had to be dictated from outside) |
| F12 | P1 | **Duplicate switches for the same thing**: the checkbox "Look things up on the internet" *and* the chip "Search the web"; "Use files" *and* "Search knowledge". Two controls, one flag — nobody knows which wins | screenshots 2026-11-06 (both pairs visible) |
| F13 | P1 | **Chips do not show their state.** On and off chips read alike at a glance (colour only, no mark) | screenshots 2026-11-06 |
| F14 | P2 | **Jargon on the surface**: "How many passages to use: 8", "Minimum match 0.6", "Vision", "router" — while genuinely advanced things share the same level | builder Knowledge/Models/Publish |
| F15 | P2 | **Knowledge can be added four ways**, plus a search field *and* a dropdown for the same library ("Add a file", library search, library dropdown, shared-folder dropdown) | screenshots 2026-11-06 |
| F16 | P2 | **Delete sits red next to Publish**; no confirmation step is visible | screenshots 2026-11-06 |
| F17 | P2 | **Save exists only at the top** of a very long page (F1's other half) | builder header |
| F18 | P3 | **The test panel is a large empty area**: input pinned to the bottom, far from the title; the assistant's greeting and starter prompts — which exist as fields — are not shown as clickable chips; no hint that unsaved changes can be tested | `AssistantTestPanel.vue`, builder Basics (Greeting, "Add starter") |
| F19 | P3 | **Empty states say nothing**: "Usage: No usage yet" without what will appear; "Connected apps" is a bare empty field with no call to action; the trigger heading "When should New assistant act?" reads broken before the assistant has a name | screenshots 2026-11-06 |

## 2. What is fine — do not "fix" it

- The search engine and its providers (Brave/Tavily/SearXNG …) and the
  admin page's test query. F5 is a *surfacing* problem, not a config one.
- The fail-loud sentence pattern ("write only X if you cannot search")
  as a prompt technique — AX3 makes it a system property instead.
- Bundle v1 as the template format and the row-42 gallery mechanics.
  AX11 ships one template *for* that gallery; it builds no second format.
- Assistant versioning for shared/published assistants (row 41 GV5
  review). D2 only removes the ceremony for personal ones.

## 3. Target model

Three rules, applied everywhere the setup path touches:

1. **Nothing fails silently.** If a run could not search, the answer
   says so as a system notice — not as model prose, and never as
   invented content.
2. **Ordered beats guessed.** What a person explicitly configured (a
   schedule, an internet switch) is executed deterministically; a
   classifier vote is a fallback for free chat, not a gate for orders.
3. **The form shows what matters first.** Name, instructions, internet,
   schedule, activation — everything else is "Advanced".

Acceptance journey (the sprint is done when this holds): a person who
did not take part in this walk sets up J-AX1 in **under 5 minutes**,
with zero prompt tricks, zero unexplained admin detours, and receives a
sourced digest the next morning. Cost per run stays visible (≈ $0.02).

## 4. Steps

Release classes per `.github/mobile-impact-policy.json`. Every
user-visible step names journey J-AX1 and records the five exit bullets
of `docs/UX_CONTRACT.md` §6 in its step file before implementation.

| Step | Change | Anchors | Class | Size |
| ---- | ------ | ------- | ----- | ---- |
| AX1 | **Scheduled runs search.** A trigger run of an assistant whose internet is on executes with search ordered (same force as `/search`), unless the instructions opt out. The "Ask it to" wording no longer decides | `TurnWebSearch.php:66-69`, trigger/Saved-Task run creation | backend-only | M |
| AX2 | **Everyday phrasing counts.** Extend the explicit-search patterns in all five locales ("Nutze/verwend/benutz die Websuche …", "use your web search …") with negative tests ("how does web search work?" must not fire). The internet switch gains three honest states: Off / Automatic / Always | `WebSearchTopicPolicy.php:88-110`, `AgentPinRoute.php:160-186` | backend-only | M |
| AX3 | **No invented news.** When a search was ordered or needed but the provider is off or returned nothing usable, the answer carries a system notice ("Created without live web search" / "No current sources found") instead of model-written content in digest form | `Service/Disclosure/`, `TurnWebSearch.php:101-107` | backend-only | M |
| AX4 | **Search status in the builder.** Under "Look things up on the internet": "Web search active (Brave)" or "Not set up on this installation — an admin enables it under Operate → System configuration → Web search" (button for admins). Data source: the existing capability inventory entry | `PlatformCapabilityInventory.php:211-218`, assistants API + builder | ota-candidate | S |
| AX5 | **Reasons, not "not available".** Test panel and chat distinguish: searched (n results) · not set up by admin · searched, nothing current. Surface the already-logged `trigger_reason` categories | `TurnWebSearch.php:84-100`, chat/test-panel status | ota-candidate | M |
| AX6 | **Sticky test panel.** The aside in the builder grid becomes `lg:sticky` with viewport height and its own scroll area | `AssistantBuilder.vue:3`, `AssistantTestPanel.vue` | ota-candidate | S |
| AX7 | **Test panel tells the truth.** Steps and source cards like the real chat (processing timeline, sources), incl. "search ran / did not run (+ reason)". Empty state (F18): the assistant's greeting and starter prompts as clickable chips, input under them, and the hint "Test your changes here — unsaved ones too" | chat timeline components, `AssistantTestPanel.vue` | ota-candidate | M |
| AX8 | **"Ask it to" explains itself + "Test this schedule now"** (runs the Saved Task once, result under Tasks) | builder Triggers, Saved Task run-once | ota-candidate | S–M |
| AX9 | **Activate for personal assistants** (D2). Publish/version/router UI only when sharing. A schedule cannot be On while the assistant is inactive — blocked with a reason, or activation happens with it | builder Publish block, trigger save | ota-candidate | M |
| AX10 | **Form in two tiers + sticky action bar** (D3, may slip; covers F14–F17). "What matters" first; Models/Knowledge/Skills under "Advanced" — including the jargon fields (passages, minimum match, Vision) and the renamed router option ("Synaplan may use this assistant automatically when a question fits"). Knowledge gets **one** "+ Add source" entry (upload / library / shared folder) with a status list instead of four parallel paths (F15). Delete moves to a danger zone at the very bottom, behind a confirmation (F16). The sticky bar carries Save/Activate, so saving exists at every scroll depth (F17). Builder component split per the 300-line rule | `AssistantBuilder.vue` and children | ota-candidate | L |
| AX11 | **One seed template: "Daily news briefing"** for the row-42 gallery — the walked instructions (date check, no-invention rule now backed by AX3), internet + page reading on, 07:00 schedule, delivery choice. Topic + time are the only required inputs | row 42 TG mechanics, bundle v1 | ota-candidate | M |
| AX12 | **Delivery as a visible choice** on schedules: "Result: in chat / by email to me" instead of the hidden "Email me" chip dependency | builder Triggers, skill mapping | ota-candidate | S |
| AX13 | **Instructions with a handrail** (F11). Guided fields — role, audience, tone, "must never …" — that compose the instruction text (still editable as free text), starter templates per use case, and an "✨ Improve with AI" button that proposes a rewrite as a diff the person accepts or rejects. Uses the assistant's own chat model; nothing is applied silently | builder Instructions, agents API | ota-candidate | M–L |
| AX14 | **One switch list, states you can see** (F12, F13). The checkbox/chip duplicates merge into a single grouped list of real switches — "Find information" (web search, read a page, knowledge), "Create content", "Take actions" — on = filled with ✓, off = grey outline. UI consolidation only; the backend flags (`tool_internet`, `tool_files`, skills) keep their meaning, so no run changes behaviour by accident | builder Tools section, agent profile mapping | ota-candidate | M |
| AX15 | **Empty states and names that speak** (F19, rest of F14). Usage: "As soon as someone chats with this assistant, you will see conversations, answered questions and cost here." Connected apps: "No apps connected yet → Connect apps". Trigger heading: "When should this assistant become active?" All five locales | builder copy, `frontend/src/i18n/locales/*` | ota-candidate | S |

Order: week 1 AX1, AX2, AX4, AX6 (independent, immediate relief);
week 2 AX3, AX5, AX7, AX8, AX9, AX14, AX15 (any time — small), then
AX11/AX12/AX13, AX10 last.

## 5. Gates

- `make ci-local` unfiltered per landing step; AX1/AX2 re-record the
  routing characterization snapshots only with a line-by-line review.
- E2E: the J-AX1 journey as a spec (template/assistant → forced search
  with a fake provider → sourced digest; provider off → AX3 notice, no
  digest). Layout changes (AX6, AX10) run `make -C frontend
  test-e2e-layout`. UI strings land in all five locales.
- Live walk of J-AX1 on the running stack with a seeded account before
  the sprint closes; the walker is not the person who built it.
- Forced search costs money per run (≈ $0.02 for J-AX1): the Always
  state and the schedule form show the expected cost note; AX1 keeps
  the per-run cost on the answer as today.

## 6. Out of scope

New search providers; the research engine; the Runs page and assistant
run kernel (row 25); the gallery itself (row 42); visual design tokens
(row 26). Pattern locales beyond the existing five.
