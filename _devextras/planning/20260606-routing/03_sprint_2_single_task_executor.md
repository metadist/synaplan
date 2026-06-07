# Sprint 2: Single-Task Executor & Adapters

**Goal:** Turn the routing flag on for *simple* messages and prove identical behaviour, including the strict Widget invariant.

## Technical Tasks
1. **Executor & Adapters:**
   - Implement `TaskPlanExecutor` (sequential for now).
   - Create `TaskRunner` interface and adapters for the **three** real handlers tagged `app.message.handler`: `ChatHandler`, `MediaGenerationHandler`, `FileAnalysisHandler` (plus thin runners for `BraveSearchService` and `AiFacade::synthesize`).
   - Ensure adapters feed the existing handlers the **exact** `$classification` array they expect — critically translating node `params` back into `media_type` / `duration` / `resolution` for `MediaGenerationHandler`, and `topic_id`/`prompt_id` for custom-topic `chat` nodes so `PromptMeta.aiModel` pins are honored.
   - **Model resolution stays in the existing chain** (capability → `PromptMeta.aiModel` → user/global `DEFAULTMODEL`) keyed by `getEffectiveUserIdForMessage`. The executor never picks a model itself.
2. **Result Assembler:**
   - Build `ResultAssembler` to format a 1-node plan result exactly like the legacy output.
3. **Widget Invariant (`ClassificationToPlan`):**
   - For fixed-prompt/widget branches, build a single `chat` node plan.
4. **Enable Routing (Constrained):**
   - Behind `MULTITASK_ROUTING_ENABLED`, route traffic through the new executor.
   - *Constraint:* Constrain the planner to emit ONLY single-node plans in this phase.

## UI/UX Impact
- **None.** The UX must remain completely unchanged. The system is just using a new internal pipeline to produce the exact same output.

## Release Gate (Success Test)
- [ ] With `MULTITASK_ROUTING_ENABLED` on, the Golden Corpus produces responses **equivalent** to Sprint 0 snapshots (zero behavioural diffs).
- [ ] **Widget Invariant Test:** `skipSorting` + `fixed_task_prompt` produces identical SSE event streams.
- [ ] Slash commands (`/pic`, `/tts`), RAG, and Officemaker verified through the new executor.
- [ ] E2E tests remain green.
