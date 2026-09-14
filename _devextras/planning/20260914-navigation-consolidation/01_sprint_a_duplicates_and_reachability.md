# Sprint A — Duplicates, reachability, one technique

**Steps `NV01`–`NV09`.** One home per concept; retired paths redirect; the
two "missing" surfaces become reachable; Memories behaves the same on every
device; the Summarizer becomes a chat tool.

**Goal:** after this sprint an administrator and a user can each name *the*
place for people, chats, usage, provider keys, connections, memories and
document summaries — and every old bookmark still lands somewhere sensible.

**User-flow:** [`../202609_ux_user_flows.md`](../202609_ux_user_flows.md);
journeys **J-NV-1 … J-NV-5** ([`00_master_plan.md`](./00_master_plan.md) §4).

**UX exit (§6 of the contract) — all five hold before the sprint closes:**

1. **Journeys (U10):** J-NV-1 … J-NV-5 walked in the browser, light + dark +
   V2, 1280 px and 320 px, screenshots on the PRs.
2. **Findability (U2):** every moved surface is reachable from its menu in
   one click *and* from its old URL; nothing is reachable only by URL.
3. **Consequence copy (U3):** "Remove key — video generation falls back to
   the server's key", "Delete memory — the AI forgets this", in en/de/es/fr/tr.
4. **Empty / error / flag-off (U5, U8, U11):** All chats empty state has one
   sentence + **New chat**; Your AI accounts with no provider module on
   → route 404s and no menu child; IAM-sharing off ⇒ no *Incoming* tab.
5. **Theme (U9):** tokens only; no new colours; WCAG AA checked on new pills.

---

## 0. Read first

| Path | Why |
| ---- | --- |
| `frontend/src/composables/useNavItems.ts` | The only place the menu shape changes |
| `frontend/src/router/index.ts`, `router/iamGuards.ts`, `router/navContext.ts` | Routes, guards, context classes, redirects block "§4.6" |
| `frontend/src/components/SidebarV2.vue` (account dropdown ~L219–L360, recent-chats sheet footer ~L604), `MobileNav.vue` (`handleOpenMemories` ~L763) | Account menu + Memories entry points |
| `frontend/src/views/AdminView.vue` (`tabs` ~L556, `UsersTab` ~L147), `views/PeopleView.vue`, `components/people/UsersTab.vue` | The two Users homes |
| `frontend/src/views/StatisticsView.vue`, `components/ChatBrowser.vue`, `views/IncomingChatsView.vue` | Chat archive today |
| `frontend/src/components/config/HiggsfieldConnection.vue`, `MessagesGatewayConfiguration.vue` (BYO block ~L82–L110), `services/api/higgsfieldCredentialsApi.ts` | User-level keys |
| `backend/src/AI/Credential/ProviderKeyCatalog.php`, `backend/src/Controller/AdminProviderKeysController.php`, `backend/src/Service/Admin/SystemConfigService.php` (tab `ai` ~L171–L180, fields ~L2150–L2260), `frontend/src/components/admin/ProviderKeyCard.vue`, `views/AdminConfigView.vue` | Instance keys in two places |
| `frontend/src/components/ToolsDropdown.vue` (`goToSummarizer` ~L389), `views/ToolsView.vue` (`doc-summary` branch ~L113), `components/summary/*`, `services/summaryService.ts`, `mocks/summaries.ts`, `composables/usePromoTips.ts` (`doc-summary`) | Summarizer surface |
| `frontend/src/components/MemoriesDialog.vue`, `views/MemoriesView.vue` (`?highlight` ~L305), `views/ChatView.vue` (`handleClickMemory` ~L4977) | Memories two techniques |
| `frontend/tests/unit/composables/useNavItems.spec.ts`, `tests/unit/router/iamGuards.spec.ts`, `tests/e2e/tests/{navigation,redirects,admin-panel,memories}.spec.ts` | Tests that lock today's behaviour and must move with it |

---

## 1. Steps

### NV01 — People is the only home for users

**Files:** `views/AdminView.vue`, `views/PeopleView.vue`, `router/iamGuards.ts`,
`composables/useNavItems.ts`, `i18n/*.json` (remove `admin.tabs.users` only if
unused after this), tests.

**Machine instructions**

1. `AdminView.vue`: delete the `users` entry from `tabs` and the
   `<UsersTab v-if="activeTab === 'users'" />` block and its import.
2. `iamGuards.ts`: `peopleRouteGuard()` returns `true` unconditionally (keep
   the export; other callers unchanged). `adminUsersTabRedirect()` returns
   `{ name: 'admin-people' }` whenever `to.query.tab === 'users'`, regardless of
   the IAM flag.
3. `PeopleView.vue`: Users tab always present (it is); Groups / Policies /
   Platform instances / Audit stay behind their flags. With every flag off the
   `TabNav` renders one tab — hide the tab bar when `tabNavItems.length === 1`
   (`v-if`), so the page reads as a plain list.
4. `useNavItems.ts`: `admin-people` child path is `/admin/people` always.
5. Confluence handle `O004` is gone; `O032` is always available.

**Tests**

- Unit `tests/unit/router/iamGuards.spec.ts`: `peopleRouteGuard` is `true`
  with the flag off; `adminUsersTabRedirect` redirects with the flag off.
- Unit `tests/unit/composables/useNavItems.spec.ts`: replace "sends People to
  /admin/people when IAM groups are enabled" with "People always points at
  /admin/people".
- Unit `tests/unit/views/PeopleView.spec.ts` (new): single tab ⇒ no tab bar;
  flags on ⇒ five tabs.
- E2E `admin-panel.spec.ts`: remove the Users-tab assertion; add
  `page.goto('/admin?tab=users')` → `expect(page).toHaveURL(/\/admin\/people/)`
  and `getByTestId('tab-users')` visible.

**Gate:** `make ci-local && make test-e2e`.
**Commit:** `refactor(nav): People is the only home for users; drop the Operate Users tab`

---

### NV02 — All chats page; Usage keeps usage only (D1)

**Files:** new `views/ChatsView.vue`, `views/IncomingChatsView.vue` (becomes a
tab body: extract `components/chats/IncomingChatsTab.vue`), `views/StatisticsView.vue`,
`components/SidebarV2.vue` (sheet footer), `router/index.ts`, `router/navContext.ts`,
`i18n/*.json`, tests.

**Machine instructions**

1. Route `/chats` → `ChatsView.vue` (`meta.titleKey: 'pageTitles.allChats'`,
   `requiresAuth`), context class **work** (add `/chats` prefix in
   `navContext.ts`; `/chats/incoming` already resolves to personal — change it
   to work as well, one class per page tree).
2. `ChatsView.vue` = `MainLayout` + `PageHeader` (title *All chats*) +
   `TabNav` with `all` (always) and `incoming` (only `features.iamSharing`).
   Tab `all` mounts `ChatBrowser`; tab `incoming` mounts the extracted
   `IncomingChatsTab`. Route `/chats/incoming` keeps its name `chats-incoming`
   and renders `ChatsView` with the incoming tab preselected (so the Account
   badge deep link and all existing incoming tests keep working).
3. `StatisticsView.vue`: remove the `#chats` section, the divider, the scroll
   code (this also deletes a `setTimeout`). Router: `beforeEnter` on
   `statistics` — if `to.hash === '#chats'` return `{ path: '/chats' }`.
4. `SidebarV2.vue` recent-chats footer button → `$router.push('/chats')`;
   add `data-testid="btn-chat-v2-show-all"`.
5. Empty state on `all`: one sentence + **New chat** (`btn-primary px-4 py-2.5
   rounded-lg`). `ChatBrowser` already has search/filter; do not fork it.
6. i18n: `pageTitles.allChats`, `chats.tabs.all`, `chats.tabs.incoming`,
   `chats.empty`, `chats.emptyAction` in five locales.

**Tests**

- Unit `tests/unit/views/ChatsView.spec.ts` (new): two tabs with sharing on,
  one without; `/chats/incoming` preselects incoming.
- Unit `tests/unit/router/navContext.spec.ts`: `/chats` and `/chats/incoming`
  → work.
- E2E new `chats-archive.spec.ts` (`@ci`): History → Show all → URL `/chats`,
  `page-chats` visible; `/statistics#chats` → `/chats`; Usage page has no
  `section-chat-browser`. Existing incoming-chats E2E must stay green
  unchanged.
- `redirects.spec.ts`: row `['/statistics#chats', '/chats']`.

**Commit:** `feat(chats): All chats page with Incoming tab; Usage keeps usage only`

---

### NV03 — Platform instances vs Linked platforms

**Files:** `views/PeopleView.vue`, `components/people/PlatformInstancesTab.vue`,
`components/config/LinkedPlatformsConfiguration.vue`, `i18n/*.json`.

**Machine instructions**

1. `people.tabs.linkedPlatforms` → **Platform instances** (de *Plattform-Instanzen*,
   es *Instancias de plataforma*, fr *Instances de plateforme*, tr *Platform
   örnekleri*). Add `people.linkedPlatforms.intro`: "Nextcloud, ownCloud and
   Outlook servers that asked to use this Synaplan. Approve one so its users can
   connect their own accounts under Manage › Connections › Linked platforms."
   Render it as the first paragraph of the tab.
2. `LinkedPlatformsConfiguration.vue`: for admins only (`authStore.isAdmin`),
   one sentence under the header: "Approving whole servers happens under
   Operate › People › Platform instances." with a `RouterLink` to
   `/admin/people?tab=linked-platforms`. `PeopleView` must read `?tab=` on mount
   (it does not today — add it, same pattern as `ProviderSetupView`).
3. Test ids unchanged (`tab-linked-platforms`).

**Tests**

- Unit `PeopleView.spec.ts`: `?tab=linked-platforms` preselects the tab.
- Unit `LinkedPlatformsConfiguration.spec.ts`: admin sees the pointer, user
  does not.
- i18n parity green.

**Commit:** `fix(people): rename platform approvals to "Platform instances" and cross-link both homes`

---

### NV04 — "Your AI accounts" page (D4)

**Files:** new `views/AiAccountsView.vue`, extract
`components/config/AnthropicByokSection.vue` from `MessagesGatewayConfiguration.vue`,
`components/config/HiggsfieldConnection.vue` (drop its `PageHeader`, becomes a
section body), `router/index.ts`, `useNavItems.ts`, `i18n/*.json`.

**Machine instructions**

1. Route `/ai/providers` → `AiAccountsView.vue`; `/ai/providers/higgsfield` →
   redirect `{ path: '/ai/providers', query: { section: 'higgsfield' } }`
   (keep for ≥ 2 releases; add to the "§4.6 redirects" block).
2. `AiAccountsView.vue`: `PageHeader` title **Your AI accounts**, subtitle
   "Use your own provider accounts instead of the server's. Keys are stored
   encrypted and only you can see them." Two sections (use the
   `AccordionSection` from NV13 once it exists; until then two `surface-card`
   blocks with `id="section-higgsfield"` / `id="section-anthropic"` and
   `?section=` scroll-into-view):
   - **Higgsfield — video generation** → existing `HiggsfieldConnection` body.
   - **Anthropic — coding clients** → `AnthropicByokSection` (status, masked
     key, save, remove, test — exactly the block moved from
     `MessagesGatewayConfiguration.vue`).
   Each section is rendered only when its module reports enabled
   (`useModuleFeature('higgsfield')`, gateway status `enabled`); both off ⇒
   route guard returns `{ name: 'not-found' }` and the nav child is absent
   (U11).
3. `MessagesGatewayConfiguration.vue`: replace the BYO block with one sentence
   and a link: "Using your own Anthropic key? Manage it under Your AI accounts."
4. `useNavItems.ts`: new child `{ key: 'ai-accounts', path: '/ai/providers',
   label: t('nav.aiAccounts') }` in group `assistants`, right after `ai-models`,
   present only when at least one section would render.
5. Consequence copy (U3) on both Remove buttons: "Remove key — video
   generation uses the server's key again." / "Remove key — coding clients use
   the server's key again."
6. i18n keys `nav.aiAccounts`, `aiAccounts.*` in five locales.

**Tests**

- Unit `AiAccountsView.spec.ts`: sections follow their modules; both off ⇒
  guard 404; `?section=higgsfield` scrolls/opens.
- Unit `useNavItems.spec.ts`: child present/absent with modules.
- E2E `navigation.spec.ts`: Manage flyout shows *Your AI accounts* when the
  seeded stack has Higgsfield enabled (use `helpers/features`).
- `redirects.spec.ts`: `['/ai/providers/higgsfield', '/ai/providers?section=higgsfield']`.
- Backend untouched.

**Commit:** `feat(ai): "Your AI accounts" page for Higgsfield and BYO Anthropic keys`

---

### NV05 — Models & keys is the one editor for instance keys (D2)

Two commits in one PR: `NV05a` backend-only, `NV05b` ota-candidate.

**Files (a):** `backend/src/AI/Credential/ProviderKeyCatalog.php`,
`backend/src/Service/Admin/SystemConfigService.php`,
`backend/src/Controller/AdminSystemConfigController.php` (OpenAPI for the new
field attribute), tests.
**Files (b):** `frontend/src/generated/api-schemas.ts` (regenerated),
`views/AdminConfigView.vue`, `components/admin/ConfigField.vue`,
`components/admin/ProviderKeyCard.vue`, `components/admin/plugs/ModelsAndKeysTab.vue`,
`i18n/*.json`.

**Machine instructions**

1. (a) Catalog coverage: add `thehive` (`THEHIVE_API_KEY`) and `elevenlabs`
   (`ELEVENLABS_API_KEY`) as single-key providers (validation may be `null`
   ⇒ the card says "Saved — not tested"). Add optional `secretEnvVar` to the
   catalog contract and register `higgsfield` (`HIGGSFIELD_API_KEY` +
   `HIGGSFIELD_API_SECRET`). `GOOGLE_VERTEX_ACCESS_TOKEN` stays a config field
   (it is a token, not a key).
2. (a) `SystemConfigService`: every field whose key equals a catalog `envVar`
   or `secretEnvVar` gets `'managedBy' => 'ai-infrastructure'` in its schema
   entry. The field stays readable (so `/api/v1/admin/system-config` output
   does not change shape) but `save()` **refuses** writes to a `managedBy` field
   with a 422 whose message names the new home. OpenAPI: `managedBy` is an
   optional string enum on the field schema.
3. (b) `make -C frontend generate-schemas`, `vue-tsc`.
4. (b) `AdminConfigView.vue`: in a section, if **every** field is `managedBy`,
   render one **status card** instead of the fields (D2 Helm-first): which
   keys are set from the environment / Helm, which have a UI override, the
   sentence "Provider keys are managed under AI infrastructure › Models &
   keys." plus "A chart install does not need this page", and a `RouterLink`
   to `/admin/setup` for an optional override. If only some fields are
   managed, hide those and show the same status sentence below the remaining
   fields. `ConfigField.vue` never renders a `managedBy` field. Never require
   a UI save for helm-injected keys.
5. (b) `ProviderKeyCard.vue`: support the optional secret (second password
   input, same chain of classes). Keep the 2-up grid (`md:grid-cols-2`).
6. (b) Wording: `adminSetup.cloudProviders` becomes "Provider keys"; hint
   sentence explains "one place for every key; media and speech providers
   included".

**Tests**

- PHPUnit `SystemConfigServiceTest::testManagedByFieldsRefuseSave`,
  `ProviderKeyCatalogTest::testEveryCatalogEnvVarIsMarkedManagedInSystemConfig`
  (the coverage lock: a new provider key added to system config without a
  catalog entry fails the suite).
- Vitest `AdminConfigView.spec.ts`: all-managed section ⇒ helm/env status
  card (no password inputs) that still names "AI infrastructure › Models &
  keys"; mixed ⇒ hidden fields + sentence.
- Vitest `ProviderKeyCard.spec.ts`: secret input appears only when
  `secretEnvVar` is set; a key with `source=env` shows the helm/env badge
  and does not look unsaved.
- E2E `admin-panel.spec.ts` or new `provider-keys.spec.ts` (`@ci`):
  `/admin/config?tab=ai` shows the status card and no `input[type=password]`
  for `OPENAI_API_KEY`; `/admin/setup` shows a card for every provider in the
  catalog. A seeded env key is already "set" without a UI save.

**Commit:** `feat(admin): Models & keys is the one editor for instance provider keys`

---

### NV06 — Connections vs Developer & devices

**Files:** `useNavItems.ts`, `i18n/*.json`, `useNavItems.spec.ts`,
`navigation.spec.ts`, `MobileNav.spec.ts`.

**Machine instructions**

1. Group **Connections** (`connections`): `connections` (label → *Connected
   apps*, **always shown to signed-in users** — D5 ungate; drop the
   `isSavedTasksEnabled()` wrap around this child), `mcp-servers`,
   `linked-platforms`. Custom tools on that page stay behind their own
   module flag (`features.toolsCustomHttpEnabled`) so U11 still holds.
2. New group **Developer & devices** (`developer`, `t('nav.groupDeveloper')`):
   `api-keys`, `api-docs`, `ai-agents` (Coding clients — moved out of
   Automations), `desktop`.
3. Automations keeps `saved-tasks`, `approvals`.
4. Order inside Manage: Assistants · Automations · Channels · Connections ·
   Developer & devices.
5. Group test id becomes `btn-sidebar-v2-group-developer`; child ids unchanged.

**Tests**

- Unit `useNavItems.spec.ts`: group order and membership; Coding clients under
  `developer`; Desktop absent when its flag is off.
- E2E `navigation.spec.ts` "Manage flyout opens with group entries" — extend
  the expected group list; `layout.spec.ts` "More section expands with
  accordion sections" — the mobile accordion now has five groups.

**Commit:** `refactor(nav): split Connections into Connections and Developer & devices`

---

### NV07 — Summarize a document runs in the chat (D7)

**Files:** `components/ToolsDropdown.vue`, `components/ChatInput.vue`, new
`composables/useSummarizeTool.ts`, `views/ChatView.vue` (read `?tool=summarize`),
`router/index.ts`, `useNavItems.ts`, `views/ToolsView.vue`, delete
`components/summary/SummaryConfiguration.vue`, `components/summary/SummaryResultModal.vue`,
`mocks/summaries.ts`, `services/summaryService.ts` (frontend only; the backend
endpoint stays), `composables/usePromoTips.ts`, `i18n/*.json`.

**Machine instructions**

1. `useSummarizeTool.ts`: `buildSummarizeInstruction({ length: 'short' | 'medium' | 'long', language: string })`
   returns the localized instruction sentence (from i18n, not hardcoded), e.g.
   "Summarize the attached document. Length: medium. Answer in German." The
   default language is the UI locale.
2. `ToolsDropdown.vue`: replace the *Summarizer → opens the tool* row with
   **Summarize a document** (`data-testid="btn-tool-summarize"`). Clicking it
   emits `summarizeDocument`; `ChatInput` handles it: opens the existing attach
   picker (`btn-plus-attach` path — reuse the same function, no second file
   input), and after a file is attached inserts the instruction into the
   composer if the composer is empty. Two small selects for length and
   language appear inline above the composer only while the tool is armed
   (`data-testid="summarize-options"`), full house form-control chain, hidden
   again on send or when the attachment is removed.
3. `ChatView.vue`: on mount, `route.query.tool === 'summarize'` arms the tool
   the same way and then strips the query (`router.replace`).
4. Router: `/ai/summarizer` → redirect `/?tool=summarize`; legacy
   `/tools/doc-summary` → same target (update the existing redirect row).
5. `useNavItems.ts`: remove the `doc-summary` child. `usePromoTips.ts`:
   `actionRoute: '/?tool=summarize'`.
6. Delete the `doc-summary` branch in `ToolsView.vue` and its imports; delete
   the summary components, mock and service after `rg` confirms no other
   importer. `chatInput.tools.summarizer*` keys are replaced by
   `chatInput.tools.summarize*`; remove `nav.toolsDocSummary`,
   `pageTitles.docSummary`, `tools.docSummaryDescription` if unused.
7. Result is a normal assistant message (U4.7 "card in the thread, not a new
   page"). Failure copy comes from the existing chat error path.

**Tests**

- Unit `useSummarizeTool.spec.ts`: instruction per length/language; default
  language is the UI locale.
- Unit `ToolsDropdown.spec.ts` (extend): row present; emits `summarizeDocument`.
- Unit `ChatInput.spec.ts` (extend): options appear when armed, vanish on
  send / attachment removed; composer prefilled only when empty.
- E2E new `summarize-tool.spec.ts` (`@ci`, provider-free): open Tools →
  Summarize a document → options visible → `/ai/summarizer` redirects to `/`
  with the tool armed; `navigation.spec.ts` asserts no `link-sidebar-v2-doc-summary`.
- `redirects.spec.ts`: `['/ai/summarizer', '/?tool=summarize']`,
  `['/tools/doc-summary', '/?tool=summarize']`.

**Commit:** `feat(chat): Summarize a document runs in the chat; retire the Summarizer page`

---

### NV08 — One Memories page (D3)

**Files:** `components/SidebarV2.vue`, `components/MobileNav.vue`,
`views/ChatView.vue`, `components/MessageMemories.vue`, `components/MessageText.vue`,
`components/ChatMessage.vue` (event comments), `views/MemoriesView.vue`, delete
`components/MemoriesDialog.vue`, tests.

**Machine instructions**

1. `SidebarV2.vue`: `handleOpenMemories` → `handleNavigate('/memories')` when
   memories are enabled (keep the `/profile?highlight=memories` branch when
   disabled). Delete the `MemoriesDialog` import, ref and template line.
2. `ChatView.vue`: `handleClickMemory(memory)` →
   `router.push({ path: '/memories', query: { highlight: String(memory.id) } })`.
   Remove the dialog ref, template block and `closeMemoriesDialog`. Update the
   three "stay in chat" comments to "opens the Memories page; browser Back
   returns to this chat".
3. `MemoriesView.vue`: keep `?highlight`; add a **Back to chat** secondary
   button in the header when `history.length > 1` (`router.back()`); scroll
   the highlighted card into view without `setTimeout` (use `nextTick` +
   `onMounted` after the list resolves — a `watch` on the loaded list).
4. Delete `MemoriesDialog.vue`; the rich list already lives in
   `MemoryListView.vue`, which the page uses.
5. `MobileNav.vue` already navigates — verify, no change expected.

**Tests**

- Unit `MemoriesView.spec.ts` (new or extended): `?highlight=42` marks card
  42; Back button calls `router.back`.
- Unit `SidebarV2` / `MobileNav` specs: Memories entry navigates to
  `/memories` on both.
- E2E `memories.spec.ts`: replace `modal-memories-dialog` expectations with
  `page-memories` + URL; add: click a memory badge in a chat → URL
  `/memories?highlight=` → `page.goBack()` → same chat visible.

**Commit:** `refactor(memories): one Memories page on every device; retire the dialog`

---

### NV09 — Journey specs and redirect matrix

**Files:** `frontend/tests/e2e/tests/nav-journeys.spec.ts` (new),
`redirects.spec.ts`, `docs/E2E_TESTING.md` (one paragraph).

**Machine instructions**

1. One `test.describe('@ci Navigation journeys')` with J-NV-1 … J-NV-5 as
   five tests, each written as the journey sentence in §4 of the master plan
   (click, type, find, undo). Provider-free: the summarize journey stops at
   "tool armed and attachment shown", it does not send.
2. `redirects.spec.ts`: the full row list after Sprint A:
   `/admin?tab=users → /admin/people`, `/statistics#chats → /chats`,
   `/ai/providers/higgsfield → /ai/providers?section=higgsfield`,
   `/ai/summarizer → /?tool=summarize`, `/tools/doc-summary → /?tool=summarize`.
3. `docs/E2E_TESTING.md`: "Navigation journeys" paragraph naming the spec and
   the rule "a moved route adds a redirect row in the same PR".

**Commit:** `test(nav): journey specs J-NV-1…5 and redirect matrix`

---

## 2. Invariants (do not break)

- Guest rail stays History only; signed-in rail stays New · History · Sources
  · Manage · (Plugins) · (Operate) · Upgrade · Account. **No new rail item.**
- Widget bundle: `nav.*` keys used by the widget build stay (check
  `frontend/src/widget.ts` imports before deleting any key).
- Live support, plugin pages, Outlook `/connect/platform`, shared-chat links:
  untouched paths.
- `/chats/incoming` keeps its route name, test ids and badge behaviour.
- `/api/v1/summary/generate` stays (API users, Nextcloud).

## 3. Exit / demo

1. J-NV-1 … J-NV-5 walked; screenshots in the five PRs.
2. `redirects.spec.ts` has one row per retired path and is green.
3. `useNavItems.spec.ts` describes the new Manage groups exactly; the
   Confluence handle map (master §2) matches the running app row by row.
4. Full gate green on every PR; `mobile-impact` reads `ota-candidate`
   (NV05 PR: `backend-only` + `ota-candidate`).
