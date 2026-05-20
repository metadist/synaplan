# Routing & Use Cases — Product Vision, UX-Optimised Plan, and Migration

This document ties together **product direction** (SynaNet / Confluence “Routing Change”), **technical reality** in the current PHP backend, and a **user-first migration path** toward use-case–centric routing with Synapse (Qdrant).

Related docs:

- [SYNAPSE_ROUTING.md](./SYNAPSE_ROUTING.md) — current Synapse tiers, embeddings, Qdrant behaviour.
- [FRONTEND_CONFIG_ROUTING_UX.md](./FRONTEND_CONFIG_ROUTING_UX.md) — config UI URLs and navigation.
- `_devextras/planning/outlook-plugin-evaluation.md` — **Outlook add-in stays in scope** as planned by product; communication use cases hook into it and existing mail channels — not replaced or deferred.

---

## 0. UX principles (how this plan differs from a “tech-first” rollout)

| Principle | What we do |
|-----------|------------|
| **Two layers** | **Users/admins** see plain-language use cases and model **groups**. **Internally** only: capability IDs, task collections, packages. |
| **Progressive delivery** | Ship **clarity first** (models + routing test + rename), then Synapse on use cases, then multi-step. No “big bang”. |
| **No `multi_task` in the UI** | Complex utterances get a **planner** after Synapse picks a **primary intent** — not a separate catalogue entry users must understand. |
| **Honest scope** | Chat, media, file analytics first. Mail/calendar **via Outlook add-in + existing mail handler** as product already plans — not promised in the same release as core routing. |
| **Visible steps** | Multi-step runs show progress (“1. Write poem → 2. Read aloud”) and **partial success** if a later step fails. |
| **Override without breaking matrix** | Per-message model picker overrides **one step only**; global capability defaults stay intact. |

---

## 1. Target product model

### 1.1 Two layers

| Layer | Audience | Content |
|-------|----------|---------|
| **Experience** | End users, admins | “Summarize document”, “Create image”, “Send email” — labels in UI i18n |
| **System** | Backend, Synapse index | Stable `use_case_id`, capabilities, internal task collections (§5) |

Admins never edit JSON task collections in v1. Templates + planner produce them.

### 1.2 Definitions

| Term | Meaning |
|------|---------|
| **Capability** | Internal model slot (`CHAT`, `TEXT2PIC`, …). Mapped from **user-facing model groups** (§1.3). |
| **Use case** | Catalogue entry: *what* should happen (description, rules, keywords for Synapse). **No model field.** |
| **Primary intent** | Single use case Synapse / sorter returns for a message. |
| **Step plan** | 0–N runtime steps (rules + optional LLM planner). **Not** a user-visible “multi_task” use case. |
| **Routing** | Resolve **primary intent** via Synapse (Qdrant) + rules + AI fallback → then build step plan if needed. |

### 1.3 Model configuration — grouped for users, capabilities internally

**Product rule:** one model choice per **group** (UI); backend stores per **capability**.

| User-facing group (settings UI) | Capabilities (internal) |
|--------------------------------|-------------------------|
| **Chat & reasoning** | `CHAT`, `SORT` (admin-only fallback sorter) |
| **Images & video** | `TEXT2PIC`, `TEXT2VID`, `PIC2PIC`, `PIC2TEXT` |
| **Voice & audio** | `VOICE2TEXT`, `TEXT2VOICE`, `TEXT2SOUND` |
| **Documents — read** | `DOC2TEXT`, `ANALYZE`, `VIDEO2TEXT` |
| **Documents — create** | `TEXT2DOC` |
| **Routing (Synapse)** | `SYNAPSE_VECTORIZE`, `VECTORIZE` |

- **Remove** per–task-prompt / per–use-case model dropdowns.
- Use case editor links: *“Models for this kind of task → AI settings”*.
- **Per-message override:** chat model dropdown (or step override in debug/admin) affects **current step only** — does not rewrite the capability matrix.

Execution:

```
step.capability → user.getDefaultModel(capability) → provider call
(optional: step.override_model_id from user “Again” / message picker)
```

Legacy `PromptMeta aiModel` is migrated into capability defaults, then deprecated.

### 1.4 Synapse role

Unchanged mechanism; new index target:

1. Embed user message → search Qdrant (`synapse_use_cases` or extended collection).
2. Confidence, guards, sticky context, stale embedding filter.
3. AI sorter fallback.
4. Output: **`primary_use_case_id`** — not a legacy `BTOPIC` that also picks models.

### 1.5 Planner (replaces “multi_task” as a catalogue entry)

After primary intent is known:

- **Single-step** use cases (most traffic): run immediately (`text_chat`, `file_analytics`, …).
- **Compound requests** detected by rules or lightweight LLM planner → **step plan** (§5), e.g. `CHAT` → `TEXT2SOUND`, `TEXT2PIC` → `comm_send_email`.

Synapse does **not** need a separate indexed use case called `multi_task`. The planner attaches steps when utterance signals multiple goals (“and”, “then”, “mail it to …”).

---

## 2. Use case catalogue (system IDs — user labels via i18n)

Stable IDs for Synapse indexing. Display names are **not** these snake_case strings.

| ID | User-facing label (example EN) | Typical step plan | Notes |
|----|--------------------------------|-------------------|-------|
| `text_chat` | Chat | 1× `CHAT` (+ optional voice loop) | Default path |
| `media_generation` | Create image, video, or audio | 1× `TEXT2PIC` / `TEXT2VID` / `TEXT2SOUND` | Media subtype from routing or planner |
| `file_generation` | Create document | 1× `TEXT2DOC` + tools | Pipeline TBD; today `officemaker` via chat |
| `file_analytics` | Understand a file | `DOC2TEXT` / vision → `CHAT` | **FileAnalysisHandler** |
| `comm_send_email` | Send email | draft → send | **Outlook add-in** + `/api/v1/outlook/*` + mail handler — **keep product plan** |
| `comm_send_calendar` | Create calendar entry | draft → calendar API | Integrate when calendar scope is ready |
| `comm_receive_email` | Work with incoming mail | ingest → analyse / reply | IMAP/mail handler + Outlook read scenarios |
| `comm_receive_calendar` | Work with calendar invites | ingest → route | TBD |
| `realtime_fork` | Live conversation (branches) | forked step plans | **Later phase** — widget/realtime |

**Removed from catalogue:** `multi_task` as a Synapse label — handled by **planner** (§1.5).

**Examples**

| User says | Primary intent | Step plan (internal) |
|-----------|----------------|----------------------|
| “Summarize this PDF” | `file_analytics` | `DOC2TEXT` → `CHAT` |
| “Write a poem and read it aloud” | `text_chat` | planner: `CHAT` → `TEXT2SOUND` |
| “Generate an image and email Bernd” | `media_generation` | planner: `TEXT2PIC` → `comm_send_email` |
| “Summarize this mail” (Outlook) | `file_analytics` or `comm_receive_email` | Outlook add-in → Synaplan API → analyse |

Re-index Synapse when catalogue descriptions change.

---

## 3. Platform architecture — three phases (internal contracts)

Gradual extraction from `MessageProcessor`; SSE streaming preserved early.

### 3.1 InputPackage

User payload + optional: web search, memories, documents, history (full or summarized), channel meta (web, WhatsApp, **Outlook**, widget).

### 3.2 ProcessingPackage

| Stage | Purpose |
|-------|---------|
| **Pre-tasks** | Fetch mail attachment (Outlook/IMAP), OCR, RAG, clarifying questions |
| **Core** | Synapse → primary intent → **step plan** → execute with per-step status |
| **Post-tasks** | Prepare send (email via Outlook API / SMTP), format artifacts |

**Chaining:** step *N* output → step *N+1* input (text, file ref, URL).

**User-visible:** SSE events like `step_started`, `step_completed`, `step_failed` with human labels — not raw JSON.

### 3.3 OutputPackage

Inline text/audio, artifact links, channel delivery (WhatsApp, **Outlook reply**, email). Partial output if a late step fails.

---

## 4. User-visible behaviour (acceptance criteria)

1. **Settings:** one **AI models** page with **groups** (§1.3); no model on use case editor.
2. **Routing admin:** rename to “Use cases & routing”; **dry-run** shows: *message → primary intent → steps → which models (from groups)*.
3. **Chat:** simple messages feel unchanged (single step, no jargon).
4. **Compound messages:** progress strip or status lines per step; failed step 2 still shows step 1 result + retry.
5. **Outlook:** add-in flows (summarize, translate, …) map to use cases / capabilities — **no parallel routing model**; see `_devextras/planning/outlook-plugin-evaluation.md`.

---

## 5. Task collection JSON (internal only)

Runtime contract between planner and orchestrator. **Not** exposed in admin UI v1.

```json
{
  "session": "1234567890",
  "primary_use_case_id": "text_chat",
  "steps": [
    {
      "id": "write",
      "label_key": "steps.write_poem",
      "capability": "CHAT",
      "prompt_ref": "use_case:text_chat"
    },
    {
      "id": "read_aloud",
      "label_key": "steps.read_aloud",
      "capability": "TEXT2SOUND",
      "input_from": "steps.write.output.text"
    }
  ]
}
```

Simple use cases: `"steps": [{ "id": "main", "capability": "CHAT", ... }]`.

---

## 6. Current backend — condensed gap analysis

| Issue | Today |
|-------|--------|
| Topic overload | `BTOPIC` = routing + prompt + RAG + analytics |
| Single handler hop | `InferenceRouter` → one intent |
| Handlers registered | `chat`, `image_generation`, `file_analysis` only |
| Comm intents | `email` / `calendar` → missing `tool` handler → **chat** fallback |
| Outlook | Planned add-in + API endpoints — **align use cases with this**, do not drop |
| Prompt `aiModel` | Conflicts with capability-only goal |

See §12 for file map.

---

## 7. Mapping: catalogue → code (today)

| Use case | Today | Gap |
|----------|-------|-----|
| `text_chat` | `general` → ChatHandler | Unify voice loop |
| `media_generation` | MediaGenerationHandler | Simplify MediaPromptExtractor pass |
| `file_analytics` | FileAnalysisHandler | More formats |
| `file_generation` | officemaker → ChatHandler | Structured output pipeline |
| `comm_*` | partial mail / Outlook plan | Wire handlers + **Outlook `/api/v1/outlook/*`** |
| Compound utterances | — | Planner + orchestrator |
| `realtime_fork` | — | Later |

---

## 8. Migration strategy (UX-first order)

### Release A — Clarity (no routing engine rewrite)

1. **AI models UI:** capability groups (§1.3); migrate away from prompt `aiModel` in UI.
2. **Config rename:** “Use cases & routing” route/nav ([FRONTEND_CONFIG_ROUTING_UX.md](./FRONTEND_CONFIG_ROUTING_UX.md)).
3. **Dry-run enhancement:** show primary intent + **would-be steps** + resolved models from groups.
4. Fix misleading “generating with CHAT model” status when handler uses another capability.

**User benefit:** understandable settings before behaviour changes.

### Release B — Capability-only execution

1. Handlers resolve models from **capability matrix** only (remove prompt `aiModel` branch).
2. Data migration: old prompt models → capability defaults where unambiguous.
3. Per-message override scoped to **current step**.

### Release C — Synapse on use cases

1. Index **use case catalogue** in Qdrant (parallel to legacy topics during transition).
2. Router emits `primary_use_case_id` + legacy `topic` bridge.
3. Admin: edit use case **descriptions/rules** (today’s task prompt fields, no model column).

### Release D — Step planner + orchestrator (2-step templates first)

1. Rule templates: `CHAT`→`TEXT2SOUND`, `TEXT2PIC`→`comm_send_email`.
2. Optional LLM planner for harder compound utterances.
3. SSE step events + partial success in OutputPackage.
4. **Outlook send/read** steps use existing add-in / mail integration design — no duplicate mail stack.

### Release E — File generation pipeline, calendar, realtime fork

- Structured `file_generation`.
- `comm_send_calendar` / `comm_receive_calendar` when product ready.
- `realtime_fork` last.

### Internal refactor (parallel, non-blocking)

- InputPackage / ProcessingPackage / OutputPackage builders inside `MessageProcessor`.
- Clean up `InferenceRouter` dead intents (`code_generation`, `tool`) or implement properly.

---

## 9. Risks and non-goals

| Risk | Mitigation |
|------|------------|
| Users confused by multi-step | Progress UI + plain step labels; hide JSON |
| Over-promising comm | Outlook/mail scoped to Release D; document in release notes |
| Dual topic + use_case | Feature flag; sunset legacy topic routing |
| Mid-chain failure | Partial OutputPackage + retry single step |
| Synapse re-index | Same ops as today (admin reindex) |

**Non-goals:** Replacing Qdrant; removing AI sort fallback in v1; changing Outlook add-in product scope (keep `_devextras/planning/outlook-plugin-evaluation.md`).

---

## 10. Open decisions

1. Global vs tenant-custom use case catalogue?
2. Planner: rules-only for v1 vs LLM planner in v1?
3. Qdrant collection: extend `synapse_topics` vs `synapse_use_cases`?
4. History in InputPackage: always summarize above N tokens?

---

## 11. Code map (current)

| Concern | Location |
|---------|----------|
| Orchestration | `MessageProcessor.php` |
| Classification | `MessageClassifier.php`, `SynapseRouter.php`, `MessageSorter.php` |
| Handler dispatch | `InferenceRouter.php` |
| Chat / vision | `ChatHandler.php` |
| Media | `MediaGenerationHandler.php`, `MediaPromptExtractor.php` |
| File analysis | `FileAnalysisHandler.php` |
| Topic aliases | `TopicAliasResolver.php` |
| Legacy prompt models | `PromptService.php` |
| Capability defaults | `ModelConfigService.php` |
| Synapse index | `SynapseIndexer.php` |
| Outlook (planned) | `_devextras/planning/outlook-plugin-evaluation.md` |

---

*Last updated: UX-first phasing, grouped model settings, planner instead of multi_task catalogue entry, step visibility, Outlook add-in kept in scope.*
