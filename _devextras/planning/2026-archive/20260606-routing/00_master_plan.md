# Master Plan: Multi-Task Routing Migration

**Goal:** Safely transition Synaplan's routing from a single-topic classification model to a parallel, multi-task DAG (Directed Acyclic Graph) execution model, without breaking existing features, breaking E2E tests, or causing regressions in cross-channel UX (Widget, WhatsApp, Email, Web).

## The Core Concept
Instead of mapping one prompt to one handler, the system will use an LLM planner (`DEFAULTMODEL.PLAN`) to break a user's prompt into a structured JSON Task Plan. This plan will be executed by a new `TaskPlanExecutor` that runs independent tasks in parallel and dependent tasks sequentially.

## Hard Constraints
1. **Zero Regressions:** Existing simple chats, slash commands, and widgets must work exactly as they do today.
2. **Test-Driven:** Every sprint must pass the pre-commit gate (`make lint`, `phpstan`, `make test`, `npm run check:types`, frontend tests).
3. **Additive Changes:** Only additive DB migrations. No destructive schema changes.
4. **Widget Invariant:** The embeddable ChatWidget must bypass the planner and continue working unchanged.

## Migration Principle (THE rule that protects live user setups)

**The planner only decides _what tasks_ to run. It NEVER re-derives _which model_ runs them.**

Model resolution stays 100% inside the existing chain, keyed by the *effective* user-id:

```
capability  ->  PromptMeta.aiModel (per-topic pin)  ->  user DEFAULTMODEL.*  ->  global DEFAULTMODEL.*  ->  fallback
```

This single rule protects the four places a user's live configuration lives today:

1. **Per-user custom topics (`BPROMPTS.BOWNERID > 0`)** — custom system prompt, `BSELECTION_RULES`, `BKEYWORDS`, and `PromptMeta` (`aiModel`, `tool_internet`, `tool_files`).
2. **`analyzefile`** — a deterministic forced route for any doc/audio attachment (NOT a model binding).
3. **`mediamaker`** — carries `media_type` / `duration` / `resolution` through the `$classification` array into `MediaGenerationHandler`.
4. **Per-user `DEFAULTMODEL.*` overrides + email/WhatsApp user-id remapping** (`ModelConfigService::getEffectiveUserIdForMessage`).

### Decisions locked for v1 (from review on 2026-06-07)

| Topic | Decision |
|---|---|
| **Custom user topics** | The planner emits a generic `chat` capability node that **carries the resolved `topic_id`/`prompt_id`**. The executor resolves that custom prompt + its `PromptMeta.aiModel` pin exactly as `ChatHandler` does today. The closed capability list is NOT widened per user. |
| **Deterministic shortcuts** | Slash commands (`/pic`, `/vid`, `/tts`, `/search`, `/lang`, `/web`, `/list`, `/docs`) **and** the "any doc/audio attachment → `analyzefile`" force-route stay as **pre-planner deterministic rules** that emit a fixed plan. The planner is never asked for these — guarantees no behavior change. |
| **Plan persistence** | New additive **`BMESSAGE_TASKS`** table (one Doctrine migration). |
| **Rollout** | Global flag defaults **ON** (so OSS, fresh installs, dev, and new signups get the new routing instantly). A one-time **grandfather migration** writes an explicit per-user `MULTITASK_ROUTING_ENABLED = off` row for every **existing** user, giving them a switch they control. Legacy sorter remains a permanent fallback. |

### Fast-path interaction
The existing plain-chat fast-path heuristic (`MessageClassifier::canFastPathClassify`) must **also skip the planner**, so trivial chat never pays for an extra planner call.

## Sprint Breakdown
We have divided the implementation into 8 safe, verifiable sprints. Each sprint has a specific goal, technical tasks, and a strict "Release Gate" (success test) that must pass before moving to the next sprint.

1. **[Sprint 0: Baseline & Foundations](./01_sprint_0_baseline.md)** - Lock down current behavior with a golden corpus and characterization tests.
2. **[Sprint 1: Shadow Planner](./02_sprint_1_shadow_planner.md)** - Generate and validate task plans in the background without executing them.
3. **[Sprint 2: Single-Task Executor](./03_sprint_2_single_task_executor.md)** - Route simple requests through the new executor to prove identical behavior.
4. **[Sprint 3: Sequential DAG](./04_sprint_3_sequential_dag.md)** - Enable multi-step plans (e.g., doc -> summary -> mp3) executing sequentially.
5. **[Sprint 4: Parallel Execution](./05_sprint_4_parallel_execution.md)** - Run independent tasks concurrently to improve latency.
6. **[Sprint 5: Cross-Channel Parity](./06_sprint_5_cross_channel.md)** - Ensure multi-file outputs work flawlessly on WhatsApp, Email, and API.
7. **[Sprint 6: UX, Observability & Admin](./07_sprint_6_ux_admin.md)** - Update the frontend to show multi-step progress and add admin debugging views.
8. **[Sprint 7: Rollout & GA](./08_sprint_7_rollout.md)** - Gradual rollout strategy and rollback procedures.

## Workflow for Each Sprint
1. **Implement** the changes behind the designated feature flags.
2. **Verify** against the Golden Corpus (created in Sprint 0).
3. **Run** the full CI suite locally (`make lint && make -C backend phpstan && make test && docker compose exec -T frontend npm run check:types`).
4. **Run** E2E tests (`@synaplan/frontend/tests/e2e/`).
5. **Review & Commit.**
