# Research — what exists, what others do

For [`00_master_plan.md`](./00_master_plan.md). Code facts are from `main` @
`0d228e323` (2026-11-16); line counts from `wc -l`. External facts are from
public documentation read on 2026-11-12/13; re-check before a step relies on
one.

## 1. Code map

### 1.1 The web chat turn

| Piece | Path (`backend/src/`) | Fact |
| ----- | --------------------- | ---- |
| Entry | `Controller/StreamController.php` | `GET/POST /api/v1/messages/stream` |
| Orchestration | `Service/Chat/Stream/ChatStreamOrchestrator.php` | rate limit → `ChatRunService::begin()` → `StreamTurnPreparer` → `StreamModelRouting` → `MessageProcessor::processStream()` → persist |
| Decisions before the model | `Service/Message/MessageProcessor.php`, `TurnWebSearch`, `WebSearchTopicPolicy`, `AutoResearchGate`, `EffortVerdict` | search yes/no, effort, multi-step are classifier outputs |
| Multi-step | `TaskPlanExecutor` (969 lines), `TaskPlanner` (≤ 24 steps), `DagExecutor` | behind `MULTITASK.ROUTING_ENABLED` |
| Chat call | `ChatHandler` and its stages | no tools on the plain call |
| Tool loops | `Service/Document/ChatToolLoop.php` (226), `AI/Messages/Tools/GatewayToolLoop.php` (1,272), `AI/OpenAI/OpenAiGatewayToolLoop.php` (769) | each: 8 iterations (`MESSAGES_GATEWAY.MCP_MAX_ITERATIONS`, 1–32), 240 s, 16 tools (`ToolLimits`) |
| Tool path | `Service/Tool/Execution/ToolInvoker.php`, `ToolRegistry`, `ToolExecutionGate`, `Policy/ApprovalPolicy.php` | one gate, one audit event `tool.executed` |
| Existing tools | `AI/Messages/Tools/*Tool.php` | `web_search`, `web_fetch`, `knowledge_search`, `read_document`, `analyze_image`, `code_execution` |
| Planner capabilities as tools | `SkillToolSource` | `skill:{capability}`; writes: EmailMe, SaveToFolder, SaveToNotion, CalendarEvent, McpAction |
| Re-attach | `Service/Chat/Run/ChatRunService.php` | `STALE_AFTER_SECONDS = 300`; pump flags off |
| Not in the code | — | `AgentToolLoop`, `App\Service\Run`, `BRUNS` |

### 1.2 Long work today: research

`Service/Research/Run/`. Units `brief_plan`, `round`, `write`, `check`,
`deliver`; message `RunResearchUnit` on `async_ai_high`; lease 240 s; state
in Redis for 48 h; a reaper for stalls (600 s).

| Effort | Searches | Sources | Rounds | Seconds | Cost | Calls |
| ------ | -------- | ------- | ------ | ------- | ---- | ----- |
| quick | 2 | 4 | 0 | 60 | 0.10 | 6 |
| standard | 10 | 15 | 3 | 360 | 1.00 | 60 |
| thorough | 30 | 40 | 8 | 1,200 | 5.00 | 200 |

Hard clamps: 120 minutes, cost 50.0 (`ResearchBudgetConfig`). At the limit
the run writes the report from what it has (`StageEnds::beforeCall()`,
`COMPLETE_AT_LIMIT`). Roadmap row 21 measured: recall 0.96 against the
gateway path, backed statements 87.3 % against 64.0 %.

What this means for the plan: the wrap-up at a limit is proven here. The
fixed rounds and seconds are what the owner does not want in chat.

### 1.3 Context

| Piece | Fact |
| ----- | ---- |
| `TurnHistoryLoader` | last 15 messages or 15,000 characters |
| `ConversationSummaryService` (519) | rolling summary, 3 tiers, up to 4,000 characters, over at most 200 messages, recent 8,000 characters verbatim; refreshed after the reply on `async_ai_high`; model `ANALYZE`, else `CHAT` (the `SUMMARIZE` row is not read) |
| `BCHATSUMMARIES` | one watermark per chat (`BUPTOMESSAGEID`) |
| `ConversationFileCatalog` (669) | 8 files per category, last 30 messages, 8 inventory entries; `latestImage`, `findByReference` |
| `GeneratedImageVisionFlag` | one generated image re-sent to a vision model |
| `Model::getContextWindowTokens()` | from `BJSON.meta.context_window` |
| `Model::hasFeature('tool_use')` | from `BJSON.features` |

Gap: a run has no compaction of its own, because no chat run has more than
8 iterations. Nothing in the UI shows that a summary replaced messages.

### 1.4 Questions

`AskUserRunner` (82): one question, ≤ 5 options, `recommended`,
`allowText`, 24 h. `POST /api/v1/messages/{id}/ask-user` (`nodeId`,
`answer`, `skip`). Rendered in `TaskCard.vue` lines 427–478. Only inside a
multi-step plan. Research has `ask_user` in `LoopAction` with a question
budget of 0 for automatic runs and no card.

### 1.5 Model choices a person sees

`PlannerModelCard.vue` (213) on `/ai/models`: task planning (`PLAN`, falls
back to `SORT`), reading sources (`READ` → `SORT` → `ANALYZE` → `CHAT`),
fact-checking (`VERIFY` → `ANALYZE` → `CHAT`). `READ` and `VERIFY` are not
in the production seed. Admin → Research already has both.

With the loop on, the web chat calls none of the three. The card would set
models nothing uses there. Desktop, the API and Saved Tasks still use them,
and the admin page covers that.

### 1.6 Sharing

| Piece | Fact |
| ----- | ---- |
| `ShareService` (853, in the size ledger), `Share` (`BSHARES`) | subjects user, group, everyone; permissions read < use < edit < manage |
| Kinds | `conversation` (read, use), `knowledge_folder` (read, use, edit, manage), `assistant`, `agent`, `prompt`, `saved_task`, `widget`, `tool`, plugin kinds |
| "Use" on a conversation | continue as a copy: `POST /api/v1/chats/{id}/continue` |
| Posting | `StreamTurnPreparer::loadChat()` line 132: caller must equal `BCHATS.BUSERID` |
| Author | none; `BMESSAGES.BUSERID` is the account; `BMESSAGEMETA` holds key/value per message |
| Realtime | `user:{id}`; `ChatAudienceNotifier` → owner and shares, no text |
| UI | `ShareDialog.vue` (329), consequence copy `iam.dialog.consequence.{kind}.{permission}`, `SharedConversationBanner.vue`, `/chats/incoming` |

### 1.7 Cost

`RateLimitService` (866): `checkCostBudget()` sums `BUSELOG.BCOST` with the
markup against the monthly budget plus top-ups; called before the turn,
only with `COST_BUDGET_GATE_ENABLED`; nothing mid-turn. `recordUsage($user,
…)` always charges the given user. The widget is the one case where the
owner pays for someone else's message.

### 1.8 Memories

`BUSERMEMORIES`, Qdrant `user_memories` (`BNAMESPACE` → own collection).
Read: `ChatMemoryContextLoader` (5 per turn, score ≥ 0.45). Write:
`PendingMemoryExtraction` → `MemoryExtractionService` (user turns, last 5;
model `MEM`, else `CHAT`). Off per request (`disableMemories=1`) and per
account. Digests (`app:digest:run`) and the "most recently updated other
chat" tail read across a user's chats.

### 1.9 Knowledge

Folder = `BFILES.BGROUPKEY`, resource ID `{ownerId}:{groupKey}`.
`RagScopeResolver` (373) widens the search to shared folders, agents and
conversations. `ragGroupKey` pins a folder for a turn. `UrlContentService`
(1,257) turns a page into text. `FileIngestExecutor` turns text into a
vectorised file. Images are described on upload
(`media_pending_describe`). No endpoint adds a link or text to a folder.

### 1.10 Connected apps

| App | Read | Write | Narrowing that exists |
| --- | ---- | ----- | --------------------- |
| Dropbox | no | `save_to_folder` | `config.folder` (save target) |
| WebDAV / Nextcloud | `folder_fetch` | `save_to_folder` | everything below `config.folder` |
| CalDAV | — | `calendar_event` | the connection is one calendar |
| Microsoft 365 | `email_search` | `email_me`, `calendar_event` | `channel`, `channel_calendar` |
| IMAP | search INBOX | — | — |
| Notion (hosted MCP) | search, fetch | behind `NotionWriteGuard` | none; the account's whole workspace |
| Other MCP servers | per tool | `BALLOWWRITE`, `BWRITEAUTO` | per server |

Per turn the model learns about connected systems through
`ConnectedSystemsHint` and `ConnectedSystemMention` (name match with word
boundary). No switch per chat.

### 1.11 Frontend

`views/ChatView.vue` 4,466 lines, `ChatMessage.vue` 2,370, `ChatInput.vue`
2,535, `stores/chats.ts` 1,511, `services/api/chatApi.ts` 1,003. Parts that
get reused: `ProcessingTimeline.vue`, `TaskCard.vue`, `ApprovalCard.vue`,
`ResearchTurn.vue`, `ToolsDropdown.vue`, `KnowledgeFolderPicker.vue`,
`UsageBudgetCard.vue` (the bar), `ShareDialog.vue`. Missing: an avatar
component, anything named project. German copy says "du" (107 strings in
`de/chat.json` against 22 "Sie"; the question card's read-only line is one
of the 22).

### 1.12 Phone

`synaplan-apps`: Capacitor 8, app IDs `com.synaplan.app`, `.staging`,
`.dev`; no push plugin, no `aps-environment`, no `POST_NOTIFICATIONS`, no
Firebase file. A new native plugin is a store release and an ask-first item
there. The server picker stores any origin (`docs/SERVER_CONFIG.md`).
Backend: `Service/PlatformLink/NotificationToken.php` signs Ed25519 JWTs
(300 s), `NotifyKeyStore` keeps and rotates the key,
`/.well-known/jwks.json` publishes it when Platform Links are on. The only
device table is `BDESKTOPDEVICES`.

### 1.13 Board, reminders, mail and watches

- **Calendars write only.** `Service/Destination/OutlookCalendarDestinationProvider.php`
  and `CalDavDestinationProvider.php` create events (`GraphClient::createEvent`);
  nothing reads a calendar today. Update and delete are added in PJ37,
  reading is PJ39.
- **Mail from the person's own mailbox** exists only for Microsoft 365:
  `Service/Microsoft/M365MailSender.php` over `GraphClient::sendMail`, used
  by `Service/Multitask/Execution/Runner/EmailMeRunner.php`. Gmail and
  generic SMTP/IMAP accounts are not connected; they would be a new
  provider (ask first).
- **System mail:** `Service/Email/InternalEmailService.php` (the server's
  SMTP) — fit for reminders to the person, not for mail to third persons.
- **Inbound mail:** `smart+keyword@` through
  `Service/Email/SmartEmailHelper.php`; the keyword route is where the
  project address hooks in. `GraphClient::listMessages`, `searchMessages`
  and `messageBody` read a connected Microsoft 365 mailbox.
- **Watching pages:** `Entity/UrlWatch.php`, `Service/UrlWatch/UrlWatchService.php`
  and `TextDiff.php` diff a page on a schedule — the `page` kind of a watch
  as it is.
- **Schedules:** `Service/SavedTask/Schedule/CronNextRun.php` and
  `Command/SavedTasksTickCommand.php` are the pattern for the reminder and
  watch ticks.
- **Chat UI size:** `views/ChatView.vue` 4,462 lines,
  `components/ChatMessage.vue` 2,373 lines. Embedding the chat in the
  project page needs a chat area extracted first (PJ29).
- **Project UI on `main`:** `components/projects/` (`AddSourceDialog`,
  `ProjectChatsTab`, `ProjectSourcesTab`, `ProjectsSidebarSection`, …) from
  PJ4; the board replaces the tabs.
- **Which turns reach the loop** (checked 2026-11-26 for the review,
  `Service/Chat/Loop/ChatLoopGate.php`): only a plain turn of a signed-in
  person in their own chat whose Chat model calls tools while streaming.
  Incognito, guests, the widget, agents, Saved Tasks, "Again", "Continue",
  turns with files and document replies keep today's path; Gemini has no
  native tool routing in a run (`STATUS.md`, PJ7a walk). Board tools inside
  the loop would therefore miss every dropped file and every person on such
  a model; hence extraction after the answer (§0 #96).
- **Extraction after a turn exists:** `Service/Memory/MemoryExtractionService.php`
  runs after the answer on the person's memory model
  (`ModelConfigService::getMemoryModelConfig()`); the board extraction
  joins that call (§0 #98, #109).

## 2. What others do

### 2.1 Projects

- **Claude Projects:** instructions, a knowledge base that switches to
  retrieval when it grows, chats private unless shared, project sharing in
  team plans.
- **ChatGPT Projects:** files, instructions, a project-only memory option,
  shared projects where members see the project's chats.

Taken: sources indexed once; instructions; a memory choice per chat.
Not taken: templates; chats visible to all members by default (§0 #55).

### 2.2 Long self-directed work

- **Cursor, Claude Code:** one loop, the model picks tools until it is
  done; a visible to-do list; automatic summary when the context fills,
  shown as one line; a question tool with options.
- **ChatGPT / Claude research modes:** a plan, searches, a report with
  sources; fixed product-side budgets that the person does not see.

Taken: one loop, a visible plan, the summary line, the question card.
Different: the only budgets are cost and repetition, and the limit is
visible when it ends a run.

### 2.3 Group chats

**ChatGPT group chats:** the model decides when to answer, a mention forces
an answer; the rate limit counts for the person it answers; personal memory
is neither used nor created.

Taken: sender pays; no personal memory. Different: the AI answers every
message unless it is addressed to a member (§0 #36) — predictable beats
clever when two people plan a kitchen.

### 2.4 Scoping an app

- **Notion hosted MCP:** OAuth with the user's full rights; one scope; no
  page picker. A public integration with its own OAuth has a page picker,
  and a chosen page includes its children.
- **Dropbox:** app-folder apps see one folder. Synaplan's Dropbox app only
  writes.
- **Microsoft Graph, CalDAV:** scopes per product (mail, calendar), one
  URL per calendar.

For Notion two ways are open and PJ18 measures both: (a) keep the hosted
MCP connection and allow `fetch` only for page IDs the member picked,
checking the ancestor chain of every fetched page; (b) a second connection
type through a public integration, where Notion's own picker limits the
token. (a) needs no new connection and no second login; its limit is in
our code. (b) is enforced by Notion; it is a second way to connect the
same product.

### 2.5 Push for self-hosted servers and one store app

Apple and Google deliver a push only for the app whose keys sign it. A
self-hoster cannot hold the keys of the official app.

| Product | How |
| ------- | --- |
| Nextcloud | Push proxy run by Nextcloud; the server signs, the proxy forwards; end-to-end encrypted payload |
| Rocket.Chat | Gateway run by the vendor; quota per workspace |
| Mattermost | Hosted push service; an "ID only" mode sends no content and the app fetches it |
| Matrix | Sygnal gateway per app; the homeserver posts to it |
| Mastodon apps | Relay per app |
| UnifiedPush / ntfy | A second app on the phone delivers; Android only in practice |

Rejected: self-hosters bring their own keys (needs their own app build);
UnifiedPush (no iOS); background polling (iOS does not allow it reliably);
web push inside the app (not available in the iOS web view).

Chosen: a relay with ID-only pushes (§0 #59, #60). The contract is in
[`03_contracts.md`](./03_contracts.md) §8.

### 2.6 Project state, reminders and acting on a yes (2026-11-26)

- **No assistant keeps a project's state.** ChatGPT, Claude, Gemini and
  Perplexity projects hold files, instructions and chats; none has tasks,
  dates, decisions or costs that the chat keeps up to date. The person
  asks "where are we?" and the model reads the chats again.
- **Group chats are hard to sell.** OpenAI retired group chats in ChatGPT
  in July 2026
  (<https://help.openai.com/en/articles/12703475-retiring-group-chats-in-chatgpt>).
  A shared project with a board gives the group a reason to come back;
  a shared chat alone did not.
- **Scheduled tasks without the project.** ChatGPT's scheduled tasks
  cannot read project files
  (<https://help.openai.com/en/articles/10291617-scheduled-tasks-in-chatgpt>);
  a reminder there does not know the project.
- **Buying for the person is not ready.** OpenAI scaled back Instant
  Checkout in March 2026
  (<https://techcrunch.com/2026/03/24/openais-plans-to-make-chatgpt-more-like-amazon-arent-going-so-well>).
  Watches report; they never buy (§0 #87).
- **WhatsApp later.** Meta's rules for general AI assistants on the
  business API, and the EU's interim measures against them
  (<https://www.houthoff.com/insights/news/a-rare-show-offorce-commission-deploys-interim-measures-against-meta-to-safeguard-ai-competition/>),
  make the channel uncertain; no bot can join an existing group; messages
  outside 24 hours are paid templates. Push and mail first (§0 #84).
- **The renovation case is real.** Houzz 2026: 37 % of renovations go over
  budget
  (<https://www.houzz.com/magazine/2026-u-s-houzz-and-home-study-renovation-trends-stsetivw-vs~185090855>)
  — the Costs card with a budget answers a known pain.
- **Dates are where assistants fail.** Reminder assistants (Poke, Gemini)
  get time zones and repetitions wrong in public reports. Hence G16: the
  zone and the repetition are said back in the "Added" line.
- **What looks machine-made.** Lists of AI-default UI patterns (gradient
  heroes, emoji headings, uniform rounded cards, filler copy):
  <https://world.hey.com/kostac/spot-the-slop-a-ui-designer-s-guide-to-fixing-ai-defaults-4c448c9c>,
  <https://www.thefountaininstitute.com/blog/signs-vibe-coded-ui>,
  <https://github.com/Laith0003/ux-skill/wiki/Anti-AI-slop-ban-list>. The
  board uses the house tokens, plain words and few cards; the banned-words
  list in `02` grew accordingly.
- **Calendars subscribe to a feed.** Google Calendar, Apple Calendar and
  Outlook all subscribe to an `.ics` address and refresh it on their own
  (Google in hours, the others on a set interval). That covers "the
  project's dates in my calendar" without OAuth or write access (§0 #103).
- **Shops and classified sites resist watching.** Price pages are
  script-rendered and behind bot protection; classified sites forbid
  automated reads in their terms. Price watchers that work rely on feeds,
  partner APIs or their own crawling contracts (§0 #106).
- **Cards over a dashboard.** In the mockup sessions the owner preferred a
  board of plain cards next to the chat over a dashboard in the middle,
  with the chat smaller on the left (`mockups/project-page.html`).

### 2.7 Hands-on: the same project in ChatGPT and Claude (2026-11-26)

One kitchen project, run the same way in both: an offer and the
measurements as sources, an instruction ("prices always include
delivery"), a status question, news from a phone call, a new chat asking
for the measuring date, an oven search, a research question on water and
power lines in a rented flat, and "remind me on 2 December".

- **ChatGPT: state in every answer, none in the project.** Status comes as
  cards and checklists inside each answer. Every answer builds a new
  checklist (31, then 39 items); ticks persist, but the old lists go
  stale. A new chat knew the phone call only after about ten minutes,
  through a project chat index. The model kept "recommended" and
  "decided" apart and said honestly what it had not saved. It ignored the
  delivery instruction.
- **Claude: one status document, kept by the model.** After the first
  answer Claude asked whether to create a status document. On "yes" it
  wrote one Markdown file into the project's context (tasks, dates,
  decisions, a cost table) and updated the same file on the phone-call
  news. A new chat read it at once, and the tool step was shown ("Read
  … from the project"). It followed the delivery instruction and noticed a
  wrong family name in the offer. This corrects §2.6: a project's state
  can be kept, by the model, as a document; it is still no board with
  items, owners or undo.
- **Generated files leave the project in both.** ChatGPT's spreadsheet,
  page and research report went to a global space; Claude's sheet and
  research report went to a global gallery and the chat. Neither research
  result updated the project's state.
- **Reminders.** ChatGPT created a task at 08:00 on one click of a
  suggestion, without asking, listed globally and bound to the chat.
  Claude turned the sentence into a one-time task at 18:00 CET, bound to
  the project and shown on the project page, and ran it without asking.
  Claude's scheduled tasks can "notify only when there is something worth
  telling", the shape a watch needs.
- **Sharing.** ChatGPT shares a project by invite or link; Claude only in
  team plans. Neither has a group chat in a project.

Taken: update in place (§0 #112), confirm before write (#113), receipt
only (#114), state read at turn start (#115), files stay in the project
(#116), a reminder said back before it is added (#117), quiet watches
(#118), one person first (#119).

## 3. Open questions the spikes answer

| Spike | Question | Decides |
| ----- | -------- | ------- |
| PJ2 | Is "first call in the request, the rest as units" within 10 % of today for simple turns? What does the hand-over cost in time to the next token? Does a two-hour run with three compactions keep its goal? | §0 #21, G1, G7 |
| PJ18 | Can a hosted-MCP `fetch` be limited to chosen pages and their children reliably, or is a public integration needed? | §0 #49 |
| PJ39 | Can a calendar be read for one hour of free/busy with the scopes a person already granted, or does it need a new consent? | §0 #88; moved to master plan §9 with §0 #103 |
| PJ23 | Does the relay contract hold on real devices with a server behind NAT? What do Apple and Google require for the generic text? | §0 #59–#62, G13 |
