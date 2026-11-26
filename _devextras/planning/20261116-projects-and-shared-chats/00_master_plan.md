# Projects, shared chats and a chat that works as long as it needs

**Status:** 2026-11-26. Phase 0 and A are on `main`; phase B is under way
(PJ5a–PJ7b merged, PJ7c next, [`STATUS.md`](./STATUS.md)). On 2026-11-26 the
owner reshaped the track after a whiteboard session: a project gets its own
state that the AI keeps (tasks, dates, decisions, waiting on, costs), the
project page becomes chat plus board, and reminders, mail and watching move
into the plan (§0 #74–#95, §10). A review of the same day (§0 #96–#111)
decouples the board from the loop, narrows calendar, mail and watches, and
moves push behind sharing. §0 records what the owner said; rows marked ☐
wait for the owner.
**Owner:** product owner.
**Roadmap:** row 46. Steps `PJ0`–`PJ47`, journeys `J-PJ-1`–`J-PJ-20`.
**Mockup:** [`mockups/project-page.html`](./mockups/project-page.html), the
agreed look of the project page (open it in a browser; the bar on top
switches "new project" / "after two weeks" and light / dark).
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
5. **The phone says when it is done.** A push when a long answer is ready,
   when the chat needs the person, or when a project reminds them. It also
   works when the app is pointed at a self-hosted server.
6. **A project knows where it stands.** Tasks, dates, decisions, who we wait
   for and what it costs are part of the project, not buried in chats. The
   AI keeps them up to date from what people write and forward. The project
   page shows them as a board next to the chat, with a written status on
   top. Claude, ChatGPT, Gemini and Perplexity have no such state in their
   projects (`01` §2.6).
7. **The project acts, after a yes.** It reminds before dates, sends a
   weekly overview, puts its dates into the person's calendar as a
   subscription, and drafts the mail that declines, moves or follows up.
   Nothing leaves without a preview and a click.
8. **Things come into the project by themselves.** A mail forwarded to the
   project becomes a file and proposals ("two dates wait for your yes").
   Watches look out for a new version or a changed page and say when
   something changed (prices and classified ads later, §0 #106).

## Phases

The order of the table is the order of work (owner, 2026-11-26, §0 #85;
proposed change §0 #107). Phases were relettered on 2026-11-26; the step
numbers did not change. Old → new on the owner's reshape: C → E, D → F,
E → D, F → I. Old → new on the review (#107): push PJ23–PJ27 leaves D and
becomes G; G → H, H → I, I → J; D keeps reminders, the calendar and the
mail preview, all without a new service.

| Phase | What ships | Needs |
| ----- | ---------- | ----- |
| 0 — Measure | This plan, a test corpus with today's numbers, one runtime spike | — |
| A — Projects for one person | Project, sources, instructions, chats in it | — |
| B — The chat works as long as it needs | Chat turn on the one loop as a run, cost limit with a result, circle detection, notes, question card, run card | AU26, AH8 |
| C — The project board | Chat area that a page can embed, project items (tasks, dates, decisions, costs) extracted after every answer, project page with chat left and board right, written status, a plan from two questions, project memory and search over the project's chats | A; PJ29 needs PJ8; the first plan needs PJ9 |
| D — Remind and write back | Reminders and the weekly overview by e-mail, the calendar subscription, the mail preview (copy, mail app, Send with Microsoft 365) | C |
| E — Shared chats | Write together, names on messages, who pays, apps per person | — (app switches use B when it is on) |
| F — Shared projects | Share a project, members on the board, "since you were here" | C, E |
| G — Push | Relay, app, push for runs and reminders | B (PJ8, PJ9), D; store release of the app |
| H — Into the project | Mails forwarded to a project become files and proposals; share to a project from the phone | C (G for the phone) |
| I — Watches | A new version or a changed page, reported in the project | C |
| J — Rollout | Flags on, user docs | all |

C does not wait for PJ11: items are extracted after the answer, not by loop
tools (§0 #96). Until G, every reminder goes out by e-mail.

## Start here

1. §0 — every decision, what the owner already said, what is still open.
   The rows of 2026-11-26 (#74–#95) reshape C–H; the review rows
   (#96–#111) narrow them.
2. §2 — the target shape in one page.
3. [`02_journeys_and_ui.md`](./02_journeys_and_ui.md) — what people see.
4. [`03_contracts.md`](./03_contracts.md) — tables, events, endpoints, flags.
5. [`04_sprints.md`](./04_sprints.md) — one brief per step.
6. [`01_research.md`](./01_research.md) — what was read and measured.
7. [`STATUS.md`](./STATUS.md) — step log.
8. [`mockups/project-page.html`](./mockups/project-page.html) — the agreed
   project page.

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
| 5 | A link is a snapshot of the page at the time it was added. "Refresh" fetches it again. A source does not watch itself; looking out for changes is a watch (#90, phase H). | Snapshot | Yes — owner, 2026-11-17; watches added 2026-11-26 |
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
| 17 | The loop is not used in WhatsApp, Telegram, e-mail, the two gateway APIs, Saved Tasks, Desktop. They keep today's paths. Reminders and project mail use e-mail and push (#84); WhatsApp and Telegram come later as extra channels. | Later (§9) | ☐ |
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
| 27 | Earlier messages stay findable word for word: the loop has a tool that searches the whole chat. The summary is the map, not the only copy. | Tool | Yes — owner, 2026-11-25 |
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
| 49 | Notion: "Only these pages" — the member searches their Notion in the switch and picks pages; the chat can open those pages and their sub-pages and nothing else. Not "Followed pages". Needs the spike PJ18 first; until then Notion is on or off. Proposal 2026-11-26: PJ18 and PJ19 move to §9, on/off per app is enough for v1. | Later | ☐ |

### Project memory

| # | Decision | Default | Agree? |
| - | -------- | ------- | ------ |
| 50 | A project has its own memory. Every entry names the member it belongs to. | As written | Yes — owner |
| 51 | Entries come only from what a member wrote in the project. Personal memories are never copied in. | As written | Yes — owner |
| 52 | Conflicts are pointed out by the model ("…, but Cristian does not like green"). No rule list. A test corpus proves it (§6). | Model + corpus | Yes — owner |
| 53 | In a chat that is not shared, inside a project, the person chooses: "Project memory only" or "Project memory and my memories". | Choice | Yes — owner |
| 54 | The default of that choice. | Project memory only | Yes — owner, 2026-11-26 |
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
| 63 | **Ask — schema.** New tables `BPROJECTS`, `BPROJECTMEMORIES`, `BCHATALLOWANCES`, `BCHATAPPGRANTS`, `BPUSHDEVICES`, and from 2026-11-26 `BPROJECTITEMS`, `BPROJECTITEMLOG`, `BPROJECTREMINDERS`, `BPROJECTWATCHES`, `BOUTGOINGMAILS`, and from the review `BPROJECTMEMBERFEEDS`, `BPROJECTSENDERS` (`03` §2.7–§2.12); new columns `BPROJECTS.BSTATUSTEXT`, `BPROJECTS.BSTATUSAT`, `BPROJECTS.BBUDGET`, `BPROJECTS.BMAILKEY`, `BPROJECTS.BMAINCHATID`; `BCHATS.BPROJECTID`, `BCHATS.BPROJECTSHARED`, `BCHATS.BMEMORYMODE`, `BMESSAGES.BAUTHORID`, `BRUNS.BNOTES`; `BRUNS.BKIND` gets the value `chat`; two switches in `BUSERDETAILS` (`03` §2). | — | `BPROJECTS`, `BCHATS.BPROJECTID/BPROJECTSHARED/BMEMORYMODE`: Yes — owner (PJ3); `BPROJECTS.BPINNEDAT` shipped with PJ4; `BRUNS.BKIND = chat` and `BRUNS.BNOTES`: Yes — owner, 2026-11-19, in AH8a's `BRUNS` migration; the rest ☐ Ask |
| 64 | Four flags, all off: `LOOP.ENABLED`, `SHARED_CHATS.ENABLED`, `PROJECTS.ENABLED`, `PUSH.ENABLED`. Projects and shared chats are feature modules. Phases C, D, H and I add `PROJECTS.BOARD_ENABLED`, `PROJECTS.REMINDERS_ENABLED`, `PROJECTS.CALENDAR_FEED_ENABLED`, `PROJECTS.MAIL_ENABLED`, `PROJECTS.INBOX_ENABLED`, `PROJECTS.WATCHES_ENABLED` (`03` §11), all off. | Off | ☐ |
| 65 | `LOOP.ENABLED` turns on for everyone only after the gate in §6 is green on the corpus of PJ1. | Gate first | ☐ |
| 66 | Order: 0, then A and C in either order, B as soon as AU26 and AH8 are on `main`, then D, E, F. | As written | Yes — owner, 2026-11-19: after A comes AU26, then AH8a, AH8b, then PJ5. Replaced by #85 (2026-11-26) for everything after B |
| 67 | In a shared chat the Chat model is the sender's, also when the owner pays. The amount bounds what that can cost. | Sender's model | ☐ |
| 68 | How a `chat` run ends. A turn without a work list ends when the model answers without a tool. A run with open `update_plan` items or ledger values is ended by the loop: one required `finish`, checked against the work list (`03` §1.5.1). Resolves the conflict between `03` §3 and §1.5.1. | Staged | Yes — owner, 2026-11-19 |
| 69 | AU26 and AH8 take their limits as profile input. `ToolLimits` keeps today's numbers as defaults; the `chat` profile sets no iteration cap, no deadline, no step and no minute budget. Their briefs say so (row 22 S4, row 25 `04_sprints.md`). AH8 is split: AH8a kernel and `BRUNS` extracted from the research engine, no consumer; AH8b research runs on it, no behaviour change. | Profile input; split | Yes — owner, 2026-11-19 |
| 70 | Until PJ6, a `chat` run checks the payer's monthly budget before every model call and ends cleanly when it is empty. `LOOP.ENABLED` stays 0 in every environment until PJ6 and PJ8 are merged. | Budget per call, flag off | Yes — owner, 2026-11-19 |
| 71 | A chat inside a project cannot get a "Can write" share until projects can be shared (PJ20). "Can read" stays possible. The share dialog says why in one sentence. | Not before PJ20 | Yes — owner, 2026-11-19 |
| 72 | When a member's account is deleted, their messages in other people's shared chats stay as rows, but the text becomes "Message deleted" and the name a neutral label. The AI's answers stay. | Replace text | Yes — owner, 2026-11-19 |
| 73 | `PROJECTS.ENABLED` turns on with PJ28, together with the other flags, not in its own step. | With PJ28 | Yes — owner, 2026-11-19 |


### The project board (2026-11-26)

Owner session of 2026-11-26 on the boss's whiteboard ("Syna Projects": DIY
kitchen, buying a flat; dates with reminders, to-dos, files, memory,
research, communication and interfaces, agents and alerts, models) and the
mockup. "Yes" rows quote what the owner said in that session.

| # | Decision | Default | Agree? |
| - | -------- | ------- | ------ |
| 74 | A project has its own state: tasks, dates, decisions, waiting on (who owes us what) and costs. Each item has a status, a date or due date where it applies, a person, and a link to the message, mail or file it came from. | As written | Yes — owner, 2026-11-26 |
| 75 | The AI writes items from the person's own messages directly, with "Undo" under the message. Items from a forwarded mail, a file or a watch are only proposed ("Zum Bestätigen") and need a yes. The AI never ticks a task off silently; it proposes it. | Write own, propose the rest | Yes — owner, 2026-11-26 |
| 76 | Built for every kind of project, not for one niche: the same items fit a kitchen, a flat purchase, a PHP upgrade or a client project. No templates (#2 stays); when a project is created the AI asks two or three questions and writes the first plan. | General core | Yes — owner, 2026-11-26 |
| 77 | The project page is chat and board side by side: the chat on the left, about 40 % and at least 420 px, the board on the right. A divider can be dragged, either side can be hidden, and the choice is remembered per person. No free layout and no switch to swap the sides. | As written | Yes — owner, 2026-11-26 |
| 78 | A new project shows only the chat, full width. The board slides in with the first item. On the phone chat and board are two tabs, the chat first; a new project has no tabs. | As written | Yes — owner, 2026-11-26 |
| 79 | The board is calm. Four tabs: Overview, Tasks, Dates, Files, and "More" (Costs, Watching for you, Decisions, Waiting on others, What I remember, Chats, People and settings). The overview starts with a written status of two or three sentences and one line "As of today, 14:05. 4 of 11 steps done.", then the cards "To confirm", Tasks, Dates, Watching, Costs; Decisions, Waiting on and Remembered sit behind "Show more". A card appears only when it has content. Everyday words, no product words (`02` §4). | As the mockup | Yes — owner, 2026-11-26 |
| 80 | The board is fixed building blocks that the AI fills with data. The AI never writes HTML or UI. What does not fit a block becomes a document in the project (a comparison table, a checklist). | Fixed blocks | Yes — owner, 2026-11-26 |
| 81 | A proposed date has three answers: "Add", "Doesn't work" and "Ignore". "Doesn't work" opens a mail that declines or proposes other times; a confirmed date can be moved the same way. "Follow up" on a waiting item opens a reminder mail. | As written | Yes — owner, 2026-11-26 |
| 82 | Every outgoing mail is shown first: from, to, subject and an editable text, with variants (decline / other times). It is sent only when the person clicks "Send". No agent, watch or run sends a mail on its own. | Preview and click | Yes — owner, 2026-11-26 |
| 83 | Who sends: with a connected mailbox the mail goes out from the person's own address and lies in their sent mails (Microsoft 365 today, `M365MailSender`). Without one, the same preview offers "Copy text" and "Open in mail app". Synaplan never sends in the person's name from a Synaplan address. A recipient whose address is unknown (the date came from the chat, not from a mail) is asked for first. | Own mailbox, else template | ☐ — narrowed by #104 |
| 84 | Channels: push and e-mail first; push moves forward into phase D. WhatsApp and Telegram come later as extra channels (Meta's rules for AI assistants on the business API, no bot in existing groups, paid templates outside 24 hours; `01` §2.6). | Push and mail first | Yes — owner, 2026-11-26 |
| 85 | Order after B: C board, D remind and write back (with push), E shared chats, F shared projects, G into the project, H watches, I rollout. | As written | Yes — owner, 2026-11-26 |
| 86 | No model picker per project. Whoever triggers a turn uses their own Chat model and pays (#38, #67). Vision, image and sound models are tools the loop calls. A project default model is not planned. | None | Yes — owner, 2026-11-26 |
| 87 | Nothing is bought automatically. A watch says "Oven at 439 €, here is the link". | Alert and link | ☐ |
| 88 | Project dates are written into the person's calendar (Outlook, CalDAV) and stay linked; moving them in the project updates the calendar entry. The calendar is read only to warn about a clash ("You already have something on Monday at 10"); it is never shown in the project. | Write and link, read for clashes | ☐ — replaced by #103 (proposal) |
| 89 | Reminders: the evening before a date at 19:00, a weekly overview on Monday at 08:00, and one notice the day after something became overdue. Push when the app has it, e-mail otherwise. Each switch is per project in "People and settings". | As written | ☐ |
| 90 | Watches have one fixed frame (what is watched as one sentence, when it reports, last checked with sources, history, pause, stop) and a value the AI picks from five kinds: number with a threshold, version or text, new entries, yes / no, page changed. A check is cheap; the model is called only when something changed. A check that fails says "Could not check" with the reason. | As written | ☐ — narrowed by #106 |
| 91 | "Forward mails to this project": every project gets an address made from the person's existing smart address and a project keyword (`smart+kueche-3f@…`, `SmartEmailHelper`). A forwarded mail becomes a file and proposals; it is not a mailbox and sends nothing. Only mails from the project's members are accepted. | Keyword address | ☐ — with #105 |
| 92 | Project memory and search work in a project of one person, before projects can be shared: "What I remember" lists the entries, each can be corrected or deleted; one search finds a word in all chats of the project. PJ21 and PJ22 move from F into C. | Move into C | ☐ |
| 93 | Every project has a main chat that the page opens; other chats are reachable through the switcher in the chat header and "More → Chats". | Main chat | ☐ |
| 94 | A member of a shared project sees "Since you were here" on top of the overview: what changed, who did it. | As written | ☐ |
| 95 | The written status is refreshed when an item changes and once in the morning of a day on which the project has open items, so the time words stay true. One call of the memory model of whoever made the last change (#109), counted to them; the morning call to the owner. Time words are computed by the client, not written by the model (`03` §14). | On change and daily | ☐ (changed by the review, 2026-11-26) |

### Review of the reshaped plan (2026-11-26)

A read of the plan, the mockup and the code after #3283. The rows below are
proposals; each names the row or step it changes. Rows that change an owner
"Yes" (#74, #79, #84, #85) need the owner's tick before a step builds them.
The rest of this plan already follows these rows.

| # | Decision | Default | Agree? |
| - | -------- | ------- | ------ |
| 96 | **The board does not depend on the loop.** Items are extracted after every answer in a project chat by one unit on `async_ai_low`, with structured output, in the same call that extracts project memory (#98). It runs for every Chat model, for turns with files, and with `LOOP.ENABLED` off. Reason: `ChatLoopGate` keeps turns with files, "Again", agents and models that do not call tools while streaming on today's path, so a dropped PDF or a person on Gemini or Ollama would never fill the board. The line "Added: … · Undo" arrives by event a few seconds after the answer. The loop keeps one board tool, `project_plan`, for the first plan and "make a plan for …". Phase C starts after PJ29, not after PJ11. | Extract after the answer | ☐ |
| 97 | **Four kinds instead of five:** task, date, decision, cost. "Waiting on others" is a view: open tasks whose assignee is another member or an outside contact, with the date since when. An item can belong to another item (`BPARENTID`: the task "Have the kitchen measured" to the date "Measuring"); tasks of a plan have an order (`BSORT`); a cost can be an estimate (`BESTIMATED`). A decision with state `open` is an open question. Changes #74 (owner) and `03` §2.8. | Four kinds | ☐ Owner (changes #74) |
| 98 | **One statement, one place.** Project memory holds facts and preferences ("The niche is 560 × 590 mm", "Ben does not like green"); the board holds choices and work ("No green fronts"). The one extraction call of #96 decides where a statement goes and never writes it to both. | Memory or board | ☐ |
| 99 | **Items from a private chat stay private.** In a shared project, an item that came from a member's private chat is seen only by that member until the chat is shared with the project or the member shares the item ("Show to everyone" in its menu). Replaces the last bullet of `03` §13 ("visible to all members"). | Private first, like #55 | ☐ |
| 100 | **Add by hand.** Tasks and Dates have one quick-add line on top ("Add a task …"); what is typed goes through the same extraction (date, person) and lands as `open` with source `manual`. The board still needs no upkeep; it only allows it. Changes the mockup's "Neu anlegen geht im Chat". | Quick-add line | ☐ |
| 101 | **Personal memories are chosen per chat** (#53, #54), in the composer menu. The switch "Meine persönlichen Erinnerungen in diesem Projekt nutzen" under "People and settings" in the mockup goes; that section shows one line that points to the composer. | Per chat, as #53 | ☐ |
| 102 | **Fewer places on the board.** Tabs: Overview, Tasks, Dates, Files. "More" holds only Chats, What I remember, People and settings. Costs, Decisions, Waiting on others and Watching for you are cards on the overview; "All" on a card opens its full list in the board, not a tab. Changes #79 (owner). | 4 tabs + 3 in More | ☐ Owner (changes #79) |
| 103 | **Calendar as a subscription.** Every member gets a private calendar address per project (`webcal://…/projects/{uuid}/calendar.ics?token=…`, read-only, can be renewed). Google, Apple and Outlook show the project's dates and keep them current by themselves. No OAuth, no write access to anyone's calendar, no drift when an entry is moved in the calendar, no question whose calendar a shared date goes to. Writing into Outlook or CalDAV and reading for clashes move to §9. Replaces #88. | Subscription | ☐ |
| 104 | **Mail out: copy and mail app first.** Every mail preview offers "Copy text" and "Open in mail app" (`mailto:` with subject and body); "Send" from the person's own mailbox is an extra where a Microsoft 365 connection with `Mail.Send` exists. The private persona (GMX, Gmail) has no such connection, so the copy path is the main path and must be complete on its own. Narrows #83. | Copy first, Send where possible | ☐ |
| 105 | **Forwarded mail must be authentic.** A mail to a project's address is accepted only when its `From` is a member's address and that domain's DMARC check passes (SPF or DKIM aligned). A member can add further own sender addresses, each confirmed by a link mailed to it. Anything else is dropped and audited. Extends #91. | DMARC pass + confirmed addresses | ☐ |
| 106 | **Watches v1: version and page only.** `version` (release feeds, GitHub, Packagist, npm) and `page` (today's `UrlWatch`) ship. Prices across shops (`number`) and new classified ads (`entries`) move to §9: shops block automated reads, prices load by script, and classified sites forbid scraping in their terms; such a watch would mostly say "Could not check". `yesno` comes with `number`. A watch extends `UrlWatch` and the Saved Tasks tick; no third scheduler. Narrows #90. | Two kinds | ☐ |
| 107 | **Order after B.** C board → D remind and write back without a new service (e-mail reminders, the calendar subscription, the mail preview) → E shared chats → F shared projects → G push → H into the project → I watches → J rollout. Push leaves D because it needs a store release and a relay and nothing in C, D or E needs it (e-mail stands in). Sharing comes before push because the board is worth most with a second person (the mockup's "after two weeks" needs F). Changes #85 and the phase named in #84 (owner). | As written | ☐ Owner (changes #84, #85) |
| 108 | **A project turn has its own cost gate.** The board adds to every project turn: the open items block (at most 40 items and 2,000 tokens), the extraction call and the status call. G20 (§6) bounds it. | G20 | ☐ |
| 109 | **Small model for the side calls.** Extraction (#96) and the status (#95) use the person's memory model (`ModelConfigService::getMemoryModelConfig()`), as memory extraction does today, not the Chat model. #86 is about the answer; these are not answers. | Memory model | ☐ |
| 110 | **A second case before PJ32.** The mockup gets a second project of another kind (a PHP upgrade or a client project) before PJ32 builds the page, to prove that the fixed blocks fit more than a kitchen (#76). | Second mockup | ☐ |
| 111 | **Whom C is for first.** Households (Ana: GMX or Gmail, phone first) or teams and businesses (Microsoft 365, Nextcloud, desktop first). It decides the examples, the mail path of #104 and whether Gmail sending moves up from §9. | — | ☐ Owner |

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

**Board, reminders and mail** (checked 2026-11-26)

- A project has no items. Tasks, dates and decisions exist only as text in
  chats.
- Calendars can be written, not read: `Service/Destination/OutlookCalendarDestinationProvider.php`
  and `CalDavDestinationProvider.php` (and an `.ics` file), through
  `GraphClient::createEvent()` for Microsoft 365.
- Sending from the person's own mailbox exists for Microsoft 365 only:
  `Service/Microsoft/M365MailSender.php` (delegated `Mail.Send`,
  `GraphClient::sendMail()`), used today only by `EmailMeRunner` ("mail me
  the result"). Everything else goes through the system SMTP
  (`Service/Email/InternalEmailService.php`). There is no Gmail sending.
- Inbound mail is one address per person with a keyword
  (`smart+keyword@…`, `Service/Email/SmartEmailHelper.php`); there is no
  address per project.
- Proactive messages: e-mail only. WhatsApp and Telegram answer, they do not
  start a conversation. There is no one-off schedule ("Monday 08:00");
  Saved Tasks run on cron expressions (`Service/SavedTask/Schedule/CronNextRun.php`).
- `UrlWatch` (`Entity/UrlWatch.php`, `Service/UrlWatch/UrlWatchService.php`)
  compares a page with its last copy (`TextDiff`); it knows no project and
  no threshold.
- `views/ChatView.vue` has 4,462 lines and is bound to `MainLayout`; it
  cannot be placed inside the project page without first extracting a chat
  area.

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

**The board.** A project has items (`BPROJECTITEMS`): task, date,
decision, cost (§0 #97). "Waiting on others" is a view of open tasks that
wait for another member or an outside contact. After every answer in a
project chat, one unit on `async_ai_low` reads the sender's message and the
answer and returns, in one structured call on the memory model, the items
to add or change and the memory entries (§0 #96, #98, #109). It does not
depend on the loop: it runs for every Chat model, for turns with files and
with the loop off. Items from the sender's own message are written at once
and logged (`BPROJECTITEMLOG`) so "Undo" restores the state before;
everything from a forwarded mail, a file, a watch or another member's
earlier message is written `proposed` and shows under "To confirm". The
loop has one board tool, `project_plan`, for the first plan and "make a
plan for …". Tasks and Dates have a quick-add line (§0 #100). The project
page embeds the same chat area as the normal chat (no second chat), on the
left; the board on the right reads the items. Items from a private chat are
seen only by its author until shared (§0 #99). When an item changes, and
once each morning, one small call rewrites the status text
(`BPROJECTS.BSTATUSTEXT`); time words in it are computed by the client.

**Acting.** A reminder is a row with a due time (`BPROJECTREMINDERS`), sent
by a tick command by e-mail, and by push once phase G is there. A mail to a
third person is a draft (`BOUTGOINGMAILS`); the person copies it or opens it
in their mail app, or, with a Microsoft 365 mailbox, clicks "Send" (§0
#104). Dates reach the person's calendar as a subscription: one private
`.ics` address per member and project that every calendar app polls (§0
#103). Nothing writes into anyone's calendar.

**Coming in.** A mail to the project's address is accepted only from a
member's confirmed address with a passing DMARC check (§0 #105); it lands as
a file in the project's folder and the same extraction proposes items. A
watch (`BPROJECTWATCHES`, kinds `version` and `page` in v1, §0 #106) is
checked by the Saved Tasks tick, compares cheaply, and calls the model only
when the value changed.

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
| **J-PJ-12** — Ana creates "DIY kitchen"; the chat asks two things, writes eleven steps, and the board slides in next to the chat | Ana | PJ29–PJ33 |
| **J-PJ-13** — Ben writes "I'm home on Monday and will deal with the measurer"; the task gets Ben and the date, a line under his message offers "Undo" | Ana, Ben | PJ30, PJ31 |
| **J-PJ-14** — Ana forwards the mail of the worktop supplier to the project; two dates wait for her yes; one she adds, one she declines with a mail | Ana | PJ40, PJ38, PJ43 |
| **J-PJ-15** — The electrician has not called back for four days; Ana presses "Follow up", reads the mail, changes a word, opens it in her mail app (or sends it from Microsoft 365) | Ana | PJ38 |
| **J-PJ-16** — The evening before the measuring Ben gets a reminder (mail, push once G is there); on Monday morning Ana gets the week in one message | Ana, Ben | PJ36, PJ27 |
| **J-PJ-17** — "Tell me when PHP 8.5.2 is out" and "tell me when the opening hours page of the recycling centre changes": two watches, two kinds, one frame | Ana | PJ44, PJ45 |
| **J-PJ-19** — Ana subscribes to the project's calendar in her phone's calendar app; a date the chat adds shows up there within the hour | Ana | PJ37 |
| **J-PJ-20** — Ana drops an offer as PDF into the project chat while her Chat model is Gemini; the answer comes as today, without the loop, and "To confirm" still shows the offer's cost and delivery date | Ana | PJ31 |
| **J-PJ-18** — Ana drags the divider, hides the board to read a long answer, opens the project on the phone and lands in the chat | Ana | PJ32 |

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
| The chat UI | `views/ChatView.vue` | A chat area extracted once (PJ29) and used by the chat page and the project page. |
| Writing to a calendar | `OutlookCalendarDestinationProvider`, `CalDavDestinationProvider`, the `.ics` file | v1 serves a subscription (`.ics` feed, §0 #103); the providers stay for §9 (write into a calendar). |
| Sending from the person's mailbox | `M365MailSender`, `GraphClient::sendMail()` | "Send" in the mail preview where a Microsoft 365 connection exists; otherwise copy and mail app (§0 #104). |
| Extracting from a turn | `MemoryExtractionService`, the memory model | Board items and project memory in one call after the answer (§0 #96, #98). |
| Inbound mail | `SmartEmailHelper`, the inbound mail channel | A project keyword routes a mail into the project. |
| Watching a page | `UrlWatch`, `UrlWatchService`, `TextDiff` | The "page changed" kind of a watch; `version` is new; prices and ads are §9 (§0 #106). |
| Schedules | Saved Tasks, `SavedTasksTickCommand`, `CronNextRun` | Watches run on the Saved Tasks tick, no third scheduler; the weekly overview reuses the pattern; one-off reminders are new. |
| Asking two things at the start | `ask_user` and the question card (PJ9) | The first plan of a project uses them. |

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
| **C — the project board** | | | |
| PJ29 | `refactor(chat): a chat area that a page can embed` | ota-candidate | PJ8 |
| PJ30 | `feat(projects): project items — tasks, dates, decisions, costs` (API) | backend-only | PJ3, §0 #63, #74, #97 |
| PJ31 | `feat(projects): the board fills itself from the chat` | backend-only | PJ30, PJ21, §0 #75, #96, #98, #109 |
| PJ32 | `feat(projects): project page with chat and board side by side` | ota-candidate | PJ29, PJ30, §0 #77–#79, #93, #100, #102, #110 |
| PJ33 | `feat(projects): a written status and a first plan from two questions` | ota-candidate | PJ31, PJ32, PJ9, §0 #76, #95 |
| PJ21 | `feat(projects): project memory` (API) — moved here from F; before PJ31, which writes into it | backend-only | PJ4, §0 #63, #92 |
| PJ22 | `feat(projects): "What I remember" and the memory choice per chat` — moved here from F | ota-candidate | PJ21, PJ32, §0 #54, #101 |
| PJ34 | `feat(projects): search all chats of a project` | ota-candidate | PJ7a, PJ32, §0 #92 |
| **D — remind and write back, no new service** | | | |
| PJ35 | `feat(projects): reminders on one-off times` (API) | backend-only | PJ30, §0 #63, #89 |
| PJ36 | `feat(projects): reminders before dates and the weekly overview, by e-mail` | ota-candidate | PJ35, PJ33, §0 #89 |
| PJ37 | `feat(projects): the project's dates as a calendar subscription` | ota-candidate | PJ30, §0 #103 |
| PJ38 | `feat(projects): mail preview to decline, move and follow up` | ota-candidate | PJ32, §0 #81–#83, #104 |
| **E — shared chats** | | | |
| PJ12 | `feat(sharing): write together in one chat` (API) | backend-only | §0 #34, #36, #37, #63 |
| PJ13 | `feat(sharing): names on messages and "Can write" in the share dialog` | ota-candidate | PJ12, §0 #35 |
| PJ14 | `feat(sharing): who pays in a shared chat` (API) | backend-only | PJ12, §0 #63 |
| PJ15 | `feat(sharing): allowance bar` | ota-candidate | PJ14, §0 #40 |
| PJ16 | `feat(sharing): my apps in this chat` (API) | backend-only | PJ12, §0 #45, #46, #63 |
| PJ17 | `feat(sharing): app switches in the composer, data chip on answers` | ota-candidate | PJ16 |
| PJ18 | `docs(planning): spike — Notion limited to chosen pages` (proposed for §9, §0 #49) | no-app-impact | PJ16 |
| PJ19 | `feat(sharing): narrow an app to a folder, a calendar or pages` (proposed for §9, §0 #49) | ota-candidate | PJ17, PJ18, §0 #48, #49 |
| **F — shared projects** | | | |
| PJ20 | `feat(projects): share a project` | ota-candidate | PJ4, PJ13, PJ32, §0 #55, #99 |
| PJ47 | `feat(projects): members on the board and "Since you were here"` | ota-candidate | PJ20, PJ22, §0 #56, #94 |
| **G — push** | | | |
| PJ23 | `docs(planning): spike — push through a relay, self-hosted server` | no-app-impact | §0 #59–#62 |
| PJ24 | `feat(push): devices and signed push requests` (API) | backend-only | PJ23, §0 #63 |
| PJ25 | Relay service (`synaplan-platform`, own PR there) | — | PJ23, §0 #62 |
| PJ26 | Push in the app shell (`synaplan-apps`, own PR there) | store-required | PJ24, PJ25, §0 #61 |
| PJ27 | `feat(push): tell the phone when a run is done, needs you, or a project reminds you` | ota-candidate | PJ8, PJ9, PJ26, PJ36 |
| **H — into the project** | | | |
| PJ40 | `feat(projects): forward mails to a project` | ota-candidate | PJ31, PJ32, §0 #91, #105 |
| PJ41 | Share to a project from the phone (`synaplan-apps`, own PR there) | store-required | PJ40, PJ26 |
| PJ42 | `feat(projects): files of a project are read for items` | backend-only | PJ31 |
| PJ43 | `feat(projects): "To confirm" — add, doesn't work, ignore` | ota-candidate | PJ40, PJ38, §0 #81 |
| **I — watches** | | | |
| PJ44 | `feat(projects): watches for a version or a changed page` (API) | backend-only | PJ30, §0 #63, #87, #90, #106 |
| PJ45 | `feat(projects): "Watching for you" on the board` | ota-candidate | PJ44, PJ32 |
| PJ46 | `feat(projects): watches report in the project, by mail and push` | backend-only | PJ44, PJ36 (push once PJ27 is there) |
| **J — rollout** | | | |
| PJ28 | `docs: projects, the board, shared chats, long answers, push` + flags on | backend-only | all |

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

**Before `PROJECTS.BOARD_ENABLED` is on** — a board corpus (40 chat
turns from five kinds of project, five locales; `backend/tests/Eval/ProjectBoard/`):

| # | Gate | Bar |
| - | ---- | --- |
| G14 | Items the AI writes from the person's own messages | At least 90 % right (kind, date, person) on the memory model of the seeded default plan and on one local Ollama model; none from a message that only mentions a date in passing |
| G15 | Items from a forwarded mail or a file | 100 % land as proposals, none is written directly |
| G16 | Dates | Time zone and repetition said back in the "Added" line; no date off by a day across a DST change |
| G17 | Undo | Restores the exact state before, also after a later change to the same item |
| G20 | Cost of a project turn (§0 #108) | The same 40 turns cost at most 15 % more inside a project than outside, extraction and status included; the open items block never exceeds 2,000 tokens |
| G21 | Private items (§0 #99) | No item from a member's private chat reaches another member through the board, the status text, the weekly overview, the calendar subscription or search |

**Before the calendar subscription is on:** G22 — a feed address of one
member never shows another member's private items; a renewed address makes
the old one answer 404 at once; the feed carries no item that is
`proposed` or `dropped`.

**Before `PROJECTS.MAIL_ENABLED` is on:** G18 — no mail leaves without a
click by the person in the preview (agent, watch, run and replayed requests
tried); the text sent is the text shown; a mail is sent at most once.

**Before `PROJECTS.WATCHES_ENABLED` is on:** G19 — 20 watches of the two
kinds (`version`, `page`) over a week: a change is reported within one interval, an unchanged
value never calls the model, a broken page reports "Could not check" and
never a made-up value.

**Before `PUSH.ENABLED` is on:** G13 — iOS and Android, cloud server and a
self-hosted server behind NAT: push arrives with the app closed; a forged
request, a replayed request and a request for another server's device are
refused.

---

## 7. Risks

| Risk | Why it matters | Answer |
| ---- | -------------- | ------ |
| Phase B rested on two steps of other rows | Nothing in B could start | Resolved: AU26, AH8a and AH8b are merged (2026-11-20). |
| No step limit and a free local model | A run could go on for days | Circle detection, Stop, one run per chat (§0 #14: no token cap). |
| The loop makes simple turns slower or dearer | Everyone pays for the few long turns | §0 #21, gate G1. |
| A weak Chat model with tool calling does poor long work | "Bad model, bad output" is accepted, a stuck run is not | Circle detection and wrap-up are model-independent; G5 runs on the cheapest seeded model. |
| Compaction drops the one fact that mattered | A two-hour run answers the wrong question | Notes keep source references, `conversation_search` reads originals, G7. |
| An instruction planted by another member | Private data from an app lands in a shared chat | §0 #44–#46, G8, G9. The tool list is the hard wall; the check is the second. |
| Allowance races: two members, two runs, one amount | Overdraw | Atomic reserve per model call (`03` §5); overshoot is at most one call per running run. |
| `BMESSAGES.BUSERID` is read in many places as "the owner" | A member's message breaks an owner-only query | The column keeps its meaning; the author is a new column (`03` §2). |
| Big files grow further (`ChatView.vue` 4,466 lines, `ChatMessage.vue` 2,370, `ShareService` 853) | Size ratchet | New code in new classes and components; no row added to the ledger. |
| Relay sees which server pushes to which device | Metadata at Synaplan for self-hosters | No content, no user ID, documented; an admin can leave `PUSH.ENABLED` off. |
| Store review and Apple / Google setup | Phase G has a lead time outside this repo | Nothing before G needs push (§0 #107); reminders go out by e-mail until push is there; PJ23 can start early. |
| The board only fills on some turns | `ChatLoopGate` keeps files, "Again", agents and non-streaming tool models off the loop; with loop tools a dropped PDF or a Gemini or Ollama user would never fill the board | Extraction after the answer, independent of the loop (§0 #96); J-PJ-20; G14 on a local model. |
| Item kinds overlap | The same fact as task, date and waiting, or as decision and memory, makes the board noisy and the extraction less accurate | Four kinds, waiting as a view, links between items, one statement in one place (§0 #97, #98). |
| A private chat leaks through the board | A member's private task ("buy Ben's present") shows up for Ben | Private items until shared (§0 #99), G21. |
| A forged forwarder | Anyone who knows the address puts proposals and instructions into a project | DMARC pass and confirmed sender addresses (§0 #105). |
| The status goes stale | "four days" is wrong on day five when nothing changed | Time words computed by the client, one morning refresh (§0 #95). |
| Watches fail on real shops | Bot blocks, script-loaded prices and terms that forbid scraping turn a price watch into "Could not check" | v1 watches versions and pages only (§0 #106). |
| The mail preview has no "Send" for most private people | GMX and Gmail are not connected; Microsoft 365 is | Copy and mail app are the main path (§0 #104); §0 #111 decides whether Gmail moves up. |
| Project turns get dearer | Items block, extraction and status come on top of every answer | Memory model for the side calls (§0 #109), G20. |
| Wrong items on the board | After a week of wrong dates the board is noise and nobody trusts it | Own messages only are written directly, everything else is proposed (§0 #75); every item names its source; Undo; G14–G17. |
| Nobody keeps the board up | Private project tools die because nobody maintains them | The AI keeps it from what people write; nothing needs a daily ritual; the chat alone is enough to use a project. |
| A mail goes out wrong or twice | A real person gets it; it cannot be taken back | Preview and click only (§0 #82); idempotent send per draft; G18. |
| Third parties' data in forwarded mails | GDPR: a mail names people who never agreed to Synaplan | Only members can forward; a forwarded mail is a project file and is deleted with the project; the admin docs say so. |
| WhatsApp rules change | A channel can vanish overnight | Push and mail are the core (§0 #84); WhatsApp is optional. |
| Watches cost money while nothing happens | Always-on checking eats the budget | Cheap compare first, model only on change (§0 #90, #106); a watch counts to its creator. |
| Splitting `ChatView.vue` breaks the chat | The main screen of the product | PJ29 is a refactor with no visible change, behind the existing E2E suite. |
| Scope doubles | Five half-done features are worse than what Claude has | One phase at a time, each with its own flag and gate; C must stand alone. |

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
- Buying anything automatically (§0 #87).
- A model picker per project (§0 #86).
- A board, card or page that the AI writes as HTML (§0 #80).
- Free placement of the board's cards; a switch to swap chat and board.
- The person's whole calendar inside a project (§0 #88).
- Writing into or reading from anyone's calendar in v1 (§0 #103: a
  subscription only).
- Scraping shops or classified sites (§0 #106).
- Reading private WhatsApp chats (there is no API for it).
- Sending a mail without the person's click.

## 9. Later

- The same loop for agents in chat (through row 25), then Telegram and
  WhatsApp with a result message instead of a card.
- An allowance per project.
- Sources that refresh on their own (`UrlWatch` exists).
- A push with the chat title, encrypted for the device.
- Project memory that a member can edit, not only delete.
- Drive, SharePoint, Confluence scoping (row 40).
- WhatsApp and Telegram as channels for reminders and for forwarding into a
  project (§0 #84).
- Sending from Gmail and other mailboxes (a new provider, ask first).
- Guests without an account: view the board and tick tasks through a link.
- A voice note into the project from the phone (voice and transcription
  exist).
- A finished project as the starting point of the next ("like the last
  one") and a closing summary when a project is archived.
- Collections whose columns the AI chooses (flats: price, m², viewing) and
  budget against actual with receipt photos.
- A card the AI assembles from a small kit (list, number, table, history).
- The Notion page picker and narrowing apps (PJ18, PJ19), if §0 #49 is
  ticked.
- Writing project dates into Outlook or CalDAV and keeping them linked,
  and reading a calendar for clashes (old PJ37 and PJ39, §0 #103).
- Watches for prices across shops and for new classified ads (`number`,
  `entries`, `yesno`), where a source offers a feed or an API (§0 #106).

## 10. Change log

| Date | Change |
| ---- | ------ |
| 2026-11-16 | First version, from the owner's sessions of 2026-11-12 and 2026-11-13 and a code map on `0d228e323`. Plan only. |
| 2026-11-26 | Reshaped after the owner's whiteboard session and the mockup (`mockups/project-page.html`, #3282): goals 6–8; §0 #74–#95; #5, #17, #49, #54, #63, #64, #66 changed; phases relettered (C board, D remind and write back with push, E shared chats, F shared projects, G into the project, H watches, I rollout); steps PJ29–PJ47; PJ21 and PJ22 move into C; journeys J-PJ-12–J-PJ-18; gates G14–G19. `01`–`04` follow. |
| 2026-11-26 | Review of the reshaped plan: §0 #96–#111 (proposals; #97, #102, #107 and #111 need the owner). The board is filled by an extraction after the answer instead of loop tools, so C does not wait for PJ11; four item kinds; memory and board split; private items; quick-add; fewer tabs; calendar as a subscription; copy and mail app before "Send"; DMARC for forwarded mail; watches `version` and `page` only; push moves to G after the shared phases (G → H, H → I, I → J); #95 changed; journeys J-PJ-19, J-PJ-20; gates G20–G22. `02`–`04` follow. |
| 2026-11-19 | After PJ4 (`89694c4b2`): §0 #21–#25 ticked; #63 partly; #66 order fixed (AU26 → AH8a → AH8b → PJ5); new rows #68–#73 (end of a run, limits as profile input, budget per call until PJ6, no write share for project chats before PJ20, deleted member's text, projects flag with PJ28). `03` and `04` follow. |
