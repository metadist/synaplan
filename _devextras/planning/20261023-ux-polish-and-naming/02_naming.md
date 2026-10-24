# Naming — agent or assistant

**Question (owner, 2026-10-23):** "We call Agents 'Assistants', as far as I
can see. That is confusing in itself. Can you check if that is true?"

**Answer: true, and it is worse than one word for one thing.** The object
people build, publish and share is an *agent* in the code, the API, the
feature flag, the Desktop app and the website, and an *assistant* in the
web app and the docs. Inside the web app, "assistant" has four meanings
and "agent" has five, and in one list the two words are swapped.

The split was a decision, not an accident: Agent Builder §0 row 1
(2026-09-03, archived in
[`2026-archive/20260903_agent_builder/`](../2026-archive/20260903_agent_builder/00_master_plan.md))
chose "User-facing word: Assistant. Code name `agent`", to match the
glossary line "AI assistant — the AI that answers inside a widget" and the
existing nav group. Seven weeks later the object reacts to mail, schedules,
web hooks and coding tools, uses tools and skills, and is sold on the
website as an agent. The reason for the choice no longer holds.

## 1. One object, two names across surfaces

| Surface | Word | Evidence |
| ------- | ---- | -------- |
| Database, code, API, flag | **agent** | `BAGENTS`, `BAGENTVERSIONS`, `App\Entity\Agent`, `AgentService`, `/api/v1/agents`, `AGENTS.ENABLED`, IAM kind `agent` |
| External contracts | **assistant** | OpenAI-compatible model alias `assistant:<slug>`, MCP tool `list_assistants` (`AssistantAliasResolver`) |
| Web app | **Assistant** | Rail, sidebar head, group label and page "Assistants" (`nav.assistants`, `nav.groupAssistants`); route `/ai/assistants`; "Create assistant"; search group "AI assistants" |
| Web app, People → Groups → "Shared with this group" | **Agent** | `admin.json` `people.groups.resourceKind.agent: "Agent"` — this row *is* the assistant |
| Desktop | **Agents** | Rail item "Agents"; the page says "Assistants come from your Synaplan workspace. Skills run on this computer."; `assistant_not_bound`: "This thread's **Assistant** is no longer used in this project. Pick another under **Agents**." |
| Website (`synaplan-website`) | **Agents** (72× vs 12× assistant) | Badge "AI Agents"; "Give every business process its own agent"; "Sales Agent", "Support Agent", "HR Agent"…; page title "Self-Hosted Agents & Chatbot" |
| Docs (`synaplan-docs`) | **Assistants** | `docs/assistants.md`: "An **assistant** is a saved recipe…" |

A person who reads the website, signs up and installs Desktop meets three
names for the one thing they came for.

## 2. "Assistant" in the web app — four meanings

| # | Meaning | Examples (en) |
| - | ------- | ------------- |
| a | The built, published object (`BAGENTS`) | Assistants page; "An assistant is an AI with its own instructions, files and tools"; "Use your assistants inside Nextcloud" |
| b | The AI that answers, in general | "Put an AI **assistant** on your website" (Apps); "Answer and summarise mail with your **assistant** in Outlook"; "The **assistant** can use files marked searchable" (Library banner); glossary in `AGENTS.md`: "AI assistant — the AI that answers inside a widget" |
| c | The guided widget setup chat | "AI Setup Assistant" (41 strings in `widgets.json`) |
| d | **Instruction prompts (`BPROMPTS`) in the sharing list** | IAM kind `assistant` is bound to `BPROMPTS` (`AssistantKind.php`, settled in Agent Builder S3.5); People → Groups labels it "AI assistant". The topic editor shares with `kind="assistant"` (`TaskPromptsConfiguration.vue:423`). |

Row d is a bug in plain sight: an administrator who looks at what a group
can use sees "AI assistant" for a *topic* and "Agent" for an *assistant*.

## 3. "Agent" in the web app — five meanings

| # | Meaning | Examples (en) | Visible? |
| - | ------- | ------------- | -------- |
| a | The built object, in the sharing list | `resourceKind.agent: "Agent"` | yes |
| b | **Tools** | Topic editor: "**Available Agents**" over Internet search, Files search, URL content, MCP data sources (`config.taskPrompts.availableTools`, used in `TaskPromptsConfiguration.vue:613`). Also `chat.tools: "Agents"` and `widgets…availableTools: "Available Agents"`, which no component renders any more | yes / dead keys |
| c | **A human** in the widget's live support | "Support Agent", "You are now connected with a support agent", "A human agent has been notified" (de: *Support-Mitarbeiter*, fr: *Agent de support*) | yes, to website visitors |
| d | Coding tools such as Claude Code | "Connect Claude Code and other Anthropic-compatible agent tools"; settings "apply to every agent tool" | yes |
| e | Technical | "Browser metadata (user agent, …)"; "this email agent" in the mailbox debug log | yes, harmless for (user agent) |

Row c matters most once the object is called an agent: a visitor told "you
are now connected with a support agent" can no longer tell whether a
person or an AI took over.

## 4. The instruction object has four names too

`BPROMPTS` (the system prompt + model + knowledge that routing picks) is
called **Topic** (AI settings tab, "New topic", the editor, search),
**Instruction** (import: `kind.prompts: "Instructions"`; "A Saved Task runs
this instruction"; docs: "the older Instructions page"), **Prompt**
("Deleting this prompt", "this prompt's knowledge base", widget "Customize
Prompt") and **AI assistant** (sharing, §2 d). "Shortcuts" (`/prompts`) is a
different object (saved prompt snippets) and keeps its name.

## 5. Options

**Decided 2026-10-23 by the owner: option A** (master plan §0 D1). It
supersedes agent handling §0 #3.

### A — "Agent" everywhere for the built object (chosen)

- Web app, docs and Desktop say **agent** for `BAGENTS`, matching the
  code, the API, the flag, Desktop's rail and the website. Desktop's mixed
  sentence becomes "This thread's agent…".
- **"Assistant" stops being a name for anything people build.** The AI that
  answers in general is "the AI" (or "{brand} AI", a key that already
  exists: `core.json` `"assistant": "{brand} AI"`). The widget glossary line
  in `AGENTS.md` changes accordingly.
- The guided widget setup keeps its product name "AI Setup Assistant"
  (§0 D3); it is the one meaning of "assistant" left, and it is a helper,
  not a thing people own.
- A person in live support is never an "agent" (§0 D4).
- Routes, API, database, flag, IAM keys, testids and the external aliases
  stay (§0 D7).

Why: it is the word the market, the website, Desktop and the code already
use; the object does what people now expect an agent to do (tools,
triggers, schedules, unattended runs); and it frees "assistant" from four
meanings. Cost: about 190 English strings and their four translations, the
nav, one route redirect, the UX contract §2, `AGENTS.md`, the docs page,
one Desktop string — and every e2e spec that matches on visible text.

### B — keep "Assistant", fix the rest

The web app keeps "Assistant"; Desktop renames its rail item to
"Assistants & skills"; the website rewrites "AI Agents" to assistants;
§2 d, §3 b and §3 c are fixed as in A. Less churn in the web app, more on
the website, and the market word is given up. "Assistant" still means both
the object and the AI in general (§2 a and b) unless every generic use is
rewritten too — which is most of A's work without A's result.

### C — fix only the collisions

Fix §2 d, §3 b, §3 c and §4; leave "Assistant" in the web app and "Agent"
everywhere else. Smallest change; the three-names problem of §1 stays.

**Same-day conflict.** The agent handling plan
([`../20261023-agent-handling/`](../20261023-agent-handling/00_master_plan.md),
row 25) chooses B in its §0 #3 and adds Run, Helper, Notes, Hint and
Needs you as new user words for the same object. Its words work under
either option ("Runs", "Notes"); only "helper assistant" becomes "helper
agent". The choice of A, B or C is one decision for both plans — taken on
2026-10-23: A.

**Steps UP6 and the IA part of UP7 are needed under every option.** UP6
(the swapped sharing labels, "Available Agents", the human "agent", the
four names of the topic) ships first and does not wait for the decision.

## 6. Proposed glossary (option A)

Native-speaker review before UP7 merges, as for the research copy. German
uses *du*, as the existing web strings.

| Concept | en | de | es | fr | tr |
| ------- | -- | -- | -- | -- | -- |
| The built object | agent | Agent (pl. Agenten) | agente | agent | ajan (pl. ajanlar) |
| Rail item / page | Agents | Agenten | Agentes | Agents | Ajanlar |
| Create button | Create agent | Agent erstellen | Crear agente | Créer un agent | Ajan oluştur |
| The AI in general | the AI / {brand} AI | die KI | la IA | l'IA | yapay zekâ |
| Widget setup helper | AI Setup Assistant | KI-Setup-Assistent | Asistente de Configuración IA | Assistant de configuration IA | AI Kurulum Asistanı |
| Human in live support | a person from our team · label "Team" | jemand aus unserem Team · „Team" | una persona de nuestro equipo · «Equipo» | une personne de notre équipe · « Équipe » | ekibimizden biri · "Ekip" |
| `BPROMPTS` | topic | Thema | tema | sujet | konu |
| Tools a topic or agent may use | tools | Werkzeuge | herramientas | outils | araçlar |
| Coding clients | coding tools | Coding-Tools | herramientas de programación | outils de développement | kodlama araçları |

Turkish already labels the sharing kind "Ajan"; German *Agent* is the
established term in German AI products ("KI-Agent").

## 7. Where each word changes (option A)

| Place | Change |
| ----- | ------ |
| `frontend/src/i18n/locales/*/assistants.json` | Namespace keeps its file name (`NAMESPACE_KEYS`); values say agent |
| `core.json` `nav.*`, `pageTitles.*`, search groups and keywords | Agents; search keywords keep "assistant" as a synonym so Ctrl+K still finds it |
| `tools.json`, `widgets.json`, `files.json`, `chat.json`, `auth.json`, `admin.json` | Every meaning-a string → agent; every meaning-b string → "the AI" or a rewrite that names the job |
| Router | `/ai/agents` and `/ai/agents/:id`; `/ai/assistants*` redirect (≥ 2 releases, as the 2026-06 tree) |
| `docs/UX_CONTRACT.md` §2 | Rail: Chats · Library · Agents · Apps · Admin |
| Root `AGENTS.md` | Glossary: "AI assistant" entry replaced by "agent"; "AI Setup Assistant" stays |
| `synaplan-docs` | `assistants.md` → `agents.md` with a redirect; the intro and FAQ |
| `synaplan-desktop` | `assistant_not_bound` and any other "Assistant" string |
| Not changed | Table and class names, `/api/v1/agents`, `AGENTS.ENABLED`, IAM keys, `assistant:<slug>`, `list_assistants`, testids such as `btn-sidebar-v2-nav-assistants` |
