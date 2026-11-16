# Step briefs

For [`00_master_plan.md`](./00_master_plan.md) §5. One brief is one branch
and one pull request. "Reads" are the files to open before writing code.
"Tests first" are written before the change and fail without it. Paths are
under `backend/src/` and `frontend/src/` unless said otherwise. Contracts:
[`03_contracts.md`](./03_contracts.md). Copy and wireframes:
[`02_journeys_and_ui.md`](./02_journeys_and_ui.md).

Every brief ends with the gate of root `AGENTS.md`: `make ci-local`
unfiltered, `make test-e2e` when the UI, a route or chat is touched, all
five locales, the five exit bullets for an `ota-candidate` step.

---

## Phase 0 — measure

### PJ0 — the plan

- **Goal:** this directory, roadmap row 46, README row.
- **Exit:** the owner has ticked or changed §0. Until then nothing starts.

### PJ1 — corpus and today's numbers

- **Goal:** a fixed set of turns and what they cost and deliver today, so
  that G1–G7 compare against numbers and not against memory.
- **Reads:** `backend/tests/Characterization/`, the research corpus of
  row 21, `Service/Message/MessageProcessor.php`.
- **Do:** about 40 turns in five groups — simple (no tool needed), needs
  one search, needs the chat's files, multi-step, long research — plus
  three scripted chats: 150 messages with a picture at message 5; a search
  stub that always returns the same; a tool stub that always fails. A
  command that runs the corpus against a stack and writes cost, time to
  first token, total time, backed-statement rate.
- **Tests first:** the runner fails when a turn has no terminal result.
- **Touches:** `backend/tests/Eval/ChatLoop/` (new), no product code.
- **Exit:** today's numbers per group in `STATUS.md`, with the command.

### PJ2 — spike: a chat turn as run units

- **Goal:** answer §0 #21 and prepare G1 and G7 with measurements.
- **Reads:** `Service/Chat/Run/ChatRunService.php`,
  `Service/Research/Run/` (units, lease, reaper),
  `20261021-architecture-update/spike/`.
- **Do:** a throw-away branch, not merged as product code. Variant 1: every
  turn is queued. Variant 2: first call in the request, hand-over on the
  first tool call. Measure time to first token for simple turns, the gap at
  the hand-over, and a two-hour run on a small-window model with forced
  compactions.
- **Exit:** a results table in `STATUS.md`; §0 #21 confirmed or changed;
  the list of what AU26 and AH8 must expose, sent to those plans.

---

## Phase A — projects for one person

### PJ3 — projects with sources and instructions (API)

- **Goal:** J-PJ-1 works through the API.
- **Reads:** `Module/Chat/ResearchModule.php` (module pattern),
  `Service/Knowledge/` and `RagScopeResolver`, `UrlContentService`,
  `FileIngestExecutor`, `ChatSystemPromptAssembler`, `Entity/Chat.php`,
  `docs/MIGRATIONS.md`.
- **Tests first:** a chat in a project finds a sentence from a PDF source
  and from a pasted text; a chat outside does not; a link source that
  answers 403 ends as `failed` with a reason; the pinned folder cannot be
  overridden by a request parameter; flag off → 404.
- **Do:** migration (`BPROJECTS`, three `BCHATS` columns); `ProjectsModule`;
  entity, repository, `ProjectService`, `ProjectSourceService`, a thin
  controller; link and text ingestion; server-side folder pin and the
  instructions block; OpenAPI and Zod.
- **Touches:** new `Service/Project/*`, `Controller/ProjectController.php`,
  `Entity/Project.php`; small edits in `ChatSystemPromptAssembler` and the
  RAG pin. No row added to the size ledger.
- **Exit:** the tests; deleting a project with `chats: keep` leaves plain
  chats behind that no longer see the sources.

### PJ4 — project page and projects in the Chats panel

- **Goal:** J-PJ-1 in the browser.
- **Reads:** `components/sidebar/SidebarPanelChats.vue`,
  `ChatHistoryList.vue`, `KnowledgeFolderPicker.vue`, the Files page's
  upload rows, UX contract §4.
- **Tests first:** Vitest for the source row states; Playwright `@ci`:
  create a project, add a text source, ask, see the source named.
- **Do:** section "Projects" in the panel; route `/projects/:id` with tabs
  Chats, Sources, Instructions (Memory and Members arrive with PJ20–PJ22);
  "Add source" dialog; project name in the composer of a project chat;
  move a chat into and out of a project from its menu.
- **Touches:** new `views/ProjectView.vue`, `components/projects/*`,
  `stores/projects.ts`, `services/api/projectsApi.ts`; locale files.
- **User-flow:** J-PJ-1, both themes, phone width.
- **Exit:** the five bullets; no component above 300 lines.

---

## Phase B — the chat works as long as it needs

Starts when AU26 and AH8 are on `main`.

### PJ5 — a chat turn runs on the one loop as a run

- **Goal:** with `LOOP.ENABLED`, a web chat turn follows `03` §1.1.
- **Reads:** AU26's loop and AH8's kernel as merged;
  `ChatStreamOrchestrator`, `MessageProcessor`, `TurnDispatcher`,
  `ChatHandler`, `ToolRegistry`, `PromptCatalog`, PJ2's results.
- **Tests first:** a simple turn creates no `BRUNS` row and makes one model
  call; a turn that needs a search is promoted and ends `completed`; the
  stream continues over the hand-over without a lost or doubled chunk; a
  killed worker ends in `interrupted` and a true sentence; flag off and a
  model without `tool_use` take today's path byte for byte
  (characterization snapshots unchanged).
- **Do:** run profile `chat` (no steps, no minutes); the prompt
  `tools:chat_loop`; the hand-over; skipping the search classifier, the
  auto-research gate and the task planner when the loop is on; the check
  before delivery; usage recorded per call.
- **Touches:** new `Service/Chat/Loop/*`; small edits where the turn is
  dispatched. `MessageProcessor` and `TurnDispatcher` do not grow.
- **Exit:** the tests; PJ1's simple group within G1 on the dev stack.

### PJ6 — cost limit that ends with a result, and circle detection

- **Goal:** `03` §1.3 and §1.4.
- **Reads:** `ResearchBudgetConfig`, `StageEnds`, `RateLimitService`.
- **Tests first:** a run with a cap of 0.05 ends `budget_used` with an
  answer that starts with the opening line; the spend is never above the
  cap by more than one call; the two stubs of PJ1 end in a wrap-up with
  reason `circling` after exactly one nudge; a run that finds something new
  every step for 200 steps is not stopped; an unpriced model stops at the
  token cap.
- **Do:** `RunCostGuard`, `StallDetector`, the wrap-up call, the four
  reasons, `continueFrom`.
- **Touches:** new classes under `Service/Chat/Loop/`.
- **Exit:** G4 and G5 on the corpus.

### PJ7 — run notes, chat search and files by ID

- **Goal:** `03` §1.5 and the three tools; J-PJ-5 works.
- **Reads:** `ConversationSummaryService`, `ConversationFileCatalog`,
  `ReadDocumentTool`, `AnalyzeImageTool`, the image generator's source
  image input, `MessageRepository` (frozen in the size ledger — add the
  search query in a new repository class).
- **Tests first:** the 150-message chat edits the picture of message 5; a
  fact from message 10 is quoted after two compactions; notes never contain
  image data; a failed compaction ends in a wrap-up.
- **Do:** `RunNotes`, `TranscriptCompactor`, `list_files`,
  `conversation_search`, file-ID input for the read and image tools, the
  `notes.compacted` event, `BRUNS.BNOTES`.
- **Exit:** G6 and G7.

### PJ8 — run card

- **Goal:** J-PJ-2 and J-PJ-4 in the browser.
- **Reads:** `ProcessingTimeline.vue`, `ResearchTurn.vue`,
  `TaskPlanBubble.vue`, `services/chat/stream/handlers/`, AH11's brief (the
  card is built once).
- **Tests first:** Vitest: the card renders plan, current step, the
  summarized line, folded state; Playwright `@ci`: start a promoted turn,
  reload, the card is back; Stop keeps the text.
- **Do:** `RunCard.vue` and small parts; working and needs-you marks on the
  chat row; "Answer now", "Stop", "Keep going"; the divider at the summary
  watermark.
- **Touches:** new `components/chat/run/*`; `ChatMessage.vue` gets a slot
  call, not the logic.
- **User-flow:** J-PJ-2, J-PJ-4; two tabs; phone width.
- **Exit:** the five bullets.

### PJ9 — the chat asks you

- **Goal:** J-PJ-3.
- **Reads:** `AskUserRunner`, `ChatAskUserController`, `TaskCard.vue`
  lines 427–478.
- **Tests first:** backend: `ask_user` puts the run to `waiting_for_you`,
  the answer resumes it, a second answer is refused, 24 hours resume it as
  skipped, a non-starter gets 403. Frontend: keyboard walk through two
  questions; read-only for others.
- **Do:** the tool, `POST /runs/{uuid}/answer`, `AskUserCard.vue` extracted
  from `TaskCard.vue` and used by both, the copy change of the read-only
  line.
- **Exit:** the five bullets; `TaskCard.vue` is shorter than before.

### PJ10 — the Chat model runs the whole turn

- **Goal:** §0 #20.
- **Reads:** `PlannerModelCard.vue`, `AIModelsConfiguration.vue`,
  `ResearchAdminService`, the planner-model endpoint.
- **Do:** remove the card from the personal page; keep the endpoint for
  Desktop until its plan says otherwise; one line next to a Chat model
  without tool calling ("answers in one step"); the admin page keeps
  reading and checking models.
- **Tests first:** Vitest for the model line; Playwright: the page has no
  planner card.
- **Exit:** the five bullets; locale keys of the card removed in all five
  files.

### PJ11 — the loop is on by default

- **Goal:** §0 #65.
- **Do:** run the corpus on staging; write G1–G7 with numbers into
  `STATUS.md`; a migration `UPDATE` that sets `LOOP.ENABLED` to 1; the
  seeder default follows.
- **Exit:** all seven gates green; one week on `web.synaplan.com` for
  staff accounts first (the flag can be pinned per environment).

---

## Phase C — shared chats

### PJ12 — write together in one chat (API)

- **Goal:** J-PJ-6 through the API.
- **Reads:** `ShareService` (in the size ledger — add, do not grow),
  `AccessGate`, `StreamTurnPreparer::loadChat()`, `CancelledTurns`,
  `ChatAudienceNotifier`, `ChatMemoryContextLoader`,
  `PendingMemoryExtraction`, the digest command, `Entity/Message.php`.
- **Tests first:** a member with `edit` posts and gets an answer; `read`
  and `use` cannot; the author is stored; G10 as tests (no memory read, no
  extraction, no digest, no tail); two members posting at once produce one
  run and one queued message; `@Ben` at the start gets no answer; owner
  queries on `BUSERID` still return the whole chat.
- **Do:** `edit` on `ConversationKind`; the gate in the two places;
  `BMESSAGES.BAUTHORID` and `Message::authorId()`; "counts as shared";
  memory, digest and tail switches; author names in history and summary
  input; `GET /chats/{id}/members`; `SharedChatsModule`.
- **Touches:** new `Service/Chat/Shared/*`.
- **Exit:** the tests; a chat that was never shared behaves exactly as
  before.

### PJ13 — names on messages and "Can write" in the share dialog

- **Goal:** J-PJ-6 in the browser.
- **Reads:** `ShareDialog.vue`, `utils/shareCopy.ts`,
  `SharedConversationBanner.vue`, `ChatMessage.vue`, `stores/realtime.ts`,
  `style.css` tokens.
- **Tests first:** Vitest: `UserAvatar.vue` picks the same colour for the
  same user, every colour passes AA in both themes; Playwright with two
  seeded accounts: share, both write, names and "Reply to" are right.
- **Do:** `UserAvatar.vue`; author row on other people's messages; "Reply
  to {name}"; the third level and its consequence text; the warning for a
  chat with messages; live refresh on `chat.activity`; the queued mark.
- **User-flow:** J-PJ-6 with two browsers.
- **Exit:** the five bullets.

### PJ14 — who pays in a shared chat (API)

- **Goal:** `03` §5.
- **Reads:** `RateLimitService`, `UseLog`, row 23's plan.
- **Tests first:** G11 as tests — the owner's usage is not counted; the
  switch happens at the exact call; two parallel runs cannot overdraw by
  more than one call each; an owner with an empty monthly budget counts as
  used up; a sender without budget inside a run gets the wrap-up.
- **Do:** `BCHATALLOWANCES`, `PayerResolver`, reserve and settle around
  every model call, usage metadata, the notice line, the endpoints.
- **Exit:** the tests.

### PJ15 — allowance bar

- **Goal:** J-PJ-7 in the browser.
- **Reads:** `UsageBudgetCard.vue` (extract the bar), `ShareDialog.vue`.
- **Do:** "Who pays" in the share dialog; mode line and bar in the chat
  header; amount for the owner only; the notice line.
- **Tests first:** Vitest for both roles; Playwright: set an amount, a
  member's message moves the bar, the owner's does not.
- **Exit:** the five bullets.

### PJ16 — my apps in this chat (API)

- **Goal:** `03` §6.3; G8 and G9.
- **Reads:** `ToolRegistry` sources, `ConnectedSystemsHint`,
  `ConnectedSystemMention`, `PlannerChannelCatalog`, `ApprovalPolicy`,
  `ApprovalService`.
- **Tests first:** the injection corpus (20 cases: planted orders, indirect
  asks, orders inside a file, orders inside a tool result) → zero
  unasked calls; another member's app is never in the tool list; a write
  asks even with "always allow"; a grant is gone when the member leaves.
- **Do:** `BCHATAPPGRANTS`; the tool list from the sender's grants;
  `AppUseCheck`; the approval override; `apps_used`; the endpoints. Works
  with the loop on and, with the loop off, for the document and planner
  paths that call apps today.
- **Exit:** G8 and G9 with the corpus in CI.

### PJ17 — app switches in the composer, data chip on answers

- **Goal:** J-PJ-8 in the browser.
- **Reads:** `ToolsDropdown.vue`, `apps/catalog.ts`.
- **Do:** "My apps in this chat" in the plus menu, shown only in a shared
  chat; app marks in the member list; the chip.
- **Tests first:** Playwright with two accounts and a stub MCP server.
- **Exit:** the five bullets.

### PJ18 — spike: Notion limited to chosen pages

- **Goal:** decide §0 #49 between the two ways of `01` §2.4.
- **Do:** against a test workspace: fetch with an ancestor check through
  the hosted MCP server (cost per fetch, failure cases, moved pages); a
  public integration with Notion's page picker (what the connection flow
  looks like for a person who already connected the hosted server).
- **Exit:** a recommendation in `STATUS.md`. No product code.

### PJ19 — narrow an app to a folder, a calendar or pages

- **Goal:** `03` §6.4.
- **Depends:** PJ18's result for the Notion row.
- **Tests first:** a WebDAV grant with `folder` cannot read a sibling or a
  parent (path traversal cases); a Microsoft 365 grant with `mail: false`
  has no mail tool; a Notion grant refuses a page outside the list.
- **Do:** `BSCOPE` enforcement per app; the small pickers in the switch
  row.
- **Exit:** the five bullets.

---

## Phase D — shared projects and project memory

### PJ20 — share a project

- **Goal:** J-PJ-9, step 1; §0 #55.
- **Reads:** IAM kinds (`KnowledgeFolderKind` as the model), `AccessGate`,
  `SharedInbox`, PJ4, PJ13.
- **Tests first:** a member with `read` sees shared chats and sources and
  cannot write; `edit` can add a source and create a chat; a private chat
  of one member is invisible to the others, including the owner; removing
  a member removes access to folder and chats at once.
- **Do:** `ProjectKind`; derived access to the folder and the shared
  chats; "Share with the project" on a chat; Members tab; the project in
  "Shared with me".
- **Exit:** the five bullets.

### PJ21 — project memory (API)

- **Goal:** `03` §7, memory.
- **Reads:** `MemoryExtractionService`, `UserMemoryService` (in the size
  ledger — nothing is added there), `ChatMemoryContextLoader`.
- **Tests first:** an entry is written from the sender's message and names
  the sender; nothing is written from a private chat in a shared project;
  nothing is ever copied from `BUSERMEMORIES`; a new statement of the same
  author replaces the old one; G12's corpus.
- **Do:** `BPROJECTMEMORIES`, the Qdrant collection, extraction, the
  prompt block with names, rule 8 of the prompt, the endpoints, flag
  `PROJECTS.MEMORY_ENABLED`.
- **Exit:** G12.

### PJ22 — memory tab and memory choice per chat

- **Goal:** J-PJ-9 step 4 and J-PJ-10.
- **Do:** tab "Memory" with delete; "Memory" in the composer menu of a
  project chat; the lock when the chat is shared.
- **Tests first:** Vitest for the three states of the choice; Playwright
  for the conflict journey with two accounts.
- **Exit:** the five bullets.

---

## Phase E — push

### PJ23 — spike: push through a relay

- **Goal:** prove `03` §8 on real phones before anything is ordered.
- **Do:** a minimal relay, a development build of the app with the push
  plugin, a server behind NAT. Measure delivery, check the store rules for
  a generic text, list what the Apple and Google accounts need.
- **Exit:** G13's cases walked by hand; findings in `STATUS.md`; the
  account and signing details go to the private repos, not here.

### PJ24 — devices and signed push requests (API)

- **Goal:** `03` §8.2, §8.4, §8.5 on the server.
- **Reads:** `Service/PlatformLink/NotificationToken.php`, `NotifyKeyStore`,
  `SignedNotificationClient`.
- **Tests first:** a request is signed and verifies with the published
  key; no request without a device, with the switch off, or after `seen`;
  a shared chat notifies only the starter; rotation keeps old devices
  working.
- **Do:** `BPUSHDEVICES`, the two switches, `PushNotifier`, the endpoints,
  `PushModule`.
- **Exit:** the tests against a relay stub.

### PJ25 — relay service (`synaplan-platform`)

- **Goal:** `03` §8.1–§8.3. Own repository, own plan and PR there.
- **Exit:** the contract tests of PJ24 pass against the real relay.

### PJ26 — push in the app shell (`synaplan-apps`)

- **Goal:** registration, permission, tap-to-open. Own PR there; a store
  release.
- **Exit:** G13 on TestFlight and an internal Android track.

### PJ27 — tell the phone when a run is done or needs you

- **Goal:** J-PJ-11.
- **Do:** the in-context sheet after the first promoted run; Settings →
  Notifications with two switches, shown only inside the app; `seen`; the
  deep link from `ref` to the chat.
- **Tests first:** Vitest: the section is absent in a browser; the sheet
  appears once.
- **Touches:** keep the web part out of the paths the mobile policy marks
  `store-required`; the native bridge ships with PJ26.
- **Exit:** the five bullets; walked on both phones against the cloud and
  a self-hosted server.

---

## Phase F — rollout

### PJ28 — docs and flags

- **Do:** `docs/FEATURES.md`, a user page each for projects, shared chats
  and long answers, the admin page for the four flags and the cost limit,
  the push section for self-hosters (what leaves the server). Flags on by
  migration, one at a time, each after its gate.
- **Exit:** every journey of `02` walked on `web.synaplan.com`; this track
  moves to `2026-archive/`.
