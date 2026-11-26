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
  Chats, Sources, Instructions (the board replaces the tabs in PJ32);
  "Add source" dialog; project name in the composer of a project chat;
  move a chat into and out of a project from its menu.
- **Touches:** new `views/ProjectView.vue`, `components/projects/*`,
  `stores/projects.ts`, `services/api/projectsApi.ts`; locale files.
- **User-flow:** J-PJ-1, both themes, phone width.
- **Exit:** the five bullets; no component above 300 lines.

---

## Phase B — the chat works as long as it needs

Starts when AU26 and AH8 are on `main`. Order (owner, 2026-11-19, §0 #66,
#69): AU26 (row 22, S4) → AH8a → AH8b (row 25) → PJ5. Both briefs take
their limits as profile input.

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
  (characterization snapshots unchanged). Security: another account
  cannot read `run:{uuid}:events` or re-attach (403/404); a unit after the
  chat was moved out of its project runs without the project's sources; a
  unit for a deleted or disabled account ends `interrupted`; a write tool
  in a unit waits for approval and is never auto-approved; a payer whose
  monthly budget runs out between two calls stops before the next call
  (§0 #70); the checkpoint is gone after terminal + TTL and on account
  erasure. End of a run (§0 #68): a turn without `update_plan` ends on a
  plain answer; a run with open plan items is ended by the required
  `finish`.
- **Do:** run profile `chat` (no steps, no minutes); the prompt
  `tools:chat_loop`; the hand-over; skipping the search classifier, the
  auto-research gate and the task planner when the loop is on; the check
  before delivery; usage recorded per call.
- **Touches:** new `Service/Chat/Loop/*`; small edits where the turn is
  dispatched. `MessageProcessor` and `TurnDispatcher` do not grow.
- **Exit:** the tests; PJ1's simple group within G1 on the dev stack;
  `LOOP.ENABLED` stays 0 in every environment until PJ6 and PJ8 are merged
  (§0 #70).

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
- **Split (2026-11-25):** **PJ7a** builds `conversation_search`,
  `list_files` and the file-ID input for `read_document` and
  `analyze_image` (§0 #27 decided). **PJ7b** builds `RunNotes`,
  `TranscriptCompactor`, `notes.compacted`, writing `BRUNS.BNOTES` (the
  column exists since AH8a), the image generator's file-ID input and "Keep going"
  (`continueFrom`). The test for the edited picture of message 5 and the
  two compactions belong to PJ7b.
- **Second split (2026-11-25, owner):** "Keep going" (`continueFrom`) moves
  to PJ8, next to its button; §0 #13 stays open. The image generator's
  file-ID input becomes **PJ7c**: the chat loop has no image tool yet, so the
  edited picture of message 5 is tested there.

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

## Phase C — the project board

Does not wait for PJ11: the board is filled by an extraction after the
answer, not by loop tools (§0 #96). PJ29 needs PJ8 (the run card lives in
the chat area); PJ33's first plan needs PJ9. PJ21 and PJ22 moved here from
the old phase D (owner, 2026-11-26); PJ21 comes before PJ31, because the
extraction writes memory and items in one call (§0 #98). Order: PJ30 →
PJ21 → PJ31 → PJ29 → PJ32 → PJ33 → PJ22 → PJ34. PJ30, PJ21 and PJ31 can
start while B is still open. Before PJ32 the mockup gets a second project
of another kind (§0 #110).

### PJ29 — a chat area that a page can embed

- **Goal:** the project page can show a real chat next to the board
  without a second chat implementation. No visible change.
- **Reads:** `views/ChatView.vue` (4,462 lines), `components/ChatMessage.vue`,
  `stores/chats.ts`, the composer components, `docs/FRONTEND_CONVENTIONS.md`.
- **Tests first:** the existing chat E2E suite passes unchanged before and
  after; a Vitest mounts `ChatArea` with a chat ID and stubbed services.
- **Do:** extract `components/chat/ChatArea.vue` (message list, composer,
  stream handling) and the composables it needs; `ChatView.vue` keeps
  routing, sidebar and header and uses it. Each new file under 300 lines;
  `ChatView.vue` shrinks, its size-ledger entry drops.
- **Touches:** frontend only.
- **Exit:** `make test-e2e` green with no spec changed; the ledger entry
  for `ChatView.vue` smaller.

### PJ30 — project items (API)

- **Goal:** `03` §2.7–§2.9 and §13 without the AI: tasks, dates,
  decisions and costs through the API; "Waiting on others" as a filter of
  tasks (§0 #97).
- **Reads:** PJ3's `Service/Project/*`, `ProjectController`,
  `docs/MIGRATIONS.md`, `docs/API_PATTERNS.md`.
- **Tests first:** create, change, finish, drop each kind; a task under a
  date (`BPARENTID`) moves with it; `?waiting=1` lists open tasks with
  `BWAITINGSINCE`; a `private` item is invisible to every other caller; a
  `read` member cannot write; an item of another project is 404; a date
  without zone takes the project's zone; `MAX_ITEMS` refuses with 422;
  every change writes one log row; flag off → 404.
- **Do:** migration (`BPROJECTITEMS`, `BPROJECTITEMLOG`, the new
  `BPROJECTS` columns); entity, repository, `ProjectItemService`,
  `ProjectItemController`; OpenAPI and Zod; flag `PROJECTS.BOARD_ENABLED`.
- **Touches:** new `Service/Project/Item/*`; no edit to the chat path.
- **Exit:** the tests.

### PJ31 — the board fills itself from the chat

- **Goal:** J-PJ-13 and J-PJ-20: what a member says in a project chat
  becomes an item, with a line under the answer and "Undo", for every Chat
  model and with the loop on or off (§0 #96).
- **Reads:** `Service/Memory/MemoryExtractionService.php`,
  `ModelConfigService::getMemoryModelConfig()`, the `async_ai_low`
  handlers, `PromptCatalog`, PJ21, PJ30.
- **Tests first:** G14, G16 and G17 with the corpus in
  `backend/tests/Eval/ProjectBoard/` (40 messages: plain dates, relative
  dates, repetitions, "Ben does it", a cost, a decision, a fact that
  belongs in memory, chatter that must add nothing), run on the seeded
  memory model and on one Ollama model; a statement lands in memory or on
  the board, never in both (§0 #98); everything from an attached file or
  from the answer alone is `proposed` (G15); "Undo" restores all items of
  one answer; a turn with a file and a turn with the loop off still
  extract; nothing runs outside a project or with the flag off; G20 on the
  same corpus.
- **Do:** `ProjectTurnExtractionHandler` and its structured output (`03`
  §13); `tools:project_extract` and `tools:project_board`; the project part
  of memory extraction moves into the same call; `POST
  /projects/{uuid}/undo`; the `project.item` event with `messageId`;
  `project_plan` as the one loop tool.
- **Exit:** G14–G17, G20.

### PJ32 — project page with chat and board side by side

- **Goal:** J-PJ-12 (the page part) and J-PJ-18; the mockup
  `mockups/project-page.html` as product.
- **Reads:** the mockup and its second project (§0 #110), `02` §3.2, PJ4's
  `ProjectView.vue`, PJ29's `ChatArea`, `style.css` tokens, UX contract §4.
- **Tests first:** Vitest for each card's empty, filled and loading state;
  Playwright `@ci`: open a project, write a task in the chat, see it on
  the board, drag the divider, hide the board, reload and keep the layout;
  phone width lands in the chat with a "Board" tab.
- **Do:** chat left, board right, divider 25–70 %, "Hide board" and "Hide
  chat"; tabs Overview, Tasks, Dates, Files (sources inside); on Overview
  the cards To confirm, Tasks, Dates, Costs, Decisions, Waiting on others
  (and Watching once phase I is there), each with "All" that opens its
  list inside the board; "More" with Chats, What I remember, People and
  settings only (§0 #102); the quick-add line on Tasks and Dates (§0 #100);
  the main chat (§0 #93); a new project shows the chat only; `GET
  /projects/{uuid}/board` and the layout endpoint. Cards are fixed
  components, each under 300 lines.
- **Touches:** `views/ProjectView.vue`, new `components/projects/board/*`;
  the old tabs become the "More" menu.
- **User-flow:** J-PJ-12, J-PJ-18, both themes, phone width.
- **Exit:** the five bullets; `make -C frontend test-e2e-layout`.

### PJ33 — a written status and a first plan

- **Goal:** `03` §14; J-PJ-12 complete.
- **Reads:** PJ9 (`ask_user`), PJ31, `async_ai_low` handlers.
- **Tests first:** ten changes within 30 seconds make one status call; the
  morning refresh runs once per project with open items and never for an
  empty one; the computed line counts right; a status names no item that
  does not exist and no private item of another member; `[[since:…]]`
  renders "for four days" today and "for five days" tomorrow without a
  new call; a new project asks at most three questions and writes a plan;
  "You decide" still writes one; without the loop no question card appears
  and nothing fails.
- **Do:** the debounced status unit and the morning refresh on the memory
  model (§0 #95, #109); `project_plan`; the link and date markup and its
  rendering; the status on top of Overview with "As of today".
- **Exit:** the five bullets.

### PJ21 — project memory (API)

- **Goal:** `03` §7, memory. Moved into phase C (§0 #92): the board needs
  it from the first project on.
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
- **Do:** "What I remember" under "More" with delete; "Memory" in the
  composer menu of a project chat, default "Project memory only" (§0 #54);
  the lock when the chat is shared. No switch for personal memories under
  "People and settings"; one line there points to the composer (§0 #101).
- **Tests first:** Vitest for the three states of the choice; Playwright
  for the conflict journey with two accounts.
- **Exit:** the five bullets.

### PJ34 — search all chats of a project

- **Goal:** §0 #92: "Where did we talk about the tiles?" finds it.
- **Reads:** PJ7a's chat search, `AccessGate`.
- **Tests first:** a hit in a member's private chat is never shown to
  another member; a hit in a shared project chat is; the AI's
  `chat_search` inside a project searches the project.
- **Do:** `GET /projects/{uuid}/search`; the search field in the project
  header; the project scope for `chat_search`.
- **Exit:** the five bullets.

---

## Phase D — remind and write back, no new service

Reminders by e-mail, the calendar subscription and the mail preview. No
relay, no store release, no OAuth scope (§0 #103, #104, #107). Mail goes out
only after a click; "Send" only from a Microsoft 365 mailbox. WhatsApp is
Later.

### PJ35 — reminders on one-off times (API)

- **Goal:** `03` §2.10 and the sending half of §15.
- **Reads:** `Command/SavedTasksTickCommand.php`, `CronNextRun`,
  `InternalEmailService`.
- **Tests first:** a reminder is sent once even with two ticks in
  parallel; a changed date moves its unsent reminder and leaves a sent one;
  it goes out by mail (push is phase G); a private item reminds only its
  author; a member who left gets nothing; the person's zone decides
  "19:00".
- **Do:** `BPROJECTREMINDERS`, `ProjectRemindersTickCommand`, the
  scheduler entry, `ProjectReminderService`; flag
  `PROJECTS.REMINDERS_ENABLED`.
- **Exit:** the tests.

### PJ36 — reminders before dates and the weekly overview, by e-mail

- **Goal:** J-PJ-16.
- **Reads:** PJ35, PJ33's status, `02` copy "This week in {project}".
- **Tests first:** the Monday overview lists the next seven days, the
  overdue tasks and what waits for the person, and is skipped for an
  empty project; the three switches per person work; the mail renders in
  five locales.
- **Do:** the three kinds of `03` §15; the settings endpoint; the switches
  under "People and settings"; the mail template.
- **User-flow:** J-PJ-16 with a seeded clock.
- **Exit:** the five bullets.

### PJ37 — the project's dates as a calendar subscription

- **Goal:** J-PJ-19; `03` §16, calendar part (§0 #103).
- **Reads:** the `.ics` writer the destination providers use today,
  `CalendarDeliveryId`, PJ30.
- **Tests first:** G22; the feed is valid iCalendar (one `VEVENT` per
  `open` or `done` date, stable `UID`, `RRULE`, `TZID`) and imports into
  Google, Apple and Outlook test accounts; a moved date keeps its `UID`; a
  `proposed` or `dropped` date is absent; a wrong or renewed token answers
  404 with no hint which part was wrong.
- **Do:** `BPROJECTMEMBERFEEDS`, `ProjectCalendarFeed`, the three
  endpoints, "Add to my calendar" on the Dates tab (copy address, open as
  `webcal://`, renew); flag `PROJECTS.CALENDAR_FEED_ENABLED`.
- **User-flow:** J-PJ-19 on a phone.
- **Exit:** G22; the five bullets.

### PJ38 — mail preview to decline, move and follow up

- **Goal:** J-PJ-15 and the decline half of J-PJ-14; `03` §2.11 and §16.
- **Reads:** `Service/Microsoft/M365MailSender.php`, `GraphClient::sendMail`,
  `EmailMeRunner`, `02` §3.8a.
- **Tests first:** G18 — no path but the web "Send" sends (tool, run, watch
  and command tests); "Send" twice sends once; "Copy text" and "Open in
  mail app" are always there and complete on their own (a long body goes
  to the clipboard; "Did you send it?" does what "Send" does afterwards);
  "Send" only with Microsoft 365 (§0 #104); an empty "To" keeps "Send"
  off; after a decline the date becomes a task that waits.
- **Do:** `BOUTGOINGMAILS`, `ProjectMailService`, the endpoints, the
  preview dialog with variants; flag `PROJECTS.MAIL_ENABLED`.
- **User-flow:** J-PJ-15 without a mailbox (mail app) and against a Graph
  stub.
- **Exit:** G18; the five bullets.

PJ39 (spike: read a calendar for clashes) moved to master plan §9 with
writing into calendars (§0 #103).

---

## Phase E — shared chats

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
  queries on `BUSERID` still return the whole chat; an `edit` share on a
  chat inside a project is refused with 422 (§0 #71); a deleted member's
  messages keep their row with the text "Message deleted" (§0 #72).
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

- **Status:** proposed for Later (§0 #49); kept here so the brief is not lost.
- **Goal:** decide §0 #49 between the two ways of `01` §2.4.
- **Do:** against a test workspace: fetch with an ancestor check through
  the hosted MCP server (cost per fetch, failure cases, moved pages); a
  public integration with Notion's page picker (what the connection flow
  looks like for a person who already connected the hosted server).
- **Exit:** a recommendation in `STATUS.md`. No product code.

### PJ19 — narrow an app to a folder, a calendar or pages

- **Status:** the folder and calendar rows stay; the Notion row waits for
  PJ18 and may move to Later with it.
- **Goal:** `03` §6.4.
- **Depends:** PJ18's result for the Notion row.
- **Tests first:** a WebDAV grant with `folder` cannot read a sibling or a
  parent (path traversal cases); a Microsoft 365 grant with `mail: false`
  has no mail tool; a Notion grant refuses a page outside the list.
- **Do:** `BSCOPE` enforcement per app; the small pickers in the switch
  row.
- **Exit:** the five bullets.

---

## Phase F — shared projects

### PJ20 — share a project

- **Goal:** J-PJ-9, step 1; §0 #55.
- **Reads:** IAM kinds (`KnowledgeFolderKind` as the model), `AccessGate`,
  `SharedInbox`, PJ4, PJ13.
- **Tests first:** a member with `read` sees shared chats and sources and
  cannot write; `edit` can add a source and create a chat; a private chat
  of one member and its items are invisible to the others, including the
  owner (G21); removing
  a member removes access to folder and chats at once.
- **Do:** `ProjectKind`; derived access to the folder and the shared
  chats; "Share with the project" on a chat, which also makes its items
  `project`; "Show to everyone" on a private item (§0 #99, G21); members
  under "People and settings"; the project in "Shared with me".
- **Exit:** the five bullets.

### PJ47 — members on the board and "Since you were here"

- **Goal:** §0 #56 and #94 in a shared project.
- **Reads:** PJ20, PJ22, `BPROJECTITEMLOG`.
- **Tests first:** the list shows what others changed since the caller's
  last visit and nothing of their own; a private chat leaves no trace in
  it; tasks show their assignee's avatar.
- **Do:** `GET /projects/{uuid}/since`; the strip on top of Overview;
  avatars on tasks; the members under "People and settings".
- **Exit:** the five bullets.

---

## Phase G — push

Moved here from the old phase D (§0 #107): it needs a relay and a store
release, and nothing before it does.

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

- **Goal:** J-PJ-11 and the push half of J-PJ-16 and of PJ46; the
  reminders of PJ36 move to push where the person has a device.
- **Do:** the in-context sheet after the first promoted run; Settings →
  Notifications with three switches (run done, needs you, reminders), shown only inside the app; `seen`; the
  deep link from `ref` to the chat.
- **Tests first:** Vitest: the section is absent in a browser; the sheet
  appears once.
- **Touches:** keep the web part out of the paths the mobile policy marks
  `store-required`; the native bridge ships with PJ26.
- **Exit:** the five bullets; walked on both phones against the cloud and
  a self-hosted server.

---

## Phase H — things come into the project

### PJ40 — forward mails to a project

- **Goal:** the forwarding half of J-PJ-14; `03` §17.
- **Reads:** `Service/Email/SmartEmailHelper.php`, the inbound mail
  handler and the headers it keeps, `FileIngestExecutor`, PJ31.
- **Tests first:** a mail from a member's confirmed address with DMARC
  `pass` lands as files and proposals; a forged `From`, a DMARC `fail`, an
  unconfirmed address and a stranger are dropped, audited with the reason
  and not answered (§0 #105); a forwarded mail names the original sender as
  contact; a mail cannot add an `open` item (G15); the extraction counts to
  the forwarding member.
- **Do:** `BMAILKEY`, `BPROJECTSENDERS` and the confirmation mail, the
  keyword route, the DMARC check on `Authentication-Results`, the
  extraction of `03` §17 with mail input; "Forward mails…" under "People
  and settings" with the address and a copy button; flag
  `PROJECTS.INBOX_ENABLED`.
- **Exit:** G15; the five bullets.

### PJ41 — share to a project from the phone (`synaplan-apps`)

- **Goal:** a photo, a PDF or a link from the share sheet into a project.
  Own PR there; a store release.
- **Exit:** walked on both phones; the file appears under Files and its
  proposals under "To confirm".

### PJ42 — files of a project are read for items

- **Goal:** an offer as PDF proposes a cost and a date.
- **Reads:** PJ31's extraction, the file indexing events.
- **Tests first:** G15 for files; a file is read once after indexing; a re-upload of the
  same file proposes nothing new; proposals name the file as source.
- **Do:** the trigger after indexing; the source link on the card.
- **Exit:** the tests.

### PJ43 — "To confirm"

- **Goal:** J-PJ-14 complete.
- **Reads:** PJ38, PJ40, `02` copy for "To confirm".
- **Tests first:** Vitest for the card's states; Playwright: a forwarded
  mail shows two proposals, "Add" makes one open, "Doesn't work" opens the
  mail preview, "Ignore" drops the other.
- **Do:** the card on top of Overview when it has entries; accept and
  ignore endpoints; the source preview.
- **User-flow:** J-PJ-14.
- **Exit:** the five bullets.

---

## Phase I — watches

### PJ44 — watches for a version or a changed page (API)

- **Goal:** `03` §2.12 and §18, kinds `version` and `page` (§0 #106).
- **Reads:** `Entity/UrlWatch.php`, `Service/UrlWatch/UrlWatchService.php`,
  `TextDiff.php`, `Command/SavedTasksTickCommand.php`, `CronNextRun`.
- **Tests first:** G19 — both kinds against recorded feeds and pages; a
  report only when the value changes as the condition says; three failures
  pause the watch; a watch never posts a form or follows a sign-in; an
  unchanged value never calls a model; a source without a feed is not
  accepted as `version`; `number` and `entries` are refused with 422.
- **Do:** `BPROJECTWATCHES`, the task kind `project_watch` on the Saved
  Tasks tick (no own tick command), one checker per kind, the endpoints;
  "tell me when …" in a project chat becomes a proposed watch through the
  extraction of PJ31; flag `PROJECTS.WATCHES_ENABLED`.
- **Exit:** G19.

### PJ45 — "Watching for you" on the board

- **Goal:** J-PJ-17.
- **Reads:** `02` §3.8b, PJ32's cards.
- **Tests first:** Vitest for active, triggered, failed and paused;
  Playwright: "tell me when PHP 8.5.2 is out" creates a watch that shows
  on the card.
- **Do:** the card, the detail with the last values, pause and stop.
- **User-flow:** J-PJ-17.
- **Exit:** the five bullets.

### PJ46 — watches report in the project, by mail and push

- **Goal:** the report half of J-PJ-17.
- **Reads:** PJ35's channel choice, PJ27 once it is there.
- **Tests first:** a triggered watch sends one mail (or one push after
  phase G) and writes one line into the project chat; a paused watch sends
  nothing.
- **Do:** the report through the reminder channel; the line in the chat.
- **Exit:** the tests.

---

## Phase J — rollout

### PJ28 — docs and flags

- **Do:** `docs/FEATURES.md`, a user page each for projects and the board,
  reminders and mail, watches, shared chats and long answers, the admin page for the flags (`03` §11) and the cost limit,
  the push section for self-hosters (what leaves the server). Flags on by
  migration, one at a time, each after its gate.
- **Exit:** every journey of `02` walked on `web.synaplan.com`; this track
  moves to `2026-archive/`.
