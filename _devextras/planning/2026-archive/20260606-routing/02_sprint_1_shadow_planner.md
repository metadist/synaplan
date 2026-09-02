# Sprint 1: Task Plan Schema + Shadow Planner

**Goal:** Generate and validate task plans for real traffic in the background, without executing them or impacting the user.

## Technical Tasks
1. **Schema Definition:** 
   - Define PHP value objects (`TaskPlan`, `TaskNode`).
   - Create a JSON Schema for validation.
2. **Database Migration:**
   - Create the `BMESSAGE_TASKS` table (additive migration) to persist plans.
3. **Prompt & Model Seeding:**
   - Seed `tools:plan` prompt in `PromptCatalog`.
   - Seed `DEFAULTMODEL.PLAN` (gpt-oss-120b on Groq).
4. **TaskPlanner Service:**
   - Build the service that calls the model, parses the JSON, validates against the schema, and falls back to a single `chat` node if invalid.
   - The planner input `[DYNAMICLIST]` MUST include the **per-user enabled `BPROMPTS` topics** (same set the legacy sorter sees), so custom topics survive. When the planner selects a custom topic, the node is a generic `chat` capability carrying the resolved `topic_id`/`prompt_id` (see master-plan decision).
   - **Deterministic pre-planner rules** (slash commands + doc/audio `analyzefile` force-route) emit a fixed plan and bypass the model entirely.
   - The plain-chat fast-path must skip the planner too.
5. **Shadow Mode Wiring:**
   - Wire `TaskPlanner` into `MessageProcessor` under the `MULTITASK_SHADOW_MODE` flag.
   - When on, the plan is generated, validated, and saved, but the **legacy path still answers the user**.

## UI/UX Impact
- **None.** The user experience remains identical. Shadow mode runs asynchronously or transparently.

## Release Gate (Success Test)
- [ ] Schema-validation unit tests pass (valid, malformed, hostile inputs).
- [ ] Golden corpus replay yields 100% schema-valid plans.
- [ ] The canonical scenario (doc -> summary -> mp3) correctly yields a 4-node chain.
- [ ] Shadow mode is proven non-user-facing: responses are byte-identical to Sprint 0 snapshots.
- [ ] Planner latency is within budget (< 1s P50).
- [ ] E2E tests remain green.
