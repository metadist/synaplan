# Agent Builder — reusable, published assistants — master plan

**Status:** Decisions ticked 2026-09-03 (log in [`STATUS.md`](./STATUS.md)).
Track 2 of [`../20260903_roadmap.md`](../20260903_roadmap.md).
Depends on track 1 (IAM) S1–S2 for publishing; S1–S2 of this track can start
before that with owner-only assistants.
Sprint files: [`01_sprint_1_entity_and_pinned_runtime.md`](./01_sprint_1_entity_and_pinned_runtime.md) …
[`06_sprint_6_portability_and_packs.md`](./06_sprint_6_portability_and_packs.md).
**This track also owns the cross-track export/import bundle** (roadmap §8).
**Owner surface:** Manage → Assistants (existing nav group). The builder
replaces the current **Instructions** page; the gallery is a new child.
**Flag:** `AGENTS.ENABLED` — default off in code and seeder.
**Related:**

- [`../20260816-saved-task-workflows/`](../20260816-saved-task-workflows/00_master_plan.md)
  — Saved Tasks (`BSAVEDTASKS.BPROMPTID`), "evolve Task Prompts, do not replace `BPROMPTS`"
- [`../20260822-open-plugin-platform/README.md`](../20260822-open-plugin-platform/README.md)
  §3.4 — prompt-pack skills; here: plugin-shipped assistant packs
- [`../202609_iam/00_master_plan.md`](../202609_iam/00_master_plan.md) — publishing = sharing
- [`../202609_tools_approval_workflows/00_master_plan.md`](../202609_tools_approval_workflows/00_master_plan.md)
  — tool allow-lists and approval policies attach to an assistant
- [`../202609_ux_user_flows.md`](../202609_ux_user_flows.md) — binding
  user-flow contract. Publish waits on **IAM-UX** so Share is
  professional before a department has to find an assistant. Journeys
  J-AB-1…6. Wireframe:
  [`../202609_ux_user_flows/assistant-publish.md`](../202609_ux_user_flows/assistant-publish.md).

---

## 0. Decision checklist (tick before any code)

| # | Decision | Proposed default | Agree? |
| - | -------- | ---------------- | ------ |
| 1 | **User-facing word: Assistant.** Code name `agent` (`BAGENTS`, `AgentService`, `/api/v1/agents`). Matches the glossary in `AGENTS.md` ("AI assistant — the AI that answers inside a widget") and the existing nav group **Assistants**. | Assistant / `agent` | ✅ 2026-09-03 |
| 2 | **The Messages-gateway page currently labelled "AI Agents" (`/channels/agents`) is renamed** to avoid the collision — proposed **"Coding clients"** (en) under Connections. Route unchanged. | Rename | ✅ 2026-09-03 |
| 3 | **An assistant composes; it does not replace `BPROMPTS`.** `BAGENTS.BPROMPTID` points at the instruction row; models, knowledge, tools, skills, parameters and triggers live in a versioned JSON definition (`agent.v1` schema). `BPROMPTMETA` stays the runtime store for widgets that are not migrated. | Compose | ✅ 2026-09-03 |
| 4 | **Versioned and publishable.** `BAGENTVERSIONS` holds immutable snapshots; users always run the latest *published* version; the owner edits a draft. "Update without rebuilding" = publish a new version. | Versions | ✅ 2026-09-03 |
| 5 | **Publishing = an IAM share** (`assistant` kind, permission `use`; `edit` for co-maintainers). "Assign to a department" = share with that group. No second permission model. | IAM only | ✅ 2026-09-03 |
| 6 | **Talking to an assistant pins it.** A chat started from an assistant carries `agentId`; `MessageClassifier` short-circuits exactly as it does today for a pinned `PROMPTID`. Assistants are **not** added to the sorter's topic list unless the owner opts in ("also let the router pick this"). | Pinned by default | ✅ 2026-09-03 |
| 7 | **Execution identity = the person talking.** Budget, rate limits, memories and files are the user's. The assistant's knowledge is the owner's shared folders (via IAM `use`), read-only for the user. Scheduled tasks created from an assistant run as the task owner (existing Saved Task rule). | User runs, owner's knowledge | ✅ 2026-09-03 |
| 8 | **Clone is a first-class action** (copy definition + prompt as a new draft owned by the cloner; lineage kept in `BPARENTID`). Cloning a shared assistant needs `read`. | Clone | ✅ 2026-09-03 |
| 9 | **Portable definitions — and the instance-to-instance bundle.** `agent.v1` is one section of a larger **`synaplan-bundle.v1`** archive this track defines in S6: assistants + instructions first; later tracks add their own sections (saved tasks / workflows, MCP server configs, custom tools, model preferences by catalog key, connections). **Secrets, credentials, API keys, file binaries and other users' ids are never exported**; import shows a "needs a key / needs a model" checklist. Every user exports/imports their own resources; admins additionally export/import instance-level settings. Plugin packs (`provides.agents`) use the same section format. | Bundle, no secrets, user + admin | ✅ 2026-09-03 |
| 10 | **The builder is a form, not a chat.** The existing AI Setup Assistant (`WidgetSetupService`, `tools:widget-setup-interview`) is reused as an optional "help me write this" helper inside the form, not as the primary editor. | Form first | ✅ 2026-09-03 |
| 11 | **Widgets and channels bind an assistant** (S5): `BWIDGETS.BAGENTID` (nullable) beside the existing `BTASKPROMPT` topic; a widget with an agent id ignores the topic. Email handler and WhatsApp bindings follow the same pattern. To the user these bindings are **event triggers** (row 15). | Additive binding | ✅ 2026-09-03 |
| 12 | **Schema (ask recorded):** `BAGENTS`, `BAGENTVERSIONS` (S1), `BWIDGETS.BAGENTID` (S5). Galera-safe `addSql`. | Ask recorded | ✅ 2026-09-03 |
| 13 | **Characterization discipline:** with the flag off or with no pinned assistant, `MessageClassifier` / `MessageSorter` output is unchanged; snapshots are not re-recorded by this track. | Locked | ✅ 2026-09-03 |
| 14 | **Mobile:** new PHP `backend-only`; builder + gallery `ota-candidate`. | Locked | ✅ 2026-09-03 |
| 15 | **Triggers replace "Tasks" and "Channels".** An assistant always answers a chat; everything else that starts it is a **trigger** of one of two categories: an **event** (something arrives — mail matching a rule, a WhatsApp message, a website-widget visitor, an app or coding tool via the API, a connected MCP app, Synaplan Desktop, a web hook) or a **schedule** (a point in time). One builder section, one row pattern, the Saved Tasks words (Trigger / Schedule / Runs on its own) plus **Event**. Under the hood nothing new: conversational events are the S5 bindings (`BWIDGETS.BAGENTID`, department `agentId`, `WHATSAPP.AGENTID`) and the S6 gateway/MCP exposure; schedules and unattended events (mail rule, web hook) are ordinary `BSAVEDTASKS` rows created on publish. `agent.v1` carries `triggers.events[]` / `triggers.schedules[]` instead of `tasks[]` / `channels`. | Trigger = event or schedule | ✅ 2026-09-07 |

---

## 1. The concept in three sentences

> An **assistant** is a saved recipe: what the AI should do (instructions),
> with which models, using which knowledge, tools and skills, with which
> settings. Whoever builds one can publish it to a group or to everyone, keep
> improving it, and every user always talks to the current version. Users
> can clone a published assistant and make it their own.

> It acts when somebody chats with it — or when a **trigger** fires: an
> **event** (a mail from a certain sender, a WhatsApp message, a website
> visitor, a call from an app) or a **schedule** (every Monday at 08:00).

---

## 2. Why this exists

Today a "prompt topic" (`/ai/instructions`) is the closest thing to an
assistant. It has a system prompt, one model binding, tool flags and (via a
file group key) knowledge — but:

- it belongs to one user or is a seeded system prompt; an admin cannot give
  a finished configuration to "Support" only;
- there is no version: editing changes the live prompt for everyone using it;
- tools, skills, parameters and schedules are configured in different places
  (prompt meta, MCP servers page, saved tasks, widget config);
- nothing is portable: a good assistant cannot be exported or shipped in a
  plugin.

The partner review called this "a catalog of purpose-built AI experiences
from the same underlying models". That is the goal.

---

## 3. What already exists (do not rebuild)

| Piece | State | Role here |
| ----- | ----- | --------- |
| `BPROMPTS` / `BPROMPTMETA`, `PromptService`, `PromptController` | Shipped | The **instruction** and today's runtime meta. Stays; the assistant references it |
| `MessageClassifier` pinned `PROMPTID` path | Shipped | Reused for `agentId` pinning — the classifier learns one more early return, nothing else |
| `tool_internet`, `tool_files`, `tool_mcp`, `mcp_servers` in prompt meta | Shipped | v1 tool flags; S4 maps them onto the tool registry of track 4 |
| Knowledge via file group key `TASKPROMPT:{topic}` | Shipped | Kept as the assistant's *own* folder; additional folders via IAM shares |
| `ModelConfigService` (`DEFAULTMODEL` per user) | Shipped | Assistant model bindings override per capability; fall back to user defaults |
| `SkillCatalog` / `Capability` | Shipped | S4 skill allow-list restricts what the planner may use for this assistant |
| `BSAVEDTASKS` (`BTRIGGERTYPE` manual / chat / schedule / inbound_email, `BTRIGGERCONFIG`, `BALLOWUNATTENDED`) | Shipped | S5: an assistant's **schedules** and **unattended events** (mail rule, later web hook) are ordinary Saved Task rows created on publish; the trigger columns stay authoritative at runtime |
| `WidgetSetupService` (AI Setup Assistant) | Shipped | Optional helper inside the builder (decision 10) |
| `BWIDGETS.BTASKPROMPT` | Shipped | Stays; `BAGENTID` added beside it |
| Plugin manifest v2 `provides.*` (planned) | Planned | `provides.agents` for assistant packs |
| IAM `assistant` kind (track 1 S3) | Planned | Publishing |

---

## 4. Target architecture

```text
  Builder (form)  ──►  BAGENTS (draft)  ──publish──►  BAGENTVERSIONS (immutable)
                              │                               │
                              │ BPROMPTID                      │ shared via IAM (use/edit)
                              ▼                               ▼
                          BPROMPTS                 Gallery ("Assistants" for the user)
                                                              │ start chat
                                                              ▼
  Chat ── agentId ──► MessageClassifier (pin) ──► AgentRuntimeResolver ──► RuntimeProfile
                                                              │
                                     prompt · models · knowledge scopes · tool allow-list
                                     skill allow-list · parameters · response schema
                                                              ▼
                                                     ChatHandler / TaskPlanner (unchanged APIs)

  Triggers (definition) ──publish──► events:  BWIDGETS.BAGENTID · department agentId · WHATSAPP.AGENTID
                                              /v1/models alias · list_assistants (S6)
                                     schedules + mail rule + web hook:  ordinary BSAVEDTASKS rows
                                              (SavedTaskRunner runs as the owner, agentId pinned)
```

### 4.1 Schema (S1)

| Table | Columns | Notes |
| ----- | ------- | ----- |
| `BAGENTS` | `BID`, `BOWNERID`, `BPROMPTID`, `BSLUG` (unique per owner), `BNAME`, `BDESCRIPTION`, `BICON`, `BSTATUS` (`draft` / `published` / `archived`), `BDRAFT` (JSON `agent.v1`), `BPUBLISHEDVERSIONID` (nullable), `BPARENTID` (clone lineage, nullable), `BSOURCE` (`manual` / `import` / `plugin:<id>`), `BROUTABLE` (0/1, decision 6), `BCREATED`, `BUPDATED` | One row per assistant |
| `BAGENTVERSIONS` | `BID`, `BAGENTID`, `BVERSION` (int), `BDEFINITION` (JSON), `BPROMPTTEXT` (snapshot of the instruction at publish time), `BCHANGELOG`, `BPUBLISHEDBY`, `BCREATED` | Immutable; users run `BPUBLISHEDVERSIONID` |
| `BWIDGETS.BAGENTID` (S5) | nullable int | Widget → assistant binding |

### 4.2 The definition (`agent.v1`)

```json
{
  "schema": "agent.v1",
  "models": { "chat": "anthropic:claude-sonnet-5:chat", "vision": null, "vectorize": null },
  "knowledge": { "ownFolder": true, "folders": [ "{ownerId}:{groupKey}" ], "ragLimit": 8, "ragMinScore": 0.6 },
  "tools": { "internet": true, "files": true, "mcpServers": [12, 15], "allow": ["web_search", "rag_search"], "deny": [] },
  "skills": { "allow": ["chat", "summarize", "document_generation"], "deny": ["email_me"] },
  "parameters": { "temperature": 0.3, "maxTokens": 4000, "language": "auto", "responseSchema": null },
  "behaviour": { "greeting": "…", "starterPrompts": ["…"], "memory": "user" },
  "triggers": {
    "events": [
      { "id": "acme-mail", "kind": "mail", "mailbox": "{ownerId}:{handlerId}",
        "rule": { "from": ["@acme.com"], "contains": ["contract", "NDA"], "match": "any" },
        "instruction": "Review it against our checklist and reply." },
      { "id": "legal-widget", "kind": "widget", "widget": "{ownerId}:{widgetId}",
        "widgetDefaults": { "…": "…" } },
      { "id": "api", "kind": "api" },
      { "id": "mcp", "kind": "mcp" }
    ],
    "schedules": [
      { "id": "weekly", "name": "Weekly digest", "every": { "unit": "week", "on": "mon", "at": "08:00" },
        "tz": "Europe/Berlin", "instruction": "Summarise last week's contract questions.", "allowUnattended": false }
    ]
  }
}
```

Model references use the catalog key form `service:providerId:tag`
(`ModelCatalog::findBidByKey`), never raw BIDs, so definitions survive
export/import. Unknown keys are rejected on import (`deny_unknown_fields`
style) — the schema is versioned for a reason.

**Triggers (decision 15).** `events[].kind` ∈ `mail` · `whatsapp` ·
`widget` · `api` · `mcp` · `desktop` · `webhook`; `schedules[]` carry
either `every` (the picker's plain form) or `cron` (Advanced), always
`tz` and a required `instruction`. Each entry has a stable `id` so the
rows it creates survive renames. Runtime mapping — nothing new is
invented:

| Kind | Who runs it | Where it lives at runtime | Sprint |
| ---- | ----------- | ------------------------- | ------ |
| chat (implicit, always on) | the person talking | `agentId` on the stream endpoint (S1) | S1 |
| `widget` | the visitor session | `BWIDGETS.BAGENTID` | S5 |
| `whatsapp` | the person writing | `BCONFIG WHATSAPP.AGENTID` | S5 |
| `mail` (department option) | the mail's user context, as today | department `agentId` in `InboundEmailHandler::$departments` | S5 |
| `mail` (rule option) | **the owner, on its own** | `BSAVEDTASKS` with `BTRIGGERTYPE = inbound_email`, `BTRIGGERCONFIG.filter` (additive), `agentId` | S5 |
| `api` | the API key's user | `assistant:<slug>` alias in `/v1/models` | S6 |
| `mcp` | the MCP session's user | `list_assistants` + `synaplan_chat.agentId` | S6 |
| `desktop` | the paired user | same as `mcp` for `desktop:*`-scoped keys; a desktop job later | S6 |
| `webhook` | **the owner, on its own** | `BSAVEDTASKS` with `BTRIGGERTYPE = webhook` (track 4 TL42) | track 4 S5 |
| schedule | **the owner, on its own** | `BSAVEDTASKS` with `BTRIGGERTYPE = schedule` | S5 |

Instance-bound references (`mailbox`, `widget`, a WhatsApp number) are
exported as **kind only** with a checklist item ("needs a mailbox"), never
as ids — the bundle rule of §4.4 and roadmap §8.1.

### 4.3 Runtime

- `AgentRuntimeResolver::resolve(agentId, user): RuntimeProfile` — loads the
  published version (or the draft for the owner's "test" mode), checks IAM
  `use`, resolves model keys to usable BIDs with fallback to the user's
  defaults, builds knowledge scopes (`RagScopeResolver` from track 1), tool
  and skill allow-lists.
- `MessageClassifier`: if the message carries `agentId` → return the pinned
  classification with `promptId` and `agentVersionId`; no sorter call.
- `ChatHandler` / `TaskPlanner` accept the `RuntimeProfile` through the
  existing options arrays (model, prompt, tool flags, rag scope). They gain
  no new branches for "agent vs no agent": a profile is always present, the
  default one being "the user's defaults".
- Usage rows (`BUSELOG`) gain `agentId` / `agentVersionId` for the owner's
  usage view ("who uses my assistant, how much") — metadata only, no content.

### 4.4 Publishing and distribution

- Publish = create `BAGENTVERSIONS` row + set `BPUBLISHEDVERSIONID`; then
  the ShareDialog (track 1) with kind `assistant`.
- Gallery (`/ai/assistants`): my assistants, shared with me, from plugins;
  filter chips; "Start chat", "Clone", "Details" (version, owner, changelog).
- Export: `GET /api/v1/agents/{id}/export` → `agent.v1` JSON + prompt text.
  Import: `POST /api/v1/agents/import` → new draft; model keys not present
  in the catalog are shown as "needs a model" and fall back to defaults.
- Plugin packs: `plugins/<id>/agents/*.json` declared in manifest v2
  `provides.agents`; installed as `BSOURCE = plugin:<id>` owned by the
  installing admin, shared with everyone by default (admin can unshare).

---

## 5. UI

**Nav (Manage → Assistants group):** `Models` (unchanged), **`Assistants`**
(new: gallery + builder; replaces `Instructions` at `/ai/instructions`,
which redirects), `Routing` (unchanged). Net change in nav item count: zero.

This track is the most visible product in the roadmap. Screens without
journeys will repeat the sharing miss: a Legal user who cannot find
"Contract review" in ten seconds means publish failed, even if
`BSHARES` is correct. Binding journeys J-AB-1…6 and U1–U12:
[`../202609_ux_user_flows.md`](../202609_ux_user_flows.md) §5.2.
S3 **Share** opens the IAM-UX dialog, not the S2 stacked form.

**Gallery:** cards, not a table. Chips Mine / Shared with me / Archived.
Primary verb on the card is **Start chat** (or **Clone** for a
view-only share). Empty Mine: one sentence + **Create assistant**.
Empty Shared: "Nothing has been shared with you yet" — no Create on
that chip. Cards shared *to* me show owner + version.

**Builder form sections** (progressive disclosure — the first section alone
makes a working assistant). **Seven, not nine** (2026-09-07 review):
Basics (name, icon, description, greeting, starters) → Instructions (the
prompt, with the optional AI helper) → Models (with an **Advanced
settings** disclosure holding the former Parameters: temperature, length,
language, response format) → Knowledge (own folder uploads + pick shared
folders) → Tools & skills (S4) → **Triggers** (S5; more event kinds in
S6) → Publish (version, changelog, share). Publish copy: "People always
talk to the published version. Your edits stay private until you
publish." Confirm via `useDialog()` names who will get it on their next
message **and which triggers switch** ("Website widget *Legal help*,
Support mailbox rule, Every Monday 08:00, 12 people in Legal").

**Triggers section** (replaces the planned *Tasks* and *Channels*;
wireframe
[`../202609_ux_user_flows/assistant-triggers.md`](../202609_ux_user_flows/assistant-triggers.md)):
one question — *when should this assistant act?* First row, always on:
"Someone starts a chat with it". Then **Events — when something arrives**
(**Add event**: Mail arrives · A visitor writes · A WhatsApp message ·
An app or coding tool · A connected app · Synaplan Desktop · A web hook;
kinds whose mechanism has not shipped are absent, not greyed) and
**Schedule — at a point in time** (**Add schedule**: every day / weekday
/ week / month at a time in the user's zone; cron only under Advanced).
Every row is one generated sentence — what arrives or when, what it
does, **who it runs as** ("Runs as the visitor" / "Runs on its own (as
you)") — plus one visible on/off and the last run. Rows created here and
the same binding made from the widget editor, the mailbox page or the
Saved tasks list are the **same row**, editable from either side; the
row says where its run history lives ("Saved under Automations → Saved
tasks"). Why this replaces Tasks + Channels: "Tasks" collided with Saved
Tasks one nav group away, "Channels" was a read-only mirror of things
configured elsewhere, cron was in primary copy, and API / MCP / Desktop
reachability was an invisible flag in S6. A trigger row makes all four
visible in the one place the owner already is.

**Test panel:** a side chat against the *draft* (owner only), titled
**Try a draft**, helper "Only you see this. It is not saved in
History." Editing does not affect published users. No Share here.

**Chat:** composer pill "Talking to {name}" for the chat's lifetime.
Archived assistants: existing chats continue with an **archived**
badge; **Start chat** disabled with one sentence why.

**Export & import (S6):** Settings section, one file, checklist of
what is missing in plain words. Import creates drafts, never shares.

**Words (en / de / es / fr / tr):** Assistant / Assistent / Asistente /
Assistant / Asistan; Publish / Veröffentlichen / Publicar / Publier /
Yayınla; Clone / Duplizieren / Duplicar / Dupliquer / Kopyala; Version /
Version / Versión / Version / Sürüm; Instructions / Anweisungen /
Instrucciones / Instructions / Talimatlar; Trigger / Auslöser / Activador
/ Déclencheur / Tetikleyici; Event / Ereignis / Evento / Événement /
Olay; Schedule / Zeitplan / Programación / Planification / Zamanlama;
Runs on its own / Läuft selbstständig / Se ejecuta por sí sola /
S'exécute seul / Kendi başına çalışır. "Prompt topic", "task prompt",
"system prompt", "binding", "channel", "task template", "cron" (outside
Advanced) leave the primary copy.

---

## 6. API sketch (additive, flag-gated)

| Method | Path | Sprint | Purpose |
| ------ | ---- | ------ | ------- |
| `GET/POST` | `/api/v1/agents`, `GET/PATCH/DELETE /api/v1/agents/{id}` | S1 | CRUD on drafts (owner) |
| `POST` | `/api/v1/agents/{id}/publish` | S3 | New version + set published |
| `GET` | `/api/v1/agents/{id}/versions` | S3 | Version list + changelog |
| `POST` | `/api/v1/agents/{id}/clone` | S2 | Clone (needs `read`) |
| `GET` | `/api/v1/agents/gallery` | S2 | Mine + shared with me + plugin packs |
| `GET/POST` | `/api/v1/agents/{id}/export`, `/api/v1/agents/import` | S6 | Portable definitions |
| `POST` | `/api/v1/messages/stream` (existing) gains optional `agentId` | S1 | Pinned chat |
| `GET` | `/api/v1/agents/{id}/usage` | S3 | Owner's metadata-only usage |
| `GET` | `/api/v1/agents/{id}/triggers` | S5 | Resolved trigger rows (kind, sentence parts, runs-as, status, last run, where it lives) — the one read the section needs |

MCP server: `list_prompts` gains a sibling `list_assistants`; `synaplan_chat`
accepts `agentId`. OpenAI gateway: an assistant is addressable as a model
alias `assistant:<slug>` in `/v1/models` (the `api` event trigger, off
until the owner adds it) — that is how Collabora's AI sidebar or a coding
client picks a curated assistant. `list_assistants` is the `mcp` event.

---

## 7. Compatibility invariants

| # | Invariant | Proof |
| - | --------- | ----- |
| C1 | Flag off ⇒ `/ai/instructions` and `PromptController` behave as today; new routes 404; nav unchanged | Feature suite with flag off |
| C2 | No `agentId` on a message ⇒ `MessageClassifier` / `MessageSorter` results identical; snapshots untouched | Characterization suite |
| C3 | Prompts API contract unchanged (widgets, Synamail, Desktop use it) | Existing API tests |
| C4 | Widgets without `BAGENTID` behave exactly as today | Widget E2E |
| C5 | Saved Tasks contract unchanged; assistant schedules and unattended events create ordinary `BSAVEDTASKS` rows whose trigger columns stay authoritative; `BTRIGGERCONFIG.filter` is additive (absent ⇒ every mail, as today) | Saved task tests |
| C6 | An assistant cannot grant a user access to knowledge the owner did not share (`use`) — runtime re-checks IAM per request | Negative tests |
| C7 | Import never creates shares, credentials or file rows | Import tests |
| C8 | Mobile: `backend-only` + `ota-candidate` only | mobile-impact script |

---

## 8. Sprints

| Sprint | Content | Exit |
| ------ | ------- | ---- |
| **S1 — Entity & pinned runtime** | Migrations; `AgentService`, `AgentRuntimeResolver`, `RuntimeProfile`; classifier early return; `agentId` on stream endpoint; CRUD API; flag | Owner creates a draft via API and chats with it pinned; snapshots untouched |
| **S2 — Builder & gallery** | `/ai/assistants` gallery + builder form (Basics, Instructions, Models, Knowledge own folder); test panel; clone; `/ai/instructions` redirect; five locales; rename of `/channels/agents` label | J-AB-1 walked: empty state → create → test → Start chat, no docs |
| **S3 — Publish & versions** | `BAGENTVERSIONS`, publish flow, changelog, IAM `assistant` kind (with track 1 S3), gallery "shared with me", owner usage view. **Depends on IAM-UX** for the Share entry | J-AB-2…4: Legal finds it under Shared with me; Sales does not; clone leaves the original; archive is not broken |
| **S4 — Knowledge, tools, skills** | Shared folders picker (IAM `use`), tool allow/deny mapped to track 4's registry (or to today's flags until that ships), skill allow-list enforced in `TaskPlanValidator` per assistant; Advanced settings inside Models | An assistant restricted to `chat` + `rag_search` never plans `email_me` |
| **S5 — Triggers: events & schedules** | `triggers` in `agent.v1`; `AgentTriggerMaterializer` (schedules + mail rule → Saved Tasks, `BTRIGGERCONFIG.filter` additive); `BWIDGETS.BAGENTID`; department / WhatsApp binding; widget setup offers "pick an assistant"; **Triggers** builder section + `/triggers` endpoint | J-AB-5 and J-AB-7: a widget runs a published assistant; a mail from `@acme.com` containing "contract" is answered by it; a Monday 08:00 schedule runs — no cron, no "binding" on screen |
| **S6 — Portability & packs** | `synaplan-bundle.v1` format + `BundleExporter` / `BundleImporter` with a section registry (this track ships the `agents` and `prompts` sections; later tracks register theirs); Settings → **Export & import** (user) and Operate → System config → Export & import (admin, instance settings); plugin `provides.agents`; event kinds `api` (`assistant:<slug>` alias in `/v1/models`), `mcp` (`list_assistants`) and `desktop` appear in **Add event** | An assistant exported on instance A works on instance B with a different model catalog; the import checklist names every missing model, key, mailbox or widget |

Sprint files: [`01`](./01_sprint_1_entity_and_pinned_runtime.md) ·
[`02`](./02_sprint_2_builder_and_gallery.md) ·
[`03`](./03_sprint_3_publish_and_versions.md) ·
[`04`](./04_sprint_4_knowledge_tools_skills.md) ·
[`05`](./05_sprint_5_triggers.md) ·
[`06`](./06_sprint_6_portability_and_packs.md).

Cut line: S5 event kinds `whatsapp` and the mail **department** option
first (keep the mail **rule**, `widget` and schedules — they are the
J-AB-7 demo). **S6 is no longer the first cut**
— the export/import bundle became a product-owner requirement on
2026-09-03 (roadmap §8); if capacity is short, S6 ships the `agents` +
`prompts` sections only and the other sections follow in their tracks. Never
cut the test panel, versions, or the J-AB-2 findability walk — editing a
live assistant under users is the bug we are fixing, and a published
assistant nobody can find is the sharing lesson again.

---

## 9. Rollout

1. Merge to `main` behind `AGENTS.ENABLED = off`.
2. After S3 on a dev instance: enable on Synaplan Cloud for internal use;
   migrate the seeded system prompts into read-only plugin-style assistants
   (`BSOURCE = system`) so the gallery is never empty.
3. Seed flag **on** for new installs after S4; existing installs flip it.
4. Rollback: flag off; drafts and versions remain; chats with `agentId` fall
   back to the referenced prompt (still valid).

---

## 10. Out of scope (v1)

- Autonomous multi-agent orchestration (agents calling agents).
- A marketplace or paid assistants; cross-instance sync.
- Per-assistant billing / budgets (IAM v2 group budgets cover the need).
- Replacing `BPROMPTS` or `BPROMPTMETA`; changing how seeded `tools:*`
  prompts work.
- Fine-tuning or per-assistant model training.

---

## 11. Success criteria

1. An admin builds "Contract review", publishes it to "Legal", and a Legal
   user finds it in the gallery, chats with it, and gets answers grounded in
   the shared folder; a Sales user does not see it.
2. The admin edits the instructions, tests in the draft panel, publishes v2;
   Legal users get v2 on their next message without doing anything.
3. A user clones a published assistant, changes the model, and the original
   is untouched.
4. An assistant with `skills.deny = ["email_me"]` never sends mail even if
   the user asks; the planner explains why.
5. Export → import on a second instance yields a working draft with a clear
   "pick a model" hint where the catalog differs.
6. Flag off: gate green, snapshots untouched, `/ai/instructions` unchanged.
7. J-AB-1 and J-AB-2 walked in the browser (U10): a non-technical user
   builds without docs; a group member finds the published assistant
   under Shared with me in ten seconds.
8. J-AB-7 walked: the owner adds a mail event ("from @acme.com,
   containing contract") and a Monday 08:00 schedule in the Triggers
   section without seeing `cron`, `binding` or `topic`; a matching mail
   is answered pinned, a non-matching one is untouched; the same rows
   show under Automations → Saved tasks; the off switch stops them at
   once.

---

## 12. Decisions from the 2026-09-03 review (formerly open questions)

| # | Question | Decision |
| - | -------- | -------- |
| 1 | Memory for assistants | **User's own memory only** (`behaviour.memory = user`, the only accepted value in `agent.v1`). Shared assistant memory is v2 and IAM-sensitive. |
| 2 | Router opt-in (`BROUTABLE`) | **Allowed, off by default.** Turning it on for the first routable assistant changes the sorter's topic list; that lands in a dedicated PR with re-recorded, reviewed snapshots. |
| 3 | Owner archives a published assistant | **Existing chats continue on the last published version** with an "archived" badge; no new chats can be started from it; gallery hides it under a filter. |
| 4 | Builder UX | **Form first**; AI Setup Assistant is an optional helper inside the Instructions section. |
| 5 | Nav | `/ai/assistants` **replaces** `/ai/instructions` (301 in the router); instructions are edited inside the assistant. |
| 6 | Gateway page label | `/channels/agents` → **"Coding clients"** (de: Coding-Clients, es: Clientes de programación, fr: Clients de codage, tr: Kodlama istemcileri). |
| 7 | Bundle ownership (from IAM row 1) | This track defines `synaplan-bundle.v1` in S6 and ships the user-facing Export & import section in Settings; admins get the instance-level variant in Operate → System config. |
| 8 | Wording for "when does it act" (2026-09-07) | **Trigger = event or schedule** (decision row 15). Channels are events; the cron scheduler is the schedule; "Someone starts a chat" is always on. Tasks and Channels sections are gone; one **Triggers** section; Parameters folds into Models → Advanced settings (seven sections). |
| 9 | Mail as an event needs a rule (2026-09-07) | `inbound_email` Saved Task trigger gains an optional, additive `BTRIGGERCONFIG.filter` (`from[]`, `contains[]`, `match any/all`) evaluated by the ingress before a run is created. Owned by S5 `AB37`; absent filter = today's behaviour (C5). The AI-routed **department** option stays as the second choice for mailboxes that already sort into departments. |
