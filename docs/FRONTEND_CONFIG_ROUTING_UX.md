# Frontend: Config & Routing — UX Plan (optimised)

Goal: **clear settings and routing admin** before deep backend changes. Backend: [ROUTING_USE_CASES_PLAN.md](./ROUTING_USE_CASES_PLAN.md). Synapse mechanics: [SYNAPSE_ROUTING.md](./SYNAPSE_ROUTING.md).

**Outlook add-in:** stays on the product roadmap (`_devextras/planning/outlook-plugin-evaluation.md`); communication use cases in routing docs reference it — no separate/conflicting UX here.

---

## UX goals

1. **Plain language** — “Use cases & routing”, not “sorting prompt”.
2. **One place for models** — grouped capabilities; **no** model dropdown on use case editor.
3. **Dry-run explains behaviour** — message → intent → steps → models (from groups).
4. **Progressive releases** — UI changes in Release A before orchestrator ships.

---

## Current state

| Aspect | Today |
|--------|--------|
| Config sections | `ConfigView.vue` + `path.includes` |
| Synapse / routing UI | `/config/sorting-prompt`, `SortingPromptConfiguration.vue` |
| Task prompts | `/config/task-prompts` — will become **use cases** (labels + fields, no model) |
| AI models | `/config/ai-models` — will add **groups** (Chat, Images & video, …) |

---

## Target UX

### 1. Routes and navigation

| Route | Purpose |
|-------|---------|
| `/config/routing` | Synapse, embedding, use case list, routing test (redirect from `/config/sorting-prompt`) |
| `/config/use-cases` | Rename from `task-prompts` (when ready); instructions only, link to AI models |
| `/config/ai-models` | Models by **group** (see backend §1.3) |

Nav: one group **“AI & tools”** — Use cases, Routing, AI models.

### 2. Use case editor (ex-task prompts)

- Fields: name, description, rules, keywords, enabled — for Synapse + fallback sorter.
- **Remove:** per-prompt model selector.
- **Add:** link “Which model handles this? → AI models” (opens grouped settings).

### 3. Routing test (dry-run)

Show admin-friendly result:

```
Message: "Write a poem and read it aloud"
→ Primary: Chat
→ Steps: 1. Write text  2. Text to speech
→ Models: Chat → [user's chat model], Speech → [user's TTS model]
```

Not: `multi_task`, JSON, or capability enum names (optional “technical details” collapse for support).

### 4. Chat (end user)

- Single-step: unchanged feel.
- Multi-step (later): step progress in status area; partial results visible.
- Keep **per-message model** control where it exists — overrides one step only (backend Release B).

### 5. Deep linking

`?section=synapse|embedding|use-cases|test|ai-sort` on routing page.

### 6. Guest gating

Unchanged: `/config/*` → `settings` gate.

---

## Phased delivery (aligned with backend)

| Release | Frontend work |
|---------|----------------|
| **A** | `/config/routing` + redirect; nav/i18n; grouped labels on AI models (even if backend still maps 1:1 initially); improved dry-run copy |
| **B** | Remove model from use case editor; link to AI models; per-message override copy/tooltip |
| **C** | Use case list matches Synapse catalogue IDs; reindex hints in UI |
| **D** | Step progress component for compound runs (SSE `step_*` events) |

---

## Test checklist

- [ ] `/config/sorting-prompt` → `/config/routing`
- [ ] Use case editor has no model dropdown (Release B)
- [ ] Dry-run readable without developer terms (Release A+)
- [ ] Sidebar highlights correct section
- [ ] Guest gate unchanged

---

## Open questions

1. Rename route `task-prompts` → `use-cases` in Release A (labels only) or Release C (with Synapse IDs)?
2. Single long routing page vs tabs for Synapse / use cases / test?

---

## Code references

- `frontend/src/router/index.ts`
- `frontend/src/views/ConfigView.vue`
- `frontend/src/components/SidebarV2.vue`
- `frontend/src/components/config/SortingPromptConfiguration.vue`
- `frontend/src/components/config/TaskPromptsConfiguration.vue`
- `frontend/src/components/config/AIModelsConfiguration.vue`
