# Agent Builder — reusable, published assistants — master plan

**Status:** Draft 2026-09-03. Track 2 of [`../20260903_roadmap.md`](../20260903_roadmap.md).
Depends on track 1 (IAM) S1–S2 for publishing; S1–S2 of this track can start
before that with owner-only assistants.
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

---

## 0. Decision checklist (tick before any code)

| # | Decision | Proposed default | Agree? |
| - | -------- | ---------------- | ------ |
| 1 | **User-facing word: Assistant.** Code name `agent` (`BAGENTS`, `AgentService`, `/api/v1/agents`). Matches the glossary in `AGENTS.md` ("AI assistant — the AI that answers inside a widget") and the existing nav group **Assistants**. | Assistant / `agent` | |
| 2 | **The Messages-gateway page currently labelled "AI Agents" (`/channels/agents`) is renamed** to avoid the collision — proposed **"Coding clients"** (en) under Connections. Route unchanged. | Rename | |
| 3 | **An assistant composes; it does not replace `BPROMPTS`.** `BAGENTS.BPROMPTID` points at the instruction row; models, knowledge, tools, skills, parameters and tasks live in a versioned JSON definition (`agent.v1` schema). `BPROMPTMETA` stays the runtime store for widgets that are not migrated. | Compose | |
| 4 | **Versioned and publishable.** `BAGENTVERSIONS` holds immutable snapshots; users always run the latest *published* version; the owner edits a draft. "Update without rebuilding" = publish a new version. | Versions | |
| 5 | **Publishing = an IAM share** (`assistant` kind, permission `use`; `edit` for co-maintainers). "Assign to a department" = share with that group. No second permission model. | IAM only | |
| 6 | **Talking to an assistant pins it.** A chat started from an assistant carries `agentId`; `MessageClassifier` short-circuits exactly as it does today for a pinned `PROMPTID`. Assistants are **not** added to the sorter's topic list unless the owner opts in ("also let the router pick this"). | Pinned by default | |
| 7 | **Execution identity = the person talking.** Budget, rate limits, memories and files are the user's. The assistant's knowledge is the owner's shared folders (via IAM `use`), read-only for the user. Scheduled tasks created from an assistant run as the task owner (existing Saved Task rule). | User runs, owner's knowledge | |
| 8 | **Clone is a first-class action** (copy definition + prompt as a new draft owned by the cloner; lineage kept in `BPARENTID`). Cloning a shared assistant needs `read`. | Clone | |
| 9 | **Portable definitions.** Export/import JSON (`agent.v1`) for backup, moving between instances, and plugin packs (`provides.agents` in manifest v2). Never includes secrets, file binaries or other users' ids. | JSON, no secrets | |
| 10 | **The builder is a form, not a chat.** The existing AI Setup Assistant (`WidgetSetupService`, `tools:widget-setup-interview`) is reused as an optional "help me write this" helper inside the form, not as the primary editor. | Form first | |
| 11 | **Widgets and channels bind an assistant** (S5): `BWIDGETS.BAGENTID` (nullable) beside the existing `BTASKPROMPT` topic; a widget with an agent id ignores the topic. Email handler and WhatsApp bindings follow the same pattern. | Additive binding | |
| 12 | **Schema (ask recorded):** `BAGENTS`, `BAGENTVERSIONS` (S1), `BWIDGETS.BAGENTID` (S5). Galera-safe `addSql`. | Ask recorded | |
| 13 | **Characterization discipline:** with the flag off or with no pinned assistant, `MessageClassifier` / `MessageSorter` output is unchanged; snapshots are not re-recorded by this track. | Locked | |
| 14 | **Mobile:** new PHP `backend-only`; builder + gallery `ota-candidate`. | Locked | |

---

## 1. The concept in three sentences

> An **assistant** is a saved recipe: what the AI should do (instructions),
> with which models, using which knowledge, tools and skills, with which
> settings. Whoever builds one can publish it to a group or to everyone, keep
> improving it, and every user always talks to the current version. Users
> can clone a published assistant and make it their own.

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
| `BSAVEDTASKS.BPROMPTID` | Shipped | S5: an assistant can define task templates; creating one creates a Saved Task |
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
  "tasks": [ { "name": "Weekly digest", "trigger": { "type": "schedule", "cron": "0 8 * * 1" }, "prompt": "…" } ],
  "channels": { "widgetDefaults": { "…": "…" } }
}
```

Model references use the catalog key form `service:providerId:tag`
(`ModelCatalog::findBidByKey`), never raw BIDs, so definitions survive
export/import. Unknown keys are rejected on import (`deny_unknown_fields`
style) — the schema is versioned for a reason.

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

**Builder form sections** (progressive disclosure — the first section alone
makes a working assistant): Basics (name, icon, description, greeting,
starters) → Instructions (the prompt, with the optional AI helper) →
Models → Knowledge (own folder uploads + pick shared folders) → Tools &
skills (S4) → Parameters → Tasks (S5) → Publish (version, changelog, share).

**Test panel:** a side chat against the *draft* (owner only), so editing does
not affect published users.

**Words (en / de / es / fr / tr):** Assistant / Assistent / Asistente /
Assistant / Asistan; Publish / Veröffentlichen / Publicar / Publier /
Yayınla; Clone / Duplizieren / Duplicar / Dupliquer / Kopyala; Version /
Version / Versión / Version / Sürüm; Instructions / Anweisungen /
Instrucciones / Instructions / Talimatlar. "Prompt topic", "task prompt",
"system prompt" leave the primary copy.

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

MCP server: `list_prompts` gains a sibling `list_assistants`; `synaplan_chat`
accepts `agentId`. OpenAI gateway: an assistant is addressable as a model
alias `assistant:<slug>` in `/v1/models` (opt-in per assistant) — that is how
Collabora's AI sidebar or a coding client picks a curated assistant.

---

## 7. Compatibility invariants

| # | Invariant | Proof |
| - | --------- | ----- |
| C1 | Flag off ⇒ `/ai/instructions` and `PromptController` behave as today; new routes 404; nav unchanged | Feature suite with flag off |
| C2 | No `agentId` on a message ⇒ `MessageClassifier` / `MessageSorter` results identical; snapshots untouched | Characterization suite |
| C3 | Prompts API contract unchanged (widgets, Synamail, Desktop use it) | Existing API tests |
| C4 | Widgets without `BAGENTID` behave exactly as today | Widget E2E |
| C5 | Saved Tasks contract unchanged; assistant tasks create ordinary `BSAVEDTASKS` rows | Saved task tests |
| C6 | An assistant cannot grant a user access to knowledge the owner did not share (`use`) — runtime re-checks IAM per request | Negative tests |
| C7 | Import never creates shares, credentials or file rows | Import tests |
| C8 | Mobile: `backend-only` + `ota-candidate` only | mobile-impact script |

---

## 8. Sprints

| Sprint | Content | Exit |
| ------ | ------- | ---- |
| **S1 — Entity & pinned runtime** | Migrations; `AgentService`, `AgentRuntimeResolver`, `RuntimeProfile`; classifier early return; `agentId` on stream endpoint; CRUD API; flag | Owner creates a draft via API and chats with it pinned; snapshots untouched |
| **S2 — Builder & gallery** | `/ai/assistants` gallery + builder form (Basics, Instructions, Models, Knowledge own folder); test panel; clone; `/ai/instructions` redirect; five locales; rename of `/channels/agents` label | A non-technical user builds and uses an assistant without reading docs |
| **S3 — Publish & versions** | `BAGENTVERSIONS`, publish flow, changelog, IAM `assistant` kind (with track 1 S3), gallery "shared with me", owner usage view | Admin publishes to "Support"; a member uses v1 while the admin edits v2; publish v2 → member gets it on next message |
| **S4 — Knowledge, tools, skills** | Shared folders picker (IAM `use`), tool allow/deny mapped to track 4's registry (or to today's flags until that ships), skill allow-list enforced in `TaskPlanValidator` per assistant | An assistant restricted to `chat` + `rag_search` never plans `email_me` |
| **S5 — Tasks & channels** | Task templates → Saved Tasks; `BWIDGETS.BAGENTID`; email handler / WhatsApp binding; widget setup offers "pick an assistant" | A widget runs a published assistant; a weekly digest task ships with it |
| **S6 — Portability & packs** | Export/import; plugin `provides.agents`; `assistant:<slug>` model alias in `/v1/models`; `list_assistants` MCP tool | An assistant exported on instance A works on instance B with a different model catalog |

Cut line: S6 first, then S5 channels (keep tasks). Never cut the test panel
or versions — editing a live assistant under users is the bug we are fixing.

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

---

## 12. Open questions (decide in S0)

1. Do assistants keep a per-user memory (`behaviour.memory = user`, today's
   behavior) or may the owner switch to "shared memory for this assistant"?
   (Proposed: v1 user memory only; shared memory is an IAM-sensitive v2.)
2. Router opt-in (`BROUTABLE`): let the sorter pick published assistants for
   users who did not start a chat from one? (Proposed: allowed, off by
   default; re-record snapshots only in a dedicated PR if it changes the
   topic list shape.)
3. What happens to a user's chat when the owner **archives** the assistant?
   (Proposed: the chat continues with the last version, badge "archived";
   no new chats.)
