# UX polish and naming

**Status:** Plan, 2026-10-23. Not started; §0 ticked by the owner on 2026-10-23 (all fifteen rows as proposed, option A for D1).
**Owner:** product owner.
**Binding:** [`docs/UX_CONTRACT.md`](../../../docs/UX_CONTRACT.md) U1–U12
and §6, the AGENTS.md "Perfect UX & Stability" bar, the house rules for
buttons, form fields, colours and copy in the root `AGENTS.md`.
**Walk:** [`01_visual_check.md`](./01_visual_check.md) (13 pages, three
themes, 1440 and 320 px, measured with
[`walk/contrast-audit.js`](./walk/contrast-audit.js)).
**Naming map:** [`02_naming.md`](./02_naming.md).

The backend has moved fast: research quality (row 21) and the
architecture update (row 22) are rebuilding how answers are made. The
screens have not kept pace. The UX overhaul
([#2431](https://github.com/metadist/synaplan/pull/2431)) gave the app its
shell; this track does the small, cheap things that make the screens read
as one product: headings that are bigger than the text they head, chips
that can be read in every theme, no internal keys on screen, one casing,
and one word per thing. It also answers the owner's question whether we
call agents "assistants". We do, and in one list the two words are swapped
(`02` §2 d).

Most steps are small and independent. Two of them — the shared heading
scale (UP3) and the status chip (UP2) — should land **before** the next
new surfaces: the research cards (row 21, RD3 / RW1) and the capacity
page (row 22, AU7). Otherwise those surfaces add more one-off sizes and
raw colours of their own.

## What already exists

| Piece | Where | State today |
| ----- | ----- | ----------- |
| Page title | `frontend/src/components/PageHeader.vue` | One `h1`, `text-2xl font-semibold`, 14 px subtitle, optional icon chip and tour button; 34 views use it. The rule is written in its doc comment. |
| Section / card headings | — | No component and no utility. `h2` appears in 6 sizes, `h3` in 6 (`01` §4). |
| Chat answer typography | `frontend/src/assets/markdown.css` | `h1` 1.5 rem, `h2` 1.25 rem, `h3` 1.125 rem over 16 px body. Good; not touched. |
| Colour tokens | `frontend/src/style.css`, `style-v2.css` | `--txt-primary`, `--txt-secondary`, `--brand`; status pairs `--status-{success,warning,error,neutral}-{text,muted}` in both themes. No `info` pair. 65 uses of the status tokens against 740 raw Tailwind status colours. |
| Conventions doc | `docs/FRONTEND_CONVENTIONS.md` | Tokens, utilities, icon map. No typography section, no status colours. |
| Themes | `frontend/src/composables/useTheme.ts` | `light`, `dark`, `oled`, `system`; `oled` adds `theme-oled` to `<html>`. |
| Watermark | `frontend/src/components/AmbientBackground.vue` | Bird at `opacity-[0.035]` / `dark:opacity-[0.06]`, layered **above** content (`z-[1]`) on purpose because page roots paint opaque backgrounds. |
| Tours | `frontend/src/composables/useTour.ts` | driver.js; one active tour; closes on the close button, Escape, overlay and the last step. No route hook. |
| Locale gates | `frontend/tests/unit/i18n/localeParity.spec.ts` + `localeParityBaseline.json` | Key parity, placeholder names, banned jargon; shrink-only ledger. The pattern the new gates in this plan copy. |
| Visual snapshots | `frontend/tests/e2e/tests/visual.spec.ts` | Rail in light and dark, the composer `+` menu. |
| Naming decision of record | `2026-archive/20260903_agent_builder/00_master_plan.md` §0 row 1 | "User-facing word: Assistant. Code name `agent`." This plan proposes to reverse it (§0 D1). |
| File list API | `backend/src/Controller/FileController.php:1114` | Returns `group_key` (`TASKPROMPT:<topic>`, …) and no readable label. |

## 0. Decision checklist

Ticked by the owner before the step that depends on it starts. **All fifteen ticked on 2026-10-23 in an interview, each as proposed** (D1: option A; D3: keep "AI Setup Assistant"; D7: route moves to `/ai/agents`; D12: own script, no new dependency; order: UP1 and UP6 start now, beside the overhauls).

- [x] **D1 (UP7).** The object people build, publish and share (`BAGENTS`) is called **agent** in the web app, the docs and Desktop, as it already is in the code, the API, the flag, Desktop's rail and the website (`02` §5, option A). *Alternatives:* B — keep "Assistant" and change Desktop and the website; C — fix only the collisions. Rejected because B gives up the word the market and the website use and still leaves "assistant" meaning both the object and the AI in general; C leaves three names for one thing. **Conflict to settle once:** [agent handling](../20261023-agent-handling/00_master_plan.md) (row 25, same day) decides the opposite in its §0 #3 ("Assistant" stays) and adds Run, Helper and Notes on top. One owner decision covers both plans; the plan that loses changes its copy before its first UI pull request. Under D1 its words read "agent runs", "helper agent", "the agent's notes".
- [x] **D2 (UP7).** "Assistant" is not used for the AI in general. Copy says "the AI", "{brand} AI" (the key exists), or names the job ("Put an AI chat on your website", "Answer mail in Outlook"). The glossary line in the root `AGENTS.md` changes with UP7.
- [x] **D3 (UP7).** The guided widget setup keeps its product name **AI Setup Assistant** in all five locales. It is the one remaining meaning of "assistant", it is a helper rather than something people own, and renaming it touches 41 widget strings for no gain. *Alternative:* "Set up with AI"; revisit if the review of UP7 finds it confusing.
- [x] **D4 (UP6).** A person in live support is never an "agent": the visitor reads "a person from our team", the sender label is "Team" (`02` §6). Under every option, because the visitor must be able to tell a person from an AI.
- [x] **D5 (UP6).** The tool list of a topic is labelled **Tools** ("Tools this topic may use"), not "Available Agents". The two unrendered "Agents" keys (`chat.tools`, `widgets…availableTools`) are deleted in all locales.
- [x] **D6 (UP6).** `BPROMPTS` is a **topic** on every surface: the import kind, "A Saved Task runs this topic…", "Deleting this topic", "this topic's knowledge", the widget's "Customize prompt" button, and the sharing list. The IAM kind label for `assistant` (`BPROMPTS`) becomes "Topic"; the label for `agent` (`BAGENTS`) becomes the D1 word. *Alternative:* "instruction"; rejected because the page, the tab, the editor, the search and the route redirect already say topic.
- [x] **D7 (UP7).** Only words change. Database, classes, `/api/v1/agents`, `AGENTS.ENABLED`, IAM keys, the `assistant:<slug>` model alias, the `list_assistants` MCP tool, i18n namespace file names and test ids stay. The SPA route becomes `/ai/agents` and `/ai/agents/:id`; `/ai/assistants*` redirects for at least two releases, as the 2026-06 redirects do.
- [x] **D8 (UP7).** The rail section is restructured as well as renamed: rail item **Agents**; sidebar groups **Agents** (Agents, Shortcuts), **Automations** (Tasks, Approvals), **Settings** (AI settings). AI settings leaves the agents group because it sets workspace defaults. With `AGENTS.ENABLED` off the first group shows Shortcuts only and the rail item keeps its name. *Alternative:* rail item "AI"; rejected because it says nothing about what is inside.
- [x] **D9 (UP3).** One heading scale, as utilities in `style.css` (and checked under `.design-v2`): `type-section` 18 px / 600 (section heading inside a page), `type-card` 16 px / 600 (card or list-group title), `type-label` 12 px / 600 uppercase with `tracking-wide` (sidebar group labels, small section labels), `type-meta` 12 px / 400 (timestamps, sizes, secondary facts). The page title stays `PageHeader`'s 24 px. Readable text is never below 12 px; badges and counters never below 11 px; 10 px and 9 px go. *Alternative:* a `SectionHeader.vue` component; rejected for now because most headings sit inside existing card markup and a class is the smaller change. A component can follow when a second pattern (heading + actions) repeats three times.
- [x] **D10 (UP2).** One status chip: `.status-pill` with modifiers `--success`, `--warning`, `--error`, `--neutral`, `--info` on the `--status-*` tokens; an `info` pair is added for light and dark (OLED inherits dark). Text on a filled bar uses the `-text` token on the `-muted` background, never white on a 500 shade.
- [x] **D11 (UP2, UP3).** Two shrink-only ledgers in Vitest, in the style of `localeParityBaseline.json`: raw Tailwind status colours per file (baseline 740 in 125 files) and `text-[9px]` / `text-[10px]` per file (baseline 131). A file may go down or leave the ledger, never up or join it. *Alternative:* an ESLint rule; rejected because the Vue template classes are strings ESLint does not parse reliably here.
- [x] **D12 (UP2).** Contrast is checked in CI by a Playwright `@ci` spec that loads the walk's audit function on six pages (Chat, Library, AI settings, Apps, Chat widgets, Statistics) in light, dark and OLED, waits for the page to settle and fails on any non-disabled text below AA. No new dependency. *Alternative:* `@axe-core/playwright`; rejected for now because it is a new npm dependency (ask first) and the glass surfaces need the blend the audit function already does. Revisit if the spec flakes.
- [x] **D13 (UP4).** A knowledge folder shows its owner's name: the file list response gains a nullable `group_label` (OpenAPI-annotated, schemas regenerated) — the topic's name for `TASKPROMPT:<topic>`, the widget's name when that topic belongs to a widget, the agent's name for an agent's folder, null otherwise (the Library then shows "Folder" with no key). The raw key is never rendered.
- [x] **D14 (UP5).** English labels use **sentence case**; product and proper names keep their capitals (Synaplan, Microsoft 365, Claude Code, Nextcloud, WhatsApp, MCP). A ledger in `localeParity.spec.ts` freezes today's Title Case strings and may only shrink. Other locales follow their own grammar (German nouns stay capitalised) and are not gated.
- [x] **D15 (UP2).** The watermark stays above content (the reason in `AmbientBackground.vue` holds) and drops to `0.035` in dark and `0.025` under `.theme-oled`. *Alternative:* draw it behind cards with `mix-blend-mode`; rejected because the glass cards would then hide it on most pages.

## 1. Why — facts this plan rests on

1. Body and secondary text pass AA in all three themes on 13 pages (`01` §2). The contrast problem is narrow: chips, badges, one bar, and the watermark on OLED. A small, shared fix closes most of it.
2. The heading problem is structural: a rule exists for the page title and none for anything below it. Measured inversions: a category heading smaller than its cards, two heading levels at one size, file names smaller than body text (`01` V1).
3. Raw Tailwind status colours are 740 uses against 65 token uses. Every new screen copies the nearest neighbour, so the count only grows until something freezes it (`01` §4).
4. The Tasks tour stays open after leaving the page and dims the next one (`01` V11). It is the kind of "works only if you don't click" behaviour the stability bar forbids.
5. Internal keys (`TASKPROMPT:…`) and implementation words ("vectorized", "chunks", "AI sorter") are on screen in the Library and the topic editor (`01` V8).
6. The built object has two names across surfaces and the web app gives each word four or five meanings; in the sharing list they are swapped (`02` §1–§3).
7. Rows 21 and 22 will add new UI soon (research cards, the capacity page). Their copy in `20261017-research-quality/05_journeys_and_ui.md` already says "the assistant can read them"; the naming decision should be made before those strings ship.
8. Row 25 (agent handling, 2026-10-23) builds runs, helpers, notes and skills on the same object and fixes "Assistant" as its word. The more surfaces ship with one word, the more expensive the other becomes; D1 is cheapest now.

## 2. Target shape

```text
Rail      Chats · Library · Agents · Apps · Admin                  (D1, D8)
Agents    ├ Agents     Agents · Shortcuts
          ├ Automations Tasks · Approvals
          └ Settings   AI settings

Headings  PageHeader h1 24/600 · type-section 18/600 · type-card 16/600
          type-label 12/600 uppercase · type-meta 12/400 · body 14 · floor 12 (badges 11)
Status    .status-pill--{success,warning,error,neutral,info} on --status-* tokens
Gates     rawColorLedger · smallTextLedger · Title Case ledger · contrast @ci spec
Words     agent (built object) · the AI (in general) · topic (BPROMPTS) · tools · team (people)
```

## 3. Journeys

| Id | Journey | Walked in | Step |
| -- | ------- | --------- | ---- |
| J-UP-1 | A new administrator read "AI agents" on the website. In the app the rail says **Agents**; the page explains an agent in one sentence; **Create agent** builds one; they publish it to the group *Support*. A colleague in *Support* finds it within ten seconds in the chat's agent picker and under Agents → Shared with me. The administrator unpublishes it from the same row; the colleague's picker no longer offers it. Old bookmarks to `/ai/assistants` land on the new page. | the browser, dev stack, two accounts, en and de, light / dark / OLED, 320 px | UP7 |
| J-UP-2 | An administrator opens People → Groups → *Support* → "Shared with this group" after sharing one topic and one agent with it. The topic row says **Topic**, the agent row says **Agent**; each opens the right editor. Revoking either from the row works and the consequence sentence names the kind. | the browser, dev stack | UP6 (labels), UP7 (agent word) |
| J-UP-3 | A person reads Apps, Library, AI settings, Admin and Statistics. Every section heading is larger than the cards and text under it; file names are body size; every chip and badge is readable; no text is 10 px; in OLED the watermark does not show through the cards. The audit script reports zero failures on the six D12 pages in all three themes. | the browser at 1440 and 320 px, light / dark / OLED, en and de; the D12 spec | UP2, UP3 |
| J-UP-4 | A person starts the Tasks tour with the `?` button and, at step 1, clicks **Library** in the rail. The tour closes, the Library is not dimmed, and the tour counts as seen. Back on Tasks, `?` starts it again. | the browser | UP1 |
| J-UP-5 | A website visitor in the chat widget asks for a human. The notice reads "Someone from our team will join you shortly" and, after takeover, messages are labelled **Team**. Nowhere does the visitor read "agent". | the widget on the dev stack, en / de / fr | UP6 |
| J-UP-6 | A person opens the Library on a workspace with one widget that has knowledge. The folder is named after the widget ("Smoke RAG widget"), the file badge says the same, and the topic editor's knowledge tab says "Linked to: Smoke RAG widget". No `TASKPROMPT:` anywhere; the subtitle has no dangling "…". | the browser, dev stack with the seeded smoke widget | UP4 |

J-UP-1, -3, -4, -5 and -6 are browser journeys in the SPA or the widget
and carry the five exit bullets of UX contract §6 in their step below.
J-UP-2 is walked as part of UP6 and again after UP7.

## 4. What we do not rebuild

- `PageHeader.vue` and the page-title size; the rail itself (icons, order, width).
- The theme system and the token names; only an `info` pair is added.
- `assets/markdown.css` (chat answers already read well).
- Any API path, table, class, flag or IAM key (D7).
- The V2 glass design. Every utility is checked under `.design-v2`.

## 5. Step table

One step is one pull request on a branch of its own. The commit subject is
the step's first line. **UP1 and UP6 have no dependency and go first.**
UP2 and UP3 go next and should merge before row 21's RD3 / RW1 and row
22's AU7 open their UI pull requests. UP7 waits for D1–D3, D7 and D8. UP5
runs after UP7 so the two copy passes do not touch the same strings twice.

| Step | Commit subject | Release class | Depends on | Findings |
| ---- | -------------- | ------------- | ---------- | -------- |
| UP0 | `docs(planning): UX polish and naming — visual check, heading scale, readable chips, agent vs assistant` | no-app-impact | — | — |
| UP1 | `fix(ui): a guided tour ends when you leave its page; People and Widgets fit 320 px` | ota-candidate | — | V11, V12 |
| UP6 | `fix(copy): one word per thing — topics, tools, people in live support and the sharing list` | ota-candidate | D4–D6 | `02` §2 d, §3 b–c, §4 |
| UP2 | `fix(ui): status chips, badges and the budget bar are readable in every theme` | ota-candidate | D10–D12, D15 | V3, V4, V5, V2 (chips) |
| UP3 | `fix(ui): headings are larger than the text they head` | ota-candidate | D9, D11 | V1, V2 |
| UP4 | `fix(files): knowledge folders show their owner's name, not an internal key` | ota-candidate + backend-only (`group_label`, OpenAPI) | D13 | V8, V9, V10 |
| UP7 | `feat(ui): assistants are called agents` | ota-candidate | D1–D3, D7, D8; UP6 | `02` §1, V13 |
| UP5 | `fix(copy): English labels use sentence case` | ota-candidate | D14; after UP7 | V7 |
| UP8 | cross-repo: `docs: assistants are called agents` (synaplan-docs), `fix(i18n): one word for agents` (synaplan-desktop) | — (their own gates) | UP7 merged | `02` §1 |

UP7 is the only `feat`: renaming the main object is a visible product
change and should raise the minor version (root `AGENTS.md`, release
bump rule).

### UP1 — tours end with their page; 320 px (V11, V12)

**User-flow:** J-UP-4. Exit bullets (UX contract §6):

1. J-UP-4 walked and entered in STATUS (U10).
2. Findability: not applicable; the `?` button stays where it is (U2).
3. Copy: no new string (U3).
4. Empty, error, flag-off: a page without a tour shows no `?` as today; a tour whose target is missing skips the step as today (U5, U8, U11).
5. Dark, V2, 320 px: the People search and the widget list header fit at 320 px (U9).

Changes: `useTour.ts` destroys the active driver instance and marks the
tour seen when the route path changes (one `router.afterEach` registered
where the composable is created, removed on unmount). `UsersTab.vue:5` and
`WidgetList.vue:38`: `min-w-0 sm:min-w-[300px]`. Tests: a Vitest for
`useTour` (route change → `destroy` called, `activeTour` null, seen
recorded); `layout.spec.ts` asserts no horizontal overflow on People at
the chromium-mobile project.

Exit: `make ci-local`, `make test-e2e`, `make -C frontend test-e2e-layout` green; J-UP-4 in STATUS.

### UP6 — one word per thing, before the big rename (D4–D6)

**User-flow:** J-UP-2, J-UP-5. Exit bullets:

1. J-UP-2 and J-UP-5 walked; J-UP-5 in en, de and fr (U10).
2. Findability: the sharing list names the kind correctly, so the administrator finds the topic and the agent in ten seconds (U2).
3. Copy in five locales: "Topic" / "Tools" / "Team" per `02` §6; consequence sentences for revoking a topic and an agent name the kind (U3).
4. Empty, error, flag-off: with `AGENTS.ENABLED` off the sharing list shows topics only; the widget without live support shows no takeover copy (U5, U11).
5. Dark, V2, 320 px: the sharing list rows and the widget notice wrap at 320 px (U9).

Changes, all five locales: `admin.json` `people.groups.resourceKind`
(`assistant` → Topic, `agent` → Assistant until UP7, then Agent);
`config.json` `taskPrompts.availableTools` → "Tools this topic may use"
and its help text; delete `chat.json` `chat.tools` and
`widgets.json` `…availableTools`; `widgets.json` operator / takeover /
human-handoff strings → team wording; `assistants.json` import kind
`prompts` → Topics and the import result sentence; "this prompt" →
"this topic" in the topic editor and the widget "Customize prompt" button;
`localeParityBaseline.json` entries for these keys removed if they were
listed. No component change beyond the deleted keys.

Exit: `make ci-local` and `make test-e2e` green; `localeParity.spec.ts` green with no new ledger entries.

### UP2 — readable chips, badges and the budget bar (D10–D12, D15)

**User-flow:** J-UP-3 (contrast half). Exit bullets:

1. J-UP-3 walked in light, dark and OLED; the audit output for the six pages pasted into STATUS (U10).
2. Findability: not applicable; nothing moves (U2).
3. Copy: no new string (U3).
4. Empty, error, flag-off: the status chip covers every state the replaced chips had (success, warning, error, neutral, info); a disabled control keeps its 50 % opacity and is exempt (U5, U8).
5. Dark, V2, OLED, 320 px, WCAG AA: the D12 spec is the proof (U9).

Changes: `style.css` — `--status-info-{text,muted}` for light and dark;
`.status-pill` and its modifiers; `docs/FRONTEND_CONVENTIONS.md` gets a
"Status colours" table. Applied to the measured offenders: Chat widgets
stat chips and "Active" (`WidgetList.vue` and siblings), Statistics
"Active", "No", "Unknown" and the cost budget bar (`UsageStatistics.vue:97`
— `-muted` background, `-text` ink), the cost pills on AI settings ("High
cost", "Mid cost" — 11 px), Apps "Connected", Setup status "Ready" / "Off",
and the seven `text-red-600` without a `dark:` variant.
`AmbientBackground.vue` per D15. New tests:
`frontend/tests/unit/style/rawColorLedger.spec.ts` + `rawColorLedger.json`
(D11) and `frontend/tests/e2e/tests/contrast.spec.ts` (`@ci`, D12) that
injects `_devextras/planning/20261023-ux-polish-and-naming/walk/contrast-audit.js`
— moved to `frontend/tests/e2e/helpers/contrastAudit.ts` in this step so the
spec does not read from the planning tree. `visual.spec.ts` rail snapshots
re-recorded only if the rail changed, with the diff reviewed.

Exit: `make ci-local`, `make test-e2e` (incl. the new spec) green; the ledger spec fails on a deliberate new `text-green-600` in a scratch commit (recorded, discarded).

### UP3 — headings larger than the text they head (D9, D11)

**User-flow:** J-UP-3 (heading half). Exit bullets:

1. J-UP-3 walked at 1440 and 320 px, en and de (German labels are the longest) (U10).
2. Findability: file names at body size make the Library list scannable; nothing moves (U2).
3. Copy: no new string (U3).
4. Empty, error, flag-off: empty-state headings use `type-card`; nothing else changes (U5).
5. Dark, V2, OLED, 320 px: headings wrap, never truncate mid-word at 320 px (U9).

Changes: `style.css` — `type-section`, `type-card`, `type-label`,
`type-meta` (D9); `docs/FRONTEND_CONVENTIONS.md` gets a "Typography"
section with the scale and when to use which; `PageHeader.vue`'s doc
comment points at it. Applied to the walked surfaces: Apps category
headings (`type-section`) and card titles (`type-card`); Library "Storage
quota", "Your files" and file names (body 14 px); Admin dashboard
sections; AI settings section headings and the Topics tab's duplicate
`h2` removed; Statistics; Setup status; Settings → Profile; Memories;
the sidebar group labels (`type-label`, 12 px). Every `text-[9px]` /
`text-[10px]` in those files goes to 11 px (badges) or 12 px (text).
`frontend/tests/unit/style/smallTextLedger.spec.ts` + ledger (D11). The
remaining `h2` / `h3` outside the walked surfaces are left for the
surfaces' next pull request; the conventions doc says so.

Exit: `make ci-local`, `make test-e2e`, `make -C frontend test-e2e-layout` green; the heading list from the audit script for the walked pages in STATUS shows every section heading ≥ the largest text under it.

### UP4 — owner names instead of keys (D13)

**User-flow:** J-UP-6. Exit bullets:

1. J-UP-6 walked (U10).
2. Findability: a widget's knowledge is found in the Library under the widget's name in ten seconds (U2, U6).
3. Copy in five locales: folder label, "Linked to: {name}", the AI settings purpose labels in plain words (e.g. "Message sorting" → "Choosing a topic", "Embedding / Vectorization" → "Making files searchable", "Text analytics" → "Reading documents"), "1 chunks" → "1 passage" with plural forms (U3, U9).
4. Empty, error, flag-off: a folder whose owner was deleted shows "Folder" and no key; with the widget module off the folder still has a name (U5, U8, U11).
5. Dark, V2, 320 px: the badge truncates with a `title` (U9).

Changes: backend — `group_label` on the file list response, resolved in
the repository layer from `BPROMPTS` / `BWIDGETS` / `BAGENTS` in one query
per page (no N+1), OpenAPI property, a PHPUnit test per owner kind;
frontend — `make -C frontend generate-schemas`, `FilesTable.vue` and
`FilesView.vue` render `group_label ?? t('files.folder')`; the topic
editor's knowledge tab and title stop printing the topic key and the
"(custom)" / "(default)" prefix (the existing System / Custom badge says
it); `FilesTabs.vue:38` drops `files.introCta` (key deleted in all
locales); Apps category "E-mail" → "Email" (en; other locales checked).

Exit: `make ci-local`, `make test-e2e` green; `make -C backend phpstan` and `deptrac` green with no baseline change.

### UP7 — assistants are called agents (D1–D3, D7, D8)

**User-flow:** J-UP-1, J-UP-2 (second walk). Exit bullets:

1. J-UP-1 walked with two accounts in en and de, plus a glance at es, fr and tr (U10).
2. Findability: the colleague finds the shared agent in ten seconds in the picker and under Agents → Shared with me; `/ai/assistants` bookmarks redirect (U2).
3. Copy in five locales per `02` §6, native-speaker review recorded in the PR (U3, U9).
4. Empty, error, flag-off: empty Agents page is one sentence and **Create agent**; with `AGENTS.ENABLED` off the rail item stays, the Agents group shows Shortcuts only (D8), and no string says "agent" for something that is not there (U5, U11).
5. Dark, V2, OLED, 320 px (U9).

Changes: every meaning-a and meaning-b string of `02` §2 in all five
locales (about 190 in English); Ctrl+K keywords keep "assistant" as a
synonym; router per D7; nav per D8 (`useNavItems.ts`, `useNavSections.ts`);
`docs/UX_CONTRACT.md` §2; root `AGENTS.md` glossary (D2, D3); the research
copy in `20261017-research-quality/05_journeys_and_ui.md` §6 updated where
it says "assistant" (planning text only). E2E: specs use test ids, which
stay (D7); any spec that matches visible "Assistant" text is updated.

Exit: `make ci-local`, `make test-e2e`, `make -C frontend test-e2e-layout` green; `node scripts/mobile-impact.mjs --base main --head HEAD` = ota-candidate; J-UP-1 in STATUS.

### UP5 — sentence case (D14)

No new journey; the walk is a STATUS list of the surfaces re-read in
English. Changes: English values only, the walked surfaces first (auth,
chat, Library, AI settings, Apps, widgets, settings), product names kept;
the Title Case ledger added to `localeParity.spec.ts` with what remains.
Exit: `make ci-local` and `make test-e2e` green; the ledger diff is
removals only after the first commit that creates it.

### UP8 — the other repositories (cross-repo)

`synaplan-docs`: `assistants.md` → `agents.md` with a redirect, intro and
FAQ. `synaplan-desktop`: `assistant_not_bound` and any other "Assistant"
string, five locales, its own `make ci-local`. The website already says
agents; checked, not changed. Each pull request through its own gate.

## 6. Gates

Every step, in this order, before the pull request is marked ready:

1. `make ci-local`. Every step adds `make test-e2e` (all are user-visible); UP1, UP3 and UP7 add `make -C frontend test-e2e-layout`; UP4 adds `make -C frontend generate-schemas` before `vue-tsc`; every step adds `node scripts/mobile-impact.mjs --base main --head HEAD`.
2. The walk: the step's journey in the browser, light / dark / OLED, 320 px, and German for every copy step; the audit script's output for the touched pages in STATUS. A screenshot is not the walk.
3. Branch `fix/…`, `feat/…` per step; Conventional Commit subjects as in §5; no attribution trailers.
4. Pull request with the step row, the findings it closes, the walk entry.
5. Copilot review: every finding gets a reply on its thread — a fixing commit (hash) or one sentence why not. A finding that is right about something the plan missed goes into §10 in the same pull request.
6. **All Checks Passed** green on the final head.
7. STATUS entry: date, step, pull request, state, Copilot rounds, evidence.
8. Merge by the owner.

## 7. Risks

| Risk | Where it bites | Mitigation |
| ---- | -------------- | ---------- |
| The contrast spec flakes on glass surfaces or mid-transition | UP2 D12 | The audit waits for finite animations to finish (the trap that produced false numbers in the walk); approximated rows (`~`) are reported, not failed, until three green runs prove them stable. Fallback: run the script by hand per step and keep only the ledgers. |
| Renaming ~190 strings misses one and leaves "assistant" for the object | UP7 | A one-off grep list in the PR body (every remaining "assistant" with its meaning); the banned-word list in `localeParity.spec.ts` gains "assistant" for the `assistants` namespace, with "AI Setup Assistant" allowed. |
| Users and docs out in the world say "assistant" | UP7 | Ctrl+K keeps "assistant" as a synonym; `/ai/assistants` redirects; the docs page redirects; the release note names the rename. |
| Turkish "ajan" reads as "spy" to some readers | UP7 | Already used for the sharing kind; native review decides between *ajan* and *yapay zekâ ajanı* in headings. |
| The heading scale changes card heights and wraps German labels | UP3 | Walked in German at 320 px; the layout spec runs; visual snapshots reviewed. |
| `group_label` resolution adds a query per file | UP4 | One joined query per page in the repository; a PHPUnit test asserts the query count for a page of 50 files. |
| The tour fix records "seen" for a tour the person never finished | UP1 | That is the existing rule for close and Escape; leaving the page is a close. The `?` button restarts it. |
| Sentence case fights product names | UP5 | An allow-list of proper names in the ledger spec; reviewed in the PR. |
| D1 and row 25's §0 #3 are ticked differently, or not at all, and both plans ship copy | UP7, row 25 Phase A | The roadmap row of both plans names the conflict; UP7 and row 25's first UI step both list "word decided" as a dependency. |
| UP2 / UP3 lose the race with RD3 / AU7 | rows 21, 22 | The ledgers freeze the counts the day they merge; a later surface that adds raw colours fails CI and is pointed at the utilities. |

## 8. Non-goals

- A redesign, new colours or a new font.
- Every heading in the app in one pull request; UP3 does the walked surfaces and freezes the rest.
- Renaming code, tables, routes of the API, the flag, IAM keys or external aliases (D7).
- An `agent:<slug>` model alias next to `assistant:<slug>` (§9).
- The widget's visitor-side styling beyond the live-support copy (J-UP-5).
- German, Spanish, French and Turkish casing.

## 9. Later

1. `SectionHeader.vue` once heading + actions repeats three times after UP3.
2. Move the remaining `h2` / `h3` outside the walked surfaces to the scale as their surfaces are touched; the ledger shows progress.
3. An `agent:<slug>` alias and `list_agents` in the gateway and MCP, with the old names kept.
4. `@axe-core/playwright` if the hand-rolled contrast spec proves brittle (needs the owner's go for the dependency).
5. A walk of the widget, the mobile shell and the Outlook add-in with the same audit script.

## 10. Change log

| Date | Change |
| ---- | ------ |
| 2026-10-23 | Plan written from the owner's request for a visual check (heading sizes, contrast across themes) and the question whether agents are called assistants. Walk in [`01_visual_check.md`](./01_visual_check.md), naming map in [`02_naming.md`](./02_naming.md). |
| 2026-10-23 | Registered as row 26: agent handling took row 25 the same afternoon. Its §0 #3 ("Assistant" stays) contradicts D1; the conflict is named in D1, §1, §7 and both roadmap rows. |
| 2026-10-23 | §0 ticked by the owner, every row as proposed. D1 = option A, so agent handling §0 #3 is superseded; a dated note on that row points here. UP1 and UP6 start now, beside rows 21 and 22. |
