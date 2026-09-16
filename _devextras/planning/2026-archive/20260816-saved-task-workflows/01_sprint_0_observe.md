# Sprint 0 — Observe executed DAGs & “Save as task” UX

**Goal:** Make the runtime DAG visible as a graph, and let the user *intend* to save a Task Prompt as a configurable task — **without** a new executor, scheduler, or schema.

**Depends on:** nothing. **Unlocks:** Sprint 1 (persistence).

**Flag:** none. This sprint is read-only on existing `BMESSAGE_TASKS` / chat history plus UI copy on AI Instructions.

---

## 0. Why this sprint exists

Two different graphs will exist after Sprint 2. If we skip observation, we will mix them:

- **Executed plan** — what the planner actually ran for one message (`BMESSAGE_TASKS`).
- **Authored graph** — what the user pinned as a Saved Task.

Sprint 0 ships only the first, plus a non-functional (or local-only) “Save as task” control that teaches the IA.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Service/Multitask/Plan/TaskPlan.php` | `tasks` + `depends_on` |
| `backend/src/Service/Multitask/TaskPlanStore.php` | How plans are persisted |
| `frontend/src/components/multitask/TaskPlanBubble.vue` | Live task cards (not a graph) |
| `frontend/src/components/multitask/TaskCard.vue` | Per-node card |
| `frontend/src/views/WidgetDetailView.vue` (~1248–1877) | Flow canvas UX to **learn from**, not import |
| `frontend/src/components/widgets/FlowNodeEditor.vue` | Node form |
| `frontend/src/utils/widgetBehaviorRules.ts` | How widget graphs compile to prompts — **do not copy this persistence** |
| `frontend/src/components/config/TaskPromptsConfiguration.vue` | Where the new control lives |
| `docs/FEATURES.md` (Multi-Task Routing) | User-facing description of the runtime DAG |

---

## 2. Developer steps

### 2.1 Backend — read API for a turn’s plan

1. Confirm how the history / stream payload already exposes task cards (`StreamController`, history store `isMultitask`).
2. If the executed plan JSON is **not** available to the frontend after reload, add an **additive** field on the existing message/history payload (OpenAPI complete: `@OA\Property`, example). Do **not** invent a new resource yet.
3. Shape for the UI (must match `TaskPlan` / stored snapshot):

   ```json
   {
     "version": 1,
     "reply_node": "n3",
     "tasks": [
       {
         "id": "n1",
         "capability": "email_search",
         "depends_on": [],
         "status": "completed"
       },
       {
         "id": "n2",
         "capability": "chat",
         "depends_on": ["n1"],
         "status": "completed"
       }
     ]
   }
   ```

4. Capabilities without a plan (legacy single bubble) → omit the graph; do not fake a one-node DAG.

### 2.2 Frontend — executed-plan graph

1. New focused component, e.g. `frontend/src/components/multitask/ExecutedPlanGraph.vue` (keep under 300 lines; split if needed).
2. Render nodes from `tasks[]`, edges from `depends_on`. Simple left-to-right or top-to-bottom is enough. Reuse design tokens; both themes; V2 (`.design-v2`) must remain readable.
3. Show it:
   - on a completed multitask turn (alongside or behind existing task cards — do not replace cards; cards are progress, graph is topology),
   - optionally in a “Plan” disclosure so simple users are not overwhelmed.
4. Empty / failed / legacy turns: no graph.
5. **Do not** allow editing nodes in this component. Read-only.

### 2.3 Frontend — “Save as task” entry points (non-persisting)

On AI Instructions (`TaskPromptsConfiguration.vue`):

1. Add a secondary button **Save as task** on a user-owned (or overridden) prompt.
2. Clicking it opens `useDialog()` explaining: *This will let you run this instruction on a schedule or from a channel. Coming in a later update.* **or**, if product wants a tighter loop, store a **frontend-only** draft in component state (discarded on navigation) — **no API**.
3. Do **not** write `BPROMPTMETA` keys for this. Sprint 1 owns persistence.

Optional (nice, still no schema): from a completed DAG turn, a “Save this as a task” affordance that pre-fills the Task Prompt topic if `params.topic_id` is present. Still no persist.

### 2.4 What you must not do in Sprint 0

- No Doctrine migration.
- No scheduler, no cron UI.
- No reuse of `persistFlowData` / widget rule comment blocks.
- No inference of boxes from `tools:plan` or `tools:sort` prompt text.
- No Office 365, no n8n service, no plugin `graphNodes`.

---

## 3. Testing

| Layer | File (create) | Assert |
| ----- | ------------- | ------ |
| Unit PHP | only if a new serializer/DTO is added | Snapshot of plan → graph DTO; missing plan → null |
| Component | `frontend/src/components/multitask/ExecutedPlanGraph.spec.ts` | Renders nodes + one edge from a fixture plan; empty plan → nothing; dark-theme classes not required but no hardcoded hex |
| Component | `TaskPromptsConfiguration` test or thin extract | Save-as-task control visible for custom prompts; dialog / disabled state; **no** POST |
| E2E | extend `frontend/tests/e2e/tests/task-prompts.spec.ts` **or** a small chat spec | Multitask fixture (TestProvider already emits multi-node plans) shows the graph disclosure |
| Regression | existing `TaskPlanBubble` / chat E2E | Cards still stream; widget chat unchanged |

Run the unfiltered gate. If OpenAPI changed: `make -C frontend generate-schemas` then `vue-tsc`.

Characterization: **do not** touch sorter/planner in this sprint. If you accidentally do, re-record and review.

---

## 4. Documentation

| Doc | Change |
| --- | ------ |
| `docs/FEATURES.md` | One sentence: executed multi-task plans can be viewed as a step graph in chat. |
| This folder `STATUS` (optional subsection in master plan) | Sprint 0 done date. |
| i18n `en.json` `de.json` `es.json` `tr.json` | Keys for graph disclosure, save-as-task, coming-soon dialog. No jargon (“DAG”, “node”) in user strings — use **step** / **plan**. |

Do **not** write `docs/N8N.md` yet unless you have spare time; that is Sprint 4. A one-line pointer in FEATURES is enough.

---

## 5. Release gate (must all be true)

- [ ] Executed plan graph renders for a known TestProvider multi-node turn and hides for a plain chat turn.
- [ ] Task cards still work; no layout break in light, dark, and V2.
- [ ] “Save as task” is visible on AI Instructions and does not persist.
- [ ] Widget, WhatsApp, email paths untouched (diff review).
- [ ] Four locales updated.
- [ ] Unfiltered CI gate green.
- [ ] Mobile-impact: if new frontend files, add paths to `.github/mobile-impact-policy.json` as `ota-candidate` and extend `tests/mobile-impact.test.mjs`.

---

## 6. Handoff to Sprint 1

Sprint 1 needs:

- Agreed JSON shape of an executed plan (reuse as `plan_snapshot` on runs).
- IA: Save as task lives on the Task Prompt, not a new nav item.
- Confirmation that widget flow persistence was **not** used.
