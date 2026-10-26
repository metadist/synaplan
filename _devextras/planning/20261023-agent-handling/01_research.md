# 01 — Research: Hermes Agent, omp, and what Synaplan has today

Written 2026-10-23 from the public documentation and repositories of both
products and a read-only code map of `main`. Every claim about Hermes or omp
links to its source; every claim about Synaplan names a file.

---

## 1. The two products in one paragraph each

**Hermes Agent** (Nous Research, MIT, Python, about 256,000 GitHub stars on
2026-10-23, [repository](https://github.com/NousResearch/hermes-agent),
[docs](https://hermes-agent.nousresearch.com/docs/)) is a **personal agent
that lives on one computer or server and talks to you everywhere**: a CLI, a
desktop app, a web dashboard and a gateway into 20+ messaging platforms
(Telegram, WhatsApp, Slack, Teams, Signal, e-mail, …). Its selling point is a
*learning loop*: it keeps a small curated memory about you and its
environment, writes its own skills when it figures out a workflow, searches
its past sessions, delegates to sub-agents, runs scheduled jobs that deliver
to any platform, and coordinates a roster of named specialist agents on a
shared task board. Its threat model is a trusted single user on their own
machine ("kanban is single-host by design", "the trusted-local-user threat
model", [Kanban](https://hermes-agent.nousresearch.com/docs/user-guide/features/kanban)).

**omp — "oh-my-pi"** (Stencil Labs, MIT, TypeScript on Bun with an ~80,000
line Rust core, about 36,000 stars, [repository](https://github.com/can1357/oh-my-pi),
[site](https://omp.sh)) is a **terminal coding agent with the IDE wired in**,
a fork of Mario Zechner's Pi. Its selling point is *tool quality*: edits by
content hash, language-server renames and diagnostics, a real debugger,
in-process search and shell, parallel sub-agents in isolated git worktrees
that return schema-checked results, an **Agent Hub** to watch and steer every
worker, a second "advisor" model that reviews each turn, and model roles that
route cheap work to cheap models. It is a developer tool; it runs with your
shell and your files.

They overlap with Synaplan in the parts that are not code- or shell-specific:
agents with roles, delegation, a live view of running work, memory, skills,
schedules, approvals, many channels, many providers.

---

## 2. Hermes Agent — what a user gets

| Area | What it offers | Source |
| ---- | -------------- | ------ |
| **Learning loop** | Agent-curated memory with periodic nudges, autonomous skill creation, skill self-improvement during use, full-text search over past sessions with LLM summaries, optional user modelling (Honcho) | [Docs home](https://hermes-agent.nousresearch.com/docs/) |
| **Memory** | Two bounded files injected at session start: `MEMORY.md` (agent's notes, 2,200 characters) and `USER.md` (your profile, 1,375 characters). The agent edits them through a `memory` tool. One agent per profile; "two writers sharing one home will compound each other's entries". External memory providers as plugins | [Persistent Memory](https://hermes-agent.nousresearch.com/docs/user-guide/features/memory) |
| **Skills** | Agent Skills format (agentskills.io). Progressive disclosure: `skills_list()` (~3k tokens) → `skill_view(name)` → `skill_view(name, path)`. The agent creates, updates and deletes its own skills with `skill_manage` "when it figured out a multi-step workflow", "hit errors and found the working path" or "the user corrected its approach". A background review can *stage* skill changes; a write-approval gate can require human review. Skills Hub for sharing | [Skills System](https://hermes-agent.nousresearch.com/docs/user-guide/features/skills) |
| **Context files** | Reads `AGENTS.md`, `CLAUDE.md`, `.cursorrules`, `SOUL.md` (persona); `@` references inject files, folders, URLs | [Features](https://hermes-agent.nousresearch.com/docs/user-guide/features/overview) |
| **Delegation** | `delegate_task` spawns child agents with isolated context, inherited (never widened) toolsets, their own iteration and time limits, structured output via `output_schema`, background completion delivery, stall detection, `/agents` monitor and steering of a running child. "Frontier planner, inexpensive workers" as a documented cost strategy; nested orchestrators bounded by `max_spawn_depth` | [Delegation](https://hermes-agent.nousresearch.com/docs/user-guide/features/delegation) |
| **Kanban** | A durable board shared by all profiles: Triage → Todo → Ready → In progress → Blocked → Done. A dispatcher claims tasks, spawns the assigned named agent as its own process, reclaims crashed workers, auto-blocks after repeated failures. Comments are the inter-agent protocol; humans unblock and comment. A decomposer turns a triage card into a graph of child cards routed to the best-fit specialist. Boards per project, attachments, PR completion contracts that check CI | [Kanban](https://hermes-agent.nousresearch.com/docs/user-guide/features/kanban), [tutorial](https://hermes-agent.nousresearch.com/docs/user-guide/features/kanban-tutorial) |
| **Bot Mode** (desktop app) | A roster of named bots (one per profile) with avatar, title, persona (`SOUL.md`), pinned model, skills and toolsets; "New Agent" in seconds (name, title, description); duplicate; groups; **group rooms** of 2–6 bots answering in up to three rounds with hard caps; `@mention` hand-off; `message_agent` between bots with attribution; per-bot **Routines** (recurring tasks); an "Active now" presence strip | [Bot Mode](https://hermes-agent.nousresearch.com/docs/user-guide/bot-mode), [archived plugin](https://github.com/NousResearch/Hermes-Bot-Mode) |
| **Schedules** | Built-in cron in natural language or cron syntax; jobs attach skills and deliver to any platform; pause, resume, edit | [Features](https://hermes-agent.nousresearch.com/docs/user-guide/features/overview) |
| **Channels** | One gateway, 20+ platforms, per-platform toolsets, DM pairing and allowlists | [Gateway internals](https://hermes-agent.nousresearch.com/docs/developer-guide/gateway-internals) |
| **Safety** | Eight layers; dangerous-command approval `smart` (a helper model auto-approves low-risk, denies dangerous, asks when unsure) · `manual` · `off`; headless surfaces (cron, webhook, API) **deny** by default instead of waiting; file-write denylist; container backends; prompt-injection scanning of context files; MCP credential filtering | [Security](https://hermes-agent.nousresearch.com/docs/user-guide/security) |
| **Undo** | Automatic checkpoints of the working directory before file changes; `/rollback` | [Features](https://hermes-agent.nousresearch.com/docs/user-guide/features/overview) |
| **API server** | OpenAI-compatible `/v1/chat/completions` and `/v1/responses` with tool progress events, plus a **Runs API**: `POST /v1/runs`, `GET /v1/runs/{id}`, `GET /v1/runs/{id}/events` (SSE: `tool.started`, `tool.completed` with redacted previews), `POST …/stop`, `…/approval`, `…/clarify`; idempotency keys; a run never survives a restart as `running` (it becomes `interrupted`); a budget-exhausted turn still answers with a wrap-up (`finish_reason: "length"`); a Jobs API for schedules | [API Server](https://hermes-agent.nousresearch.com/docs/user-guide/features/api-server) |
| **Models** | Any OpenAI-compatible endpoint, provider routing, fallback providers, credential pools, prompt caching | [Features](https://hermes-agent.nousresearch.com/docs/user-guide/features/overview) |
| **Other** | MCP client, ACP for editors, browser automation, voice and wake word, image generation, plugins (tools, hooks, memory providers, context engines), batch runs and trajectory export for training | same |

**What users praise, read from the docs' own framing:** it remembers, it gets
better at your recurring jobs, it reaches you where you already are, and you
can give it a *team* that keeps working while you are away. **What it costs
them:** one machine, one trusted user, a large configuration surface
(`config.yaml`, profiles, toolsets, boards, tenants), and safety that is
"best-effort pattern matching" on shell commands
([custom-dangerous-patterns](https://hermes-agent.nousresearch.com/docs/plugins/custom-dangerous-patterns)).

---

## 3. omp — what a user gets

| Area | What it offers | Source |
| ---- | -------------- | ------ |
| **Sub-agents** | `task` fans out workers in parallel, optionally each in an isolated git worktree; each worker has its own tool surface; the result is a schema-validated object the parent reads directly ("no prose to parse") | [README §05](https://github.com/can1357/oh-my-pi), [Subagents](https://omp.sh/docs/subagents) |
| **Specialists** | Bundled: `scout` (fast read-only exploration), `designer`, `reviewer`, `security-reviewer`, `librarian` (verified external research), `task` (general), `sonic` (mechanical edits). Custom ones are Markdown files in `.omp/agents/*.md` with front matter: model, tools, spawns. "An explicit `tools` list is a capability boundary"; omitted tools inherit the parent's | [Subagents](https://omp.sh/docs/subagents), [Authoring](https://omp.sh/docs/subagent-authoring) |
| **Agent Hub** | `Alt+A`: a roster with current activity and usage of every sub-agent; open one to read its live transcript, type a steering message, revive a parked worker or kill a stuck one without aborting the parent | [README §05](https://github.com/can1357/oh-my-pi) |
| **Advisor** | A second model on its own context reads every turn and injects notes (aside, concern, blocker); the main agent course-corrects or says why not | README §06 |
| **Model roles** | Nine roles: `default`, `smol` (cheap fan-out), `slow` (deep reasoning), `plan`, `commit`, `vision`, `task`, `advisor`, `tiny`; fallback chains per role on 429 / quota; path-scoped model sets; round-robin keys | README "Sixty-plus providers" |
| **Asking** | An `ask` tool: a structured option picker the agent calls mid-turn | README "Interactive" |
| **Preview, then accept** | Structural edits are staged as a *proposed* card; an **Accept** card applies them atomically | README §19 |
| **Review** | `/review` spawns reviewer sub-agents; every issue ranked P0–P3 with a confidence and a verdict | README §10 |
| **Rules that wake up** | "Time-traveling stream rules": a regex match aborts the stream mid-token, injects the rule and retries — no context cost on every turn | README §04 |
| **Memory and skills** | `retain`, `recall`, `reflect`, `memory_edit`, `learn` (capture a lesson, optionally promote it to a managed skill), `manage_skill`; project-scoped by default | README §13 |
| **Share a session** | `/collab` gives a link and QR code; read-write pairing or read-only watching; frames sealed client-side | README §07 |
| **Inherits config** | Reads rules, skills and MCP servers from `.claude`, `.cursor`, `.codex`, `.cline`, `.github/copilot`, … without migration | README §15 |
| **Entry points** | TUI, one-shot `-p`, a Node SDK, an NDJSON RPC mode over stdio, and ACP for editors | README "Four entry points" |
| **Providers** | 60+ including any OpenAI-compatible endpoint declared in `~/.omp/agent/models.yml` (`baseUrl`, `api: openai-completions` or `anthropic-messages`, `apiKey`, models) | README "Custom OpenAI-compatible providers" |
| **Coding tools** | Hashline edits, LSP (14 operations), DAP debugger (28), ast-grep, in-process ripgrep and bash with 58 utilities, browser and desktop control, `web_search` over 23 providers | README |

**What users value:** it does the job right the first time (edit success
rates are its headline benchmark), and parallel work stays *visible and
steerable*. **What it is not:** a multi-user product, a channel gateway, or
safe without trusting the person at the keyboard — it has a shell.

---

## 4. The shared standards

| Standard | State on 2026-10-23 | Who uses it | Relevance |
| -------- | ------------------- | ----------- | --------- |
| **Agent Skills** (`SKILL.md` folder: YAML front matter `name`, `description`, optional `license`, `compatibility`, `metadata`, experimental `allowed-tools`; optional `scripts/`, `references/`, `assets/`; progressive disclosure, body under 500 lines recommended) | Donated by Anthropic to the Agentic AI Foundation on 2026-10-22; "more than 70 agent platforms", about 1 million skills published ([spec](https://agentskills.io/specification), [announcement](https://www.anthropic.com/news/donating-agent-skills-to-the-agentic-ai-foundation)) | Hermes, omp, Claude, ChatGPT, Cursor, Copilot, Codex, Gemini CLI — and **Synaplan Desktop** (`synaplan-desktop/skills/bundled/*/SKILL.md`, 14 skills) | The skill format to adopt on the server: zero invention, instant catalogue |
| **MCP** | Synaplan is both client (`McpClient`, per person) and server (`/mcp`, `McpServerFactory`); row 18 adds install-wide servers | Both products are MCP clients | Hermes and omp can use Synaplan's knowledge, memories and assistants as tools today |
| **ACP** (Agent Client Protocol) | Stable v1 over stdio; a streamable HTTP / WebSocket transport is an RFD, not final ([RFD](https://agentclientprotocol.com/rfds/streamable-http-websocket-transport)) | Hermes, omp, Zed, JetBrains, VS Code | Later: a remote ACP endpoint would let editors drive a Synaplan assistant. Wait for the transport to be final |
| **OpenAI-compatible / Anthropic Messages** | Synaplan serves both (`OpenAICompatibleController`, `MessagesGateway`) | Both products speak both | Synaplan as their model provider works today |

---

## 5. What Synaplan has today (code map, `main` on 2026-10-23)

Full map in STATUS (2026-10-23 entry). The short version:

| Area | State | Where |
| ---- | ----- | ----- |
| **Assistants** (code name *agent*) | Versioned, publishable, shareable (IAM `agent` kind), clonable, exportable (`synaplan-bundle.v1`), plugin packs, system assistants. Definition `agent.v1`: models (chat, vision, vectorize), knowledge, tools (internet, files, MCP servers, allow/deny), skills (= planner *capabilities*, allow/deny), parameters, behaviour (greeting, starters, `memory: "user"` only), triggers (events: mail, WhatsApp, widget, API, MCP, Desktop, web hook; schedules) | `Entity/Agent.php`, `AgentVersion.php`, `DTO/AgentDefinitionV1.php`, `Service/Agent/*`, `AgentController.php` (921) |
| Builder | A form of seven sections plus a draft test chat; "Help me write" (AB14) was deferred | `frontend/src/components/assistants/*`, `views/AssistantsView.vue` |
| Where assistants answer | Web chat (pinned), widget, WhatsApp (one per user), mail departments and mail rules, `/v1` as model alias `assistant:<slug>`, MCP `synaplan_chat` / `list_assistants`, Desktop. **Not Telegram** | `WidgetAgentRuntime`, `WhatsAppAgentBinding`, `InboundEmailHandlerService`, `AssistantAliasResolver` |
| How work happens in web chat | A **one-shot planner**: `TaskPlanner` plans up to 24 steps once (16 in a saved graph), `DagExecutor` runs them in order (media steps optionally in parallel, `MAX_PARALLEL` 3, 120 s per step); a failed step skips its dependents. No reflection, no re-planning. Only document tools run in a model-driven loop (`ChatToolLoop`, 8 rounds, 240 s) | `Service/Multitask/*`, 23 runners |
| Model-driven tool loops | Two gateway loops (`GatewayToolLoop` 1,431 lines, `OpenAiGatewayToolLoop` 626), 8 rounds / 240 s; Desktop's own loop (40 rounds) | architecture update S4 unifies them (AU24–AU26) |
| Saved Tasks | Triggers manual, chat, schedule (≥ 15 min), inbound mail, web hook; runs as the owner; auto-pause after 3 failures; resume after approval. **A running run cannot be stopped** — `SavedTaskRun::STATUS_CANCELLED` is defined and never set, and no cancel route exists | `Entity/SavedTask*.php`, `Service/SavedTask/*`, `SavedTaskController.php` (676) |
| Approvals | Gate + policy (most restrictive wins), inbox `/approvals`, chat card, expiry, digest, audit for approval events | `ToolExecutionGate`, `ApprovalPolicy`, `ApprovalService`, `ApprovalsInbox.vue` |
| Memory | Per person, 500 entries, Qdrant `user_memories`, automatic extraction, `[Memory:ID]` badges. Rows carry a `BNAMESPACE` (used today for hidden feedback namespaces). **No per-assistant memory** | `UserMemoryService.php` (1,022), `Entity/UserMemory.php` |
| Skills (`SKILL.md`) | **Desktop only** (bundled + installed by hand, no shell, `run_program` with an allowlist). The server has no `SKILL.md` support | `synaplan-desktop/src-tauri/synaplan-core/src/skills.rs` |
| Live view of running work | Per-step cards in the chat (`TaskCard.vue`, per-step Stop), a processing timeline, "What was sent". **No page that lists running work**; Saved Task runs are an on-demand list with no realtime push | `components/multitask/*`, `components/chat/ProcessingTimeline.vue` |
| Delegation / teams | **None.** "Agents calling agents" was out of scope for the builder (archived plan §non-goals) | — |
| Copy collision | The builder already calls planner capabilities **Fähigkeiten / skills** ("Was es tun darf", `assistants.json` → `skill.*`) | `frontend/src/i18n/locales/*/assistants.json` |

---

## 6. Side by side

| Capability | Hermes | omp | Synaplan today | Gap size |
| ---------- | ------ | --- | -------------- | -------- |
| Named agents with persona, model, tools | Profiles + Bot Mode | `.omp/agents/*.md` | **Assistants — stronger**: versions, publish, share, clone, export | none |
| Many channels | 20+ | — | Web, widget, WhatsApp, mail, API, MCP, Desktop; Telegram without assistants | small |
| Schedules and events | cron | — | Saved Tasks + assistant triggers | none |
| Approvals for writes | smart / manual / off; headless deny | permission prompts (ACP) | **Stronger**: policy per group / assistant, inbox, expiry, audit | none |
| Multi-user, organisation, sovereignty | — (single trusted user) | — | **Unique**: IAM, OIDC, world rule, EU / local models, budgets | — |
| Model-driven work loop with reflection | yes | yes | one-shot plan; loops only on the gateways and Desktop | **large** |
| Durable run you can leave and come back to | Runs API, Kanban | sessions | chat run buffer (30 min), Saved Task runs | medium |
| Live view: what is running, steer, stop | `/agents`, Kanban, Active now | **Agent Hub** | step cards in one chat; nothing across chats | **large** |
| Stop a scheduled run | yes | yes | **no** | small, urgent |
| Delegation to helpers | `delegate_task`, `message_agent` | `task` with typed results | none | **large** |
| Cheap / strong model split per job | auxiliary models, "frontier planner, inexpensive workers" | nine roles | slots exist server-wide (SORT, PLAN, CHAT, ANALYZE); assistants pick only chat / vision / vectorize | medium |
| Second model checks the work | smart approvals (helper model) | **advisor** | none (research plan adds VERIFY for research only) | medium |
| Memory about the work | `MEMORY.md` / `USER.md` | retain / recall | per-person memories, not per assistant | medium |
| Skills in the open format | yes, + self-written, + hub | yes, + `learn` | Desktop only | **large** |
| Self-improvement (proposes skills, with review) | yes | yes | none | medium |
| Create an agent in seconds | "New Agent": name, title, description | Markdown file | seven-section form; helper deferred | medium |
| Group rooms of agents | 2–6 bots, three rounds | IRC-style peer messages | none | later |
| Task board | Kanban | todo tool | Saved Task card list | later |
| Undo | checkpoints, `/rollback` | worktree isolation, accept card | document revisions (10), "already done stays done" | small |

**Reading of the table.** Synaplan already wins on everything an
organisation needs around an agent — definition, publishing, sharing,
channels, approvals, sovereignty, budgets. It loses on everything that
happens *while the agent works*: a loop that reflects, helpers it can hand
work to, a place where you see and steer the work, memory and skills that
make the next run better. That is exactly the layer the two in-flight
overhauls are preparing: one tool path and one agent loop (architecture
update S4), long waits out of PHP (S5), and a server-side run with units,
claims, a reaper and an event log (research quality Phase C).

---

## 7. Can we use their agents?

Four ways, evaluated against the house rules (one AI path, one tool path,
IAM, approvals, metering, sovereignty, "no shell", public repository).

| Option | What it means | Effort | Verdict |
| ------ | ------------- | ------ | ------- |
| **A. They use Synaplan** | A person points Hermes or omp at Synaplan as model provider (`/v1/chat/completions`, `/v1/messages`, `assistant:<slug>` aliases) and as MCP server (`/mcp`: knowledge search, memories, files, `synaplan_chat` with an assistant). Works today with a Synaplan key; needs a conformance check and two pages of docs | days | **Yes, first** (AH1–AH2). Costs almost nothing, and developers who like these clients get Synaplan's knowledge and models |
| **B. Embed Hermes or omp as Synaplan's agent engine** | Run their process per user or per run on the server, behind Synaplan's UI | months, and permanently | **No.** Both assume one trusted person with a shell and their own files. Running them for many users means a container per user, their own tool loop beside AU26's, their own approval semantics beside `ApprovalPolicy`, their own metering beside `UsageHook`, their own memory files beside `UserMemoryService`, and Python / Bun runtimes in the image. Every one of those breaks a decision of the architecture update (one AI path, one tool path, central egress) and the sovereignty promise (Hermes calls tools and providers itself) |
| **C. Connect a person's own Hermes as an "outside assistant"** | Someone already runs Hermes on their machine or server. Synaplan talks to its Runs API (`POST /v1/runs`, events, stop, approval, clarify) and shows it as an assistant card; runs appear on the Runs page; its approval requests land in the Approvals inbox | weeks | **Later, behind a spike** (§9 #1). Attractive for power users, but it routes data to a process Synaplan does not govern; the world rule must call it "outside" honestly, and the Runs API contract is Hermes-specific |
| **D. Adopt their patterns natively** | Build the agent layer on Synaplan's own pipeline: a run with a reflective loop, helpers, a live Runs page, stop and steer, notes, Agent Skills, proposed improvements, a second-model check, a faster builder | the plan | **Yes, the core of this track.** Both projects are MIT, their documentation is public, and the formats (Agent Skills, MCP) are open standards — we copy ideas and formats, not code |

**So: is it easy?** Option A is easy and ships first. Option D is
medium-sized *because the expensive foundations are already planned*: the
agent loop is AU26, the run kernel is research quality's RS6, the metering is
AU11, the long-wait runtime is S5. This track adds the product layer on top —
roughly 20 pull requests, most of them small (§5 of the master plan).

---

## 8. Patterns to adopt, patterns to avoid

**Adopt**

1. **A run is a first-class object** with a terminal state that is never
   "running" after a restart (Hermes `interrupted`, budget-exhausted runs still
   answer with a wrap-up).
2. **One place shows all running work**, with live activity, steer and stop
   per item (omp Agent Hub, Hermes `/agents`, Bot Mode "Active now").
3. **Delegation with typed results and a capability boundary**: a helper
   never gets more than the caller; results are structured, not prose (omp
   `task`, Hermes `delegate_task` inherited toolsets, `output_schema`).
4. **Specialists as small definitions** with a one-line role and a tool list
   (omp bundled specialists) — Synaplan's assistants already are this.
5. **Cheap workers, strong lead** as the default cost shape (both).
6. **Bounded, visible memory per agent** (Hermes 2,200 / 1,375 characters),
   edited by the agent, readable by the person.
7. **Skills in the open format, loaded progressively**, and **self-written
   skills as reviewed proposals** (Hermes write-approval gate, omp `learn`).
8. **Headless means deny, not wait** (Hermes `unattended_mode: deny`) —
   Synaplan's `allowUnattended` already says this; keep it.
9. **A second model that reviews** the work, on its own context (omp advisor).
10. **Create an agent from a sentence** (Hermes "New Agent": name, title,
    description).

**Avoid**

1. **A configuration surface for developers** (profiles, toolsets, boards,
   tenants, `config.yaml`). Synaplan's bar is "first run without help"; every
   knob above goes behind a sensible default or does not exist.
2. **Free-running agent rooms** without a person's goal (group rooms with
   rounds) — fun, costly, and hard to make honest. Later, if at all.
3. **Shared memory written by many people** — Hermes warns about two writers;
   in an organisation that is a privacy leak. Assistant-wide notes are written
   only by the owner (or proposed and accepted).
4. **Shell-pattern safety.** Synaplan has no server shell and keeps it that
   way; programs run only in Secure Compute or on Desktop under its allowlist.
5. **A second tool loop.** Everything runs through `ToolInvoker` (AU24).
