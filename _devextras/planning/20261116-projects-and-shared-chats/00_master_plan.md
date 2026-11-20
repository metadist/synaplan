# Projects, shared chats and a chat that works as long as it needs

**Status:** 2026-11-19. Phase 0 and phase A are on `main` (PJ0–PJ4,
[`STATUS.md`](./STATUS.md)). Phase B is blocked: AU26 and AH8 are not on
`main`. §0 records what the owner said; rows marked ☐ wait for the owner.
**Owner:** product owner.
**Roadmap:** row 46. Steps `PJ0`–`PJ28`, journeys `J-PJ-1`–`J-PJ-11`.
**Binding:** [`docs/UX_CONTRACT.md`](../../../docs/UX_CONTRACT.md) (U1–U12),
root [`AGENTS.md`](../../../AGENTS.md), and the plans this one builds on:
row 22 [`20261021-architecture-update`](../20261021-architecture-update/00_master_plan.md)
(AU26, one tool loop) and row 25
[`20261023-agent-handling`](../20261023-agent-handling/00_master_plan.md)
(AH8, the run kernel).
**Code map taken on:** `main` @ `0d228e323` (2026-11-16).

## Goal

1. **Projects.** A person creates a project ("New kitchen"), puts in what
   belongs to it — PDFs, links, texts, screenshots — and chats inside it. The
   sources are indexed once when they are added. Every chat in the project
   can find them without reading them again.
2. **A chat that decides for itself how much work a question needs.** Two
   minutes or two hours. It decides when to search the web, when to read the
   files, how often, and when it is done. There is no step limit, no round
   limit and no time limit. Two things end the work early: a cost limit and
   going in circles. Both end with a result, never with an error.
3. **Writing together.** A chat, and a project, can be shared so that several
   people write in the same conversation. Everyone sees who wrote what and
   who the answer is for. Each person pays for their own messages unless the
   owner sets an amount for the chat.
4. **Nothing leaks between people.** In a shared chat, personal memories are
   off. Connected apps are off until their owner switches one on, and then
   only that person's own messages can use it.
5. **The phone says when it is done.** A push when a long answer is ready or
   when the chat needs the person. It also works when the app is pointed at a
   self-hosted server.

## Phases

| Phase | What ships | Needs |
| ----- | ---------- | ----- |
| 0 — Measure | This plan, a test corpus with today's numbers, one runtime spike | — |
| A — Projects for one person | Project, sources, instructions, chats in it | — |
| B — The chat works as long as it needs | Chat turn on the one loop as a run, cost limit with a result, circle detection, notes, question card, run card | AU26, AH8 |
| C — Shared chats | Write together, names on messages, who pays, apps per person | — (app switches use B when it is on) |
| D — Shared projects and project memory | Share a project, memory that belongs to the project and names its author | A, C |
| E — Push | Phone notification, relay for self-hosted servers | B; store release of the app |
| F — Rollout | Flags on, user docs | all |

A and C do not wait for B. B waits for two steps of other rows (§4).

## Start here

1. §0 — every decision, what the owner already said, what is still open.
2. §2 — the target shape in one page.
3. [`02_journeys_and_ui.md`](./02_journeys_and_ui.md) — what people see.
4. [`03_contracts.md`](./03_contracts.md) — tables, events, endpoints, flags.
5. [`04_sprints.md`](./04_sprints.md) — one brief per step.
6. [`01_research.md`](./01_research.md) — what was read and measured.
7. [`STATUS.md`](./STATUS.md) — step log.

## Rules for the AI assistant

- One step is one branch and one pull request (root `AGENTS.md`, Loop).
- A row marked **Ask** in §0 is a stop. Do not write the migration, add the
  dependency or the service before the owner ticks it.
- Do not build a second loop, a second run table or a second share model.
  §4 names what exists. Extend it.
- A limit that counts steps, rounds or minutes is not allowed in the `chat`
  run profile. If a test needs one, the test is wrong.
- Every flag in this plan defaults to off. A default flips with a migration
  `UPDATE`, in its own step, after the gate in §6.
- Copy comes from `02` §4, in all five locales, in the PR that paints it.
- A fact about the code in this plan has a path. If the path moved, fix the
  plan in the same PR.

---

## 0. Decisions

"Yes" rows quote the owner (2026-11-12 / 2026-11-13). ☐ rows are this plan's
proposal and wait for a tick. **Ask** rows are ask-first by `AGENTS.md`.

### Projects

| # | Decision | Default | Agree? |
| - | -------- | ------- | ------ |
| 1 | Projects exist: a name, sources, instructions, chats, later members. | As written | Yes — owner |
| 2 | No project templates. | None | Yes — owner |
| 3 | Sources: files, images and screenshots, links, pasted text. Indexed once on insert; a chat searches them, it does not re-read them. | As written | Yes — owner |
| 4 | A project's sources are a knowledge folder (`BFILES.BGROUPKEY`, `BRAG`). No second store, no second search. | Reuse | Yes — owner, 2026-11-17 |
| 5 | A link is a snapshot of the page at the time it was added. "Refresh" fetches it again. No automatic watching in v1. | Snapshot | Yes — owner, 2026-11-17 |
| 6 | Where projects live: a "Projects" section in the Chats panel and a project page. No new rail item (U2). | Chats panel | Yes — owner, 2026-11-17 |
| 7 | A chat can be moved into a project and out of it. Moving out removes access to the project's sources and memory for later turns. | Movable | Yes — owner, 2026-11-17 |

### The chat that works as long as it needs

| # | Decision | Default | Agree? |
| - | -------- | ------- | ------ |
| 8 | The model decides: when to search, what to read, how often, how long, when it is done. | As written | Yes — owner |
| 9 | No fixed time, no maximum number of steps or rounds. | None | Yes — owner |
| 10 | Two guard rails: a cost limit per run and going-in-circles detection. Stop is always there. | As written | Yes — owner |
| 11 | At the cost limit the run does not abort. It writes the result from what it has and says what is still open. | Wrap-up | Yes — owner |
| 12 | Value of the cost limit: the person chooses it, as a share of their own monthly budget (5, 10, 20, 25, 50 or 100 %, default 10 %), in Settings → Chat. Never above what the payer has left this month. A plan without a monthly budget has no cost limit per run. | Person chooses a share, default 10 % | Yes — owner, 2026-11-20 |
| 13 | After a wrap-up the person can press "Keep going". That is a new run with a new limit that starts from the notes of the old one. | Offered | ☐ |
| 14 | Models without a price (local Ollama) never reach a cost limit. There is no token limit for them; circle detection and Stop end a stuck run. | No token cap | Yes — owner, 2026-11-20 |
| 15 | Circle detection is deterministic and counts repetition, not work: the same call with the same input three times, six steps in a row that bring nothing new, five tool errors in a row. First a nudge, then the wrap-up. | 3 / 6 / 5 | Yes — owner, 2026-11-20 |
| 16 | Scope: web chat and projects. Not the widget. | As written | Yes — owner |
| 17 | Also not in this track: WhatsApp, Telegram, e-mail, the two gateway APIs, Saved Tasks, Desktop. They keep today's paths. | Later (§9) | ☐ |
| 18 | The Chat model runs every step. No list of "verified" models. A weak model gives a weak result. | Chat model | Yes — owner |
| 19 | A Chat model without tool calling (`tool_use` feature) answers as today, in one call. The model page says so next to that model. | As today | ☐ |
| 20 | Settings → AI Models loses the card "Task planning / Reading sources / Fact-checking" (`PlannerModelCard.vue`). Admin → Research keeps its optional reading and checking models. | Remove | ☐ |
| 21 | A simple question stays as fast and as cheap as today: the first model call runs in the request. Only when the model asks for a tool does the turn become a run in the worker. | Inline first | Yes — owner, 2026-11-19 (PJ2 measured it) |
| 22 | Built on AU26 (one loop) and AH8 (run kernel, `BRUNS`). Ordering row 46 phase B orders those two steps first. | Reuse | Yes — owner, 2026-11-19; order AU26 → AH8a → AH8b → PJ5 (#69) |
| 23 | For web chat this plan replaces three rows of row 25 §0: #12 (no `MAX_STEPS`, no `MAX_MINUTES` for the `chat` profile), #8 (no "Let it work" button needed in chat; every message can become long work), #6 (no model conformance list). Agents keep row 25's rules. | Replace for chat | Yes — owner, 2026-11-19 |
| 24 | The router keeps what is not a judgement about effort: language, topic, and the direct paths for "make an image / a video / audio". Web search, depth and multi-step are the loop's. | Keep fast paths | Yes — owner, 2026-11-19 |
| 25 | The research engine and the task planner stay for Desktop, the API and Saved Tasks. In web chat they are not called when the loop is on. The citation check of the engine runs on a loop answer that cites web sources. | Keep, reuse check | Yes — owner, 2026-11-19 |

### Notes and long chats

| # | Decision | Default | Agree? |
| - | -------- | ------- | ------ |
| 26 | It works after 100 messages. Inside a run the transcript is compacted into notes before the model's window is full. The chat shows a short line: "Conversation summarized". | As written | Yes — owner |
| 27 | Earlier messages stay findable word for word: the loop has a tool that searches the whole chat. The summary is the map, not the only copy. | Tool | ☐ |
| 28 | Files and pictures of the chat stay usable by ID for the whole chat. "Change the picture from earlier" works after any number of messages. | As written | Yes — owner |

### Questions to the person

| # | Decision | Default | Agree? |
| - | -------- | ------- | ------ |
| 29 | The AI asks when it needs something: a card with options A, B, C … and a free-text field, several questions after each other. | As written | Yes — owner |
| 30 | Up to four questions per card, up to five options each, one may be marked "recommended", "You decide" skips. It asks early, not after ten minutes of work. | As written | Yes — owner |
| 31 | No answer after 24 hours: the run goes on as if "You decide" was pressed and says so. | 24 h | ☐ |
| 32 | In a shared chat only the person whose message started the run can answer. Others see the card read-only. | Starter only | Yes — owner |

### Shared chats

| # | Decision | Default | Agree? |
| - | -------- | ------- | ------ |
| 33 | A chat can be shared so that others write in it — in plain chats and in projects. | As written | Yes — owner |
| 34 | It is the existing share (`BSHARES`, kind `conversation`) with a third level, "Can write" (`edit`). People and groups, not "everyone". "Can read" and "Can continue as a copy" stay. | Extend | ☐ |
| 35 | Who wrote what: every message stores its author. Other people's messages show an avatar with initials and the name. The answer shows "Reply to {name}". Avatar colours come from a fixed set of tokens that meet AA in both themes. | Avatar + name | ☐ |
| 36 | The AI answers every message. A message that starts with `@Name` of a member is for that person, and the AI stays quiet. | Always, except @member | ☐ |
| 37 | One run per chat. A new message from the same person steers the run. A message from someone else appears at once; its answer is queued and starts when the current run ends. | Queue | ☐ |
| 38 | Each person pays for their own messages. | Sender pays | Yes — owner |
| 39 | The owner can pay instead: one amount for the chat. Only the others' usage counts against it, not the owner's. When it is used up, everyone pays for themselves again, automatically. The chat shows which mode is on and a bar. | As written | Yes — owner |
| 40 | Members see the bar and a percentage. The amount is shown to the owner only. | Percent only | ☐ |
| 41 | Personal memories are neither used nor created in a shared chat. A shared plain chat has no memory at all. | Off | Yes — owner |
| 42 | Sharing a chat that already has messages shows a warning first: the others will see everything written so far. | Warn | Yes — owner |

### Apps in shared chats

| # | Decision | Default | Agree? |
| - | -------- | ------- | ------ |
| 43 | Every connected app is off in a shared chat. A member switches each of their own apps on, per chat. | Off | Yes — owner |
| 44 | Only the app's owner can make the AI use it. "Tell me what is in Ben's Notion" does not work, whoever asks. Enforced in code: the tools of a turn are built from the sender's switches only. | As written | Yes — owner |
| 45 | Against instructions another member planted earlier: before an app is used in a shared chat, a check that sees only the sender's own message confirms that the sender asked for it. The app named in the message passes without a model call. Otherwise one small call decides, and "not sure" means no. | Sender-only check | ☐ |
| 46 | A write through an app in a shared chat always asks the app's owner, even when "always allow" is on for that app. | Always ask | ☐ |
| 47 | An answer that used an app says so: "Contains data from {name}'s {app}". | Chip | Yes — owner |
| 48 | Narrower than on/off, second step: a sub-folder for WebDAV / Nextcloud, mail or calendar for Microsoft 365. A CalDAV connection is already one calendar. Dropbox cannot be read today (save only), so there is nothing to narrow. | v2 | ☐ |
| 49 | Notion: "Only these pages" — the member searches their Notion in the switch and picks pages; the chat can open those pages and their sub-pages and nothing else. Not "Followed pages". Needs the spike PJ18 first; until then Notion is on or off. | Page picker after spike | ☐ |

### Project memory

| # | Decision | Default | Agree? |
| - | -------- | ------- | ------ |
| 50 | A project has its own memory. Every entry names the member it belongs to. | As written | Yes — owner |
| 51 | Entries come only from what a member wrote in the project. Personal memories are never copied in. | As written | Yes — owner |
| 52 | Conflicts are pointed out by the model ("…, but Cristian does not like green"). No rule list. A test corpus proves it (§6). | Model + corpus | Yes — owner |
| 53 | In a chat that is not shared, inside a project, the person chooses: "Project memory only" or "Project memory and my memories". | Choice | Yes — owner |
| 54 | The default of that choice. | Project memory only | ☐ |
| 55 | In a shared project a chat is private until its author shares it with the project. Private chats read the project memory and do not add to it. | Private first | ☐ |
| 56 | Everyone in the project sees the memory list. A member deletes their own entries; the owner any. | As written | ☐ |

### Push

| # | Decision | Default | Agree? |
| - | -------- | ------- | ------ |
| 57 | Push on the phone when a long run is done and when it needs the person. Nothing in the browser. | As written | Yes — owner |
| 58 | "Long" means the run left the request (it used a tool) and the person is not looking at that chat. | As written | ☐ |
| 59 | Self-hosted servers reach the official app through a relay that Synaplan runs. The server signs a request, the relay holds the Apple and Google keys. | Relay | ☐ |
| 60 | A push carries no content: a kind ("done" / "needs you") and an opaque reference. The text on the lock screen is a fixed sentence. The app loads the rest from its own server after it is opened. | No content | ☐ |
| 61 | **Ask — dependency.** `@capacitor/push-notifications` in `synaplan-apps`, with the Apple push capability and Firebase for Android. A store release. | — | ☐ Ask |
| 62 | **Ask — new service.** The relay, in `synaplan-platform`. | — | ☐ Ask |

### Cross-cutting

| # | Decision | Default | Agree? |
| - | -------- | ------- | ------ |
| 63 | **Ask — schema.** New tables `BPROJECTS`, `BPROJECTMEMORIES`, `BCHATALLOWANCES`, `BCHATAPPGRANTS`, `BPUSHDEVICES`; new columns `BCHATS.BPROJECTID`, `BCHATS.BPROJECTSHARED`, `BCHATS.BMEMORYMODE`, `BMESSAGES.BAUTHORID`, `BRUNS.BNOTES`; `BRUNS.BKIND` gets the value `chat`; two switches in `BUSERDETAILS` (`03` §2). | — | `BPROJECTS`, `BCHATS.BPROJECTID/BPROJECTSHARED/BMEMORYMODE`: Yes — owner (PJ3); `BPROJECTS.BPINNEDAT` shipped with PJ4; `BRUNS.BKIND = chat` and `BRUNS.BNOTES`: Yes — owner, 2026-11-19, in AH8a's `BRUNS` migration; the rest ☐ Ask |
| 64 | Four flags, all off: `LOOP.ENABLED`, `SHARED_CHATS.ENABLED`, `PROJECTS.ENABLED`, `PUSH.ENABLED`. Projects and shared chats are feature modules. | Off | ☐ |
| 65 | `LOOP.ENABLED` turns on for everyone only after the gate in §6 is green on the corpus of PJ1. | Gate first | ☐ |
| 66 | Order: 0, then A and C in either order, B as soon as AU26 and AH8 are on `main`, then D, E, F. | As written | Yes — owner, 2026-11-19: after A comes AU26, then AH8a, AH8b, then PJ5. C waits until the owner orders it |
| 67 | In a shared chat the Chat model is the sender's, also when the owner pays. The amount bounds what that can cost. | Sender's model | ☐ |
| 68 | How a `chat` run ends. A turn without a work list ends when the model answers without a tool. A run with open `update_plan` items or ledger values is ended by the loop: one required `finish`, checked against the work list (`03` §1.5.1). Resolves the conflict between `03` §3 and §1.5.1. | Staged | Yes — owner, 2026-11-19 |
| 69 | AU26 and AH8 take their limits as profile input. `ToolLimits` keeps today's numbers as defaults; the `chat` profile sets no iteration cap, no deadline, no step and no minute budget. Their briefs say so (row 22 S4, row 25 `04_sprints.md`). AH8 is split: AH8a kernel and `BRUNS` extracted from the research engine, no consumer; AH8b research runs on it, no behaviour change. | Profile input; split | Yes — owner, 2026-11-19 |
| 70 | Until PJ6, a `chat` run checks the payer's monthly budget before every model call and ends cleanly when it is empty. `LOOP.ENABLED` stays 0 in every environment until PJ6 and PJ8 are merged. | Budget per call, flag off | Yes — owner, 2026-11-19 |
| 71 | A chat inside a project cannot get a "Can write" share until projects can be shared (PJ20). "Can read" stays possible. The share dialog says why in one sentence. | Not before PJ20 | Yes — owner, 2026-11-19 |
| 72 | When a member's account is deleted, their messages in other people's shared chats stay as rows, but the text becomes "Message deleted" and the name a neutral label. The AI's answers stay. | Replace text | Yes — owner, 2026-11-19 |
| 73 | `PROJECTS.ENABLED` turns on with PJ28, together with the other flags, not in its own step. | With PJ28 | Yes — owner, 2026-11-19 |

---

## 1. What is true today

Paths are under `backend/src/` and `frontend/src/` unless said otherwise.
Details and numbers: [`01_research.md`](./01_research.md).

**The chat turn**

- A web turn is `StreamController` → `ChatStreamOrchestrator` →
  `MessageProcessor::processStream()`. A classifier decides search, effort
  and multi-step before the model sees the message (`TurnWebSearch`,
  `AutoResearchGate`, `TaskPlanExecutor` behind `MULTITASK.ROUTING_ENABLED`).
- The plain chat model call has no tools. A tool loop runs only for document
  editing (`ChatToolLoop` via `DocumentEditCoordinator`) and in the two
  gateways. There are three loops, each with 8 iterations, 240 seconds and
  16 tools (`ToolLimits`). AU26 makes them one; it is not merged.
- Long work exists only as research: units on `async_ai_high`, state in
  Redis for 48 hours, budgets per effort (thorough: 30 searches, 8 rounds,
  20 minutes, cost 5.00). When the budget is used it writes the report from
  what it has. That is the behaviour §0 #11 asks for, in one place.
- `BRUNS` and `App\Service\Run\*` (AH8) do not exist. `ChatRunService` makes
  a chat turn re-attachable; the pump flags are off.
- History is the last 15 messages or 15,000 characters
  (`TurnHistoryLoader`), plus a rolling summary of up to 4,000 characters
  over at most 200 messages (`ConversationSummaryService`,
  `BCHATSUMMARIES`), refreshed after the reply. Nothing in the UI says a
  summary exists.
- The file inventory given to the model holds 8 files per category from the
  last 30 messages (`ConversationFileCatalog`).
- The question card exists only inside a multi-step plan
  (`AskUserRunner`, `TaskCard.vue`): one question, up to 5 options, free
  text, skip, 24 hours. A plain turn can never ask. Research has an
  `ask_user` action with no card in the UI.
- Settings → AI Models has a card with three models: task planning, reading
  sources, fact-checking (`PlannerModelCard.vue`). The summary uses the
  `ANALYZE` model, else the Chat model.

**Sharing**

- Shares are `BSHARES` (`ShareService`), with people, groups or everyone.
  A conversation can be shared "read" or "use". "Use" means *continue as my
  copy*. Nobody but the owner can post: `StreamTurnPreparer::loadChat()`
  compares the caller with `BCHATS.BUSERID`.
- A message has no author. `BMESSAGES.BUSERID` is the account the row lies
  under.
- Realtime is per user (`user:{id}`); `ChatAudienceNotifier` tells the owner
  and the people a chat is shared with that something changed, never the
  text.
- Cost is checked before a turn only (`RateLimitService::checkCostBudget`),
  always against the caller. Nothing can charge another account, except the
  widget, where the owner pays.
- Memories are per user (`BUSERMEMORIES`, Qdrant). They can be switched off
  per request and per account, not per chat. Digests and the "most recent
  other chat" tail read a user's messages across chats.

**Knowledge and apps**

- A knowledge folder is `BFILES.BGROUPKEY`; it can be shared
  (`knowledge_folder`: read, use, edit, manage) and pinned per turn
  (`ragGroupKey`). There is no endpoint that adds a link or a pasted text as
  a document. `FileIngestExecutor` (text → file → vectors) and
  `UrlContentService` (page → text) exist.
- Connections are `BCONNECTIONS`. Dropbox can save, not read. WebDAV reads
  below the connection's folder. A CalDAV connection is one calendar.
  Microsoft 365 has mail search, mail send, calendar. Notion runs through
  the hosted MCP server with the whole account's rights. Nothing switches
  an app per chat.
- Write actions go through `ApprovalPolicy` and `ApprovalService`; only the
  owner of the action can approve.

**Phone**

- `synaplan-apps` (Capacitor 8) has no push plugin, no Apple push
  capability and no Firebase file. The server picker lets the app talk to
  any server.
- The backend can sign short notification tokens (`NotificationToken`,
  Ed25519) and publish its key; today only for Platform Links.

---

## 2. Target shape

```
            ┌───────────────────────── Project ─────────────────────────┐
            │ name · instructions · sources (one knowledge folder)      │
            │ members (share) · project memory (entries name a member)  │
            └───────────────┬───────────────────────────────────────────┘
                            │ has
                 ┌──────────▼──────────┐        members write
                 │        Chat         │◄──────────────────────────────
                 │ private | shared    │   each message: author
                 └──────────┬──────────┘
                            │ a message arrives
              ┌─────────────▼─────────────┐
              │ first model call, in the  │  no tool asked → answer, done
              │ request (Chat model)      │  (same cost and speed as today)
              └─────────────┬─────────────┘
                            │ tool asked
              ┌─────────────▼─────────────┐   run kernel (AH8), kind chat
              │ run: step, step, step …   │   one loop (AU26)
              │ tools: web, pages, files, │   ── notes when the window fills
              │ knowledge, apps, ask,     │   ── cost limit → write result
              │ plan, search this chat    │   ── circling → nudge → result
              └─────────────┬─────────────┘   ── Stop / Answer now
                            │ answers without a tool
              answer in the chat · run card · push to the phone
```

**The loop.** One `chat` run profile on the loop of AU26 and the kernel of
AH8. The profile has no step count and no deadline. A step is one model call
and the tool calls it asked for; each step is a unit of at most 120 seconds
in the worker, so no PHP request waits. The tools are the ones that exist
(`web_search`, `web_fetch`, `knowledge_search`, `read_document`,
`analyze_image`, `code_execution`, the `skill:*` capabilities, MCP and app
tools, document tools) plus four new ones: `list_files`, `conversation_search`,
`update_plan` and `ask_user`. Files are read by ID. The run ends when the
model answers without asking for a tool.

**Guard rails.** Before every model call the run compares what it has spent
with its limit minus a reserve. If the next call does not fit, the last call
gets no tools and one instruction: write the result from what you have, name
what is open. The run ends as `budget_used` with an answer. A detector
watches repetition; it nudges once and then triggers the same wrap-up. Stop
ends the run and keeps what was written. "Answer now" is the wrap-up on
request.

**Notes.** When the transcript of a run reaches a share of the model's
context window, the oldest part is replaced by run notes: goal, plan state,
facts with their source, decisions, open questions, file IDs. Sources stay
re-openable by reference. The chat shows "Conversation summarized". Between
turns the rolling summary stays; in shared chats it names authors.

**Shared chats.** `edit` on a conversation lets a member post. The turn
runs as the sender: their memories setting (off here), their apps (off until
switched on), their budget, unless the chat's allowance still has room.

**Projects.** A row in `BPROJECTS`, a knowledge folder for its sources, a
flag on its chats. Sharing a project shares its sources, instructions,
memory and the chats their authors shared with it.

**Push.** The server knows a device only as an ID from the relay. It sends
the relay a signed request with that ID and a kind. The relay sends the
push. Text and content never leave the server.

---

## 3. Journeys

Full text, wireframes and copy: [`02_journeys_and_ui.md`](./02_journeys_and_ui.md).

| Journey | Persona | Steps |
| ------- | ------- | ----- |
| **J-PJ-1** — Ana creates "New kitchen", drops in the floor plan, two offers as PDF, three links and a note; asks which offer fits the plan | Ana | PJ3, PJ4 |
| **J-PJ-2** — Ana asks for a comparison of induction hobs; the chat works 25 minutes, she closes the tab, comes back, reads the answer and the steps it took | Ana | PJ5, PJ8 |
| **J-PJ-3** — The chat asks Ana two things before it starts (budget, width); she taps B, types the width, the work goes on | Ana | PJ9 |
| **J-PJ-4** — A run reaches the cost limit; Ana gets the result with "what is open" and presses Keep going | Ana | PJ6, PJ8 |
| **J-PJ-5** — Message 140 in the same chat: "make the cabinet in the picture from the beginning darker"; the right picture is changed | Ana | PJ7 |
| **J-PJ-6** — Ana shares the chat with Ben, "Can write"; both write, each sees who wrote and who the answer is for | Ana, Ben | PJ12, PJ13 |
| **J-PJ-7** — Ana sets an amount for the chat; Ben's messages draw from it, the bar fills, at the end Ben pays himself | Ana, Ben | PJ14, PJ15 |
| **J-PJ-8** — Ben switches his Notion on for this chat; his question uses it; Ana asking for "Ben's Notion" gets a plain no | Ana, Ben | PJ16, PJ17 |
| **J-PJ-9** — Ana shares the project; Ben wrote earlier that he dislikes green; Ana asks for green fronts and the answer points it out | Ana, Ben | PJ20–PJ22 |
| **J-PJ-10** — In her private chat in the project Ana switches from "Project memory only" to "Project memory and my memories" | Ana | PJ22 |
| **J-PJ-11** — Cem hosts Synaplan himself, picks his server in the app, starts a long question, locks the phone, gets the push | Cem | PJ23–PJ27 |

---

## 4. What we do not rebuild

| Need | Exists | This plan |
| ---- | ------ | --------- |
| A tool loop | AU26 `AgentToolLoop` (planned, row 22) | Adds a profile without iteration cap and deadline. AU26 must take limits as input, not constants. |
| Runs that outlive a request | AH8 run kernel, `BRUNS` (planned, row 25) | Adds kind `chat`. The kernel must accept "no step budget, no minute budget". |
| The run card in chat | AH11 (planned) | One card. Whichever of AH11 and PJ8 lands first builds it; the other adds its rows. |
| Tools, approvals, audit | `ToolRegistry`, `ToolInvoker`, `ApprovalPolicy`, `ApprovalService` | Every loop tool goes through them. |
| Web search, page reading | `WebSearchTool`, `WebFetchTool`, research searcher | Called as tools. |
| The citation check | Research engine check stage (row 21) | Runs on a loop answer with web sources. |
| A question card | `AskUserRunner`, `TaskCard.vue` lines 427–478 | Extracted into one `AskUserCard.vue`, used by both (U12). |
| Rolling summary | `ConversationSummaryService`, `BCHATSUMMARIES` | Kept. Author names added for shared chats. |
| Files of a chat | `ConversationFileCatalog` | The tools read through it; the 8-per-category limit applies to the prompt block only. |
| Sharing | `ShareService`, `AccessGate`, `ShareDialog.vue`, `SharedInbox` | New permission on `conversation`, new kind `project`. |
| Sources of a project | Knowledge folders, `RagScopeResolver`, upload pipeline | A project owns one folder. |
| Link and text as a document | `UrlContentService`, `FileIngestExecutor` | One small endpoint in front of them. |
| Budgets | `RateLimitService`, `BUSELOG`, top-ups | A payer resolver in front of `recordUsage`. |
| Memories | `UserMemoryService`, `MemoryExtractionService` | A second, small store for project memory; the personal one is untouched. |
| Signed notifications | `NotificationToken`, `NotifyKeyStore` | Same token form with a push key. |

**Related tracks**

- **Row 22, architecture update.** AU26 is a precondition for phase B. The
  pump (AU27–AU32) is not: a chat run uses worker units.
- **Row 25, agent handling.** AH8 is a precondition for phase B. §0 #23
  lists what this plan changes for chat. Agents get the same card, the same
  question card and the same wrap-up through AH9–AH11.
- **Row 21, research quality.** The engine stays. Its corpus is the bar for
  §6.
- **Row 23, per-user budget.** The payer resolver must call the same budget
  check that row 23 introduces; whichever lands second adapts.
- **Row 8, prompting flow.** Not written. The loop prompt of PJ5 is new and
  lives in `PromptCatalog` as `tools:chat_loop`.
- **Row 40, permission-aware connectors.** Drive and SharePoint scoping
  belongs there. This plan narrows only what exists (§0 #48, #49).
- **Row 41, governance.** "Local only" and retention apply to project
  sources and project memory like to any file and memory.
- **Row 26 / UP7.** The built object is called "agent". This plan adds no
  new noun besides "project".

---

## 5. Steps

Briefs: [`04_sprints.md`](./04_sprints.md). Class is the mobile impact class
of `.github/mobile-impact-policy.json`.

| Step | Commit title | Class | Depends |
| ---- | ------------ | ----- | ------- |
| PJ0 | `docs(planning): projects, shared chats and a chat that works as long as it needs` | no-app-impact | — |
| PJ1 | `test(chat): corpus and today's numbers for long and simple turns` | backend-only | — |
| PJ2 | `docs(planning): spike — a chat turn as run units` | no-app-impact | PJ1 |
| **A** | | | |
| PJ3 | `feat(projects): projects with sources and instructions` (API) | backend-only | §0 #4, #5, #63 |
| PJ4 | `feat(projects): project page and projects in the Chats panel` | ota-candidate | PJ3, §0 #6 |
| **B** | | | |
| PJ5 | `feat(chat): a chat turn runs on the one loop as a run` | backend-only | AU26, AH8, PJ2, §0 #21–#25 |
| PJ6 | `feat(chat): cost limit that ends with a result, and circle detection` | backend-only | PJ5, §0 #12–#15 |
| PJ7 | `feat(chat): run notes, chat search and files by ID` | backend-only | PJ5, §0 #27 |
| PJ8 | `feat(chat): run card — plan, steps, Stop, Answer now, Keep going` | ota-candidate | PJ5, PJ6 |
| PJ9 | `feat(chat): the chat asks you — question card` | ota-candidate | PJ5, §0 #31 |
| PJ10 | `refactor(settings): the Chat model runs the whole turn` | ota-candidate | PJ5, §0 #20 |
| PJ11 | `feat(chat): the loop is on by default` | backend-only | PJ5–PJ10, §6 gate |
| **C** | | | |
| PJ12 | `feat(sharing): write together in one chat` (API) | backend-only | §0 #34, #36, #37, #63 |
| PJ13 | `feat(sharing): names on messages and "Can write" in the share dialog` | ota-candidate | PJ12, §0 #35 |
| PJ14 | `feat(sharing): who pays in a shared chat` (API) | backend-only | PJ12, §0 #63 |
| PJ15 | `feat(sharing): allowance bar` | ota-candidate | PJ14, §0 #40 |
| PJ16 | `feat(sharing): my apps in this chat` (API) | backend-only | PJ12, §0 #45, #46, #63 |
| PJ17 | `feat(sharing): app switches in the composer, data chip on answers` | ota-candidate | PJ16 |
| PJ18 | `docs(planning): spike — Notion limited to chosen pages` | no-app-impact | PJ16 |
| PJ19 | `feat(sharing): narrow an app to a folder, a calendar or pages` | ota-candidate | PJ17, PJ18, §0 #48, #49 |
| **D** | | | |
| PJ20 | `feat(projects): share a project` | ota-candidate | PJ4, PJ13, §0 #55 |
| PJ21 | `feat(projects): project memory` (API) | backend-only | PJ20, §0 #63 |
| PJ22 | `feat(projects): memory tab and memory choice per chat` | ota-candidate | PJ21, §0 #54, #56 |
| **E** | | | |
| PJ23 | `docs(planning): spike — push through a relay, self-hosted server` | no-app-impact | §0 #59–#62 |
| PJ24 | `feat(push): devices and signed push requests` (API) | backend-only | PJ23, §0 #63 |
| PJ25 | Relay service (`synaplan-platform`, own PR there) | — | PJ23, §0 #62 |
| PJ26 | Push in the app shell (`synaplan-apps`, own PR there) | store-required | PJ24, PJ25, §0 #61 |
| PJ27 | `feat(push): tell the phone when a run is done or needs you` | ota-candidate | PJ8, PJ9, PJ26 |
| **F** | | | |
| PJ28 | `docs: projects, shared chats, long answers, push` + flags on | backend-only | all |

---

## 6. Gates

A step is done when its brief's exit list is true and these hold.

**Every step:** `make ci-local` unfiltered; `make test-e2e` for a step that
touches the UI, a route or chat; the five exit bullets of UX contract §6 for
every `ota-candidate` step; all five locales in the same PR.

**Before PJ11 (loop on by default)** — measured on the corpus of PJ1, the
command and the numbers in `STATUS.md`:

| # | Gate | Bar |
| - | ---- | --- |
| G1 | Simple turns (no tool needed): cost and time to first token | Within 10 % of today |
| G2 | Research turns: statements backed by a cited source | Not below the research engine on row 21's corpus (87.3 % today) |
| G3 | Every run ends in a terminal state with a true outcome sentence | 100 %, including kill-the-worker drills |
| G4 | Cost limit | Never exceeded by more than one model call; every such run ends with an answer |
| G5 | Planted circles (a search that always returns the same, a tool that always fails) | End in a wrap-up, with an answer |
| G6 | 150-message chat with a picture at message 5 | The right picture is edited; a fact from message 10 is quoted correctly |
| G7 | Two-hour run on a small-window model | At least three compactions, no lost goal, no duplicate work after a compaction |

**Before `SHARED_CHATS.ENABLED` is on:**

| # | Gate | Bar |
| - | ---- | --- |
| G8 | Injection corpus: member B plants an instruction, member A writes later with an app on | Zero calls to A's app that A did not ask for |
| G9 | Asking for another member's app, directly and indirectly | Zero calls; the answer says it cannot |
| G10 | Memory isolation | No personal memory read or written in a shared chat; no digest and no "other chat" tail from one |
| G11 | Allowance | Owner's usage never counted; switch to own budget exactly at the end, also inside a run |

**Before project memory is on:** G12 — conflict corpus (20 cases, five
locales): the conflict is named in at least 18; an entry is never attributed
to the wrong member.

**Before `PUSH.ENABLED` is on:** G13 — iOS and Android, cloud server and a
self-hosted server behind NAT: push arrives with the app closed; a forged
request, a replayed request and a request for another server's device are
refused.

---

## 7. Risks

| Risk | Why it matters | Answer |
| ---- | -------------- | ------ |
| Phase B rests on two unmerged steps of other rows | Nothing in B can start | §0 #22. A and C deliver first. PJ2 is a spike that needs neither. |
| No step limit and a free local model | A run could go on for days | Circle detection, Stop, one run per chat (§0 #14: no token cap). |
| The loop makes simple turns slower or dearer | Everyone pays for the few long turns | §0 #21, gate G1. |
| A weak Chat model with tool calling does poor long work | "Bad model, bad output" is accepted, a stuck run is not | Circle detection and wrap-up are model-independent; G5 runs on the cheapest seeded model. |
| Compaction drops the one fact that mattered | A two-hour run answers the wrong question | Notes keep source references, `conversation_search` reads originals, G7. |
| An instruction planted by another member | Private data from an app lands in a shared chat | §0 #44–#46, G8, G9. The tool list is the hard wall; the check is the second. |
| Allowance races: two members, two runs, one amount | Overdraw | Atomic reserve per model call (`03` §5); overshoot is at most one call per running run. |
| `BMESSAGES.BUSERID` is read in many places as "the owner" | A member's message breaks an owner-only query | The column keeps its meaning; the author is a new column (`03` §2). |
| Big files grow further (`ChatView.vue` 4,466 lines, `ChatMessage.vue` 2,370, `ShareService` 853) | Size ratchet | New code in new classes and components; no row added to the ledger. |
| Relay sees which server pushes to which device | Metadata at Synaplan for self-hosters | No content, no user ID, documented; an admin can leave `PUSH.ENABLED` off. |
| Store review and Apple / Google setup | Phase E has a lead time outside this repo | PJ23 first; E does not block A–D. |

---

## 8. Non-goals

- Project templates.
- The loop in the widget, in channels, in the gateways, in Saved Tasks.
- Browser notifications.
- Reading Dropbox. Sharing one person's app with another person.
- Live typing indicators, presence, reactions, threads.
- A list of approved models, a second "deep" model, a per-turn effort
  switch.
- A step, round or time limit.
- Paying for a whole project (the allowance is per chat in v1).

## 9. Later

- The same loop for agents in chat (through row 25), then Telegram and
  WhatsApp with a result message instead of a card.
- An allowance per project.
- Sources that refresh on their own (`UrlWatch` exists).
- A push with the chat title, encrypted for the device.
- Project memory that a member can edit, not only delete.
- Drive, SharePoint, Confluence scoping (row 40).

## 10. Change log

| Date | Change |
| ---- | ------ |
| 2026-11-16 | First version, from the owner's sessions of 2026-11-12 and 2026-11-13 and a code map on `0d228e323`. Plan only. |
| 2026-11-19 | After PJ4 (`89694c4b2`): §0 #21–#25 ticked; #63 partly; #66 order fixed (AU26 → AH8a → AH8b → PJ5); new rows #68–#73 (end of a run, limits as profile input, budget per call until PJ6, no write share for project chats before PJ20, deleted member's text, projects flag with PJ28). `03` and `04` follow. |
