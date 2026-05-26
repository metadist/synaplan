# Multi-Step Planner — Design Plan (Release D)

Language-agnostic compound requests (e.g. “answer a question **and** generate an image”) without regex.
Aligns with [ROUTING_USE_CASES_PLAN.md](./ROUTING_USE_CASES_PLAN.md) §1.5, §3.2, §5 and [AGENTS_DEV.md](../AGENTS_DEV.md) architecture rules.

---

## 1. Problem

| Today | Issue |
|-------|--------|
| AI sorter returns **one** primary topic + flags (`BWEBSEARCH`, `BMEDIA`) | Compound utterances collapse into a **single handler hop** |
| `RuleBasedStepPlanner` uses **regex** for some compounds | Breaks on wording, language, typos |
| `MediaGenerationHandler` may run ChatHandler internally for prompt extraction | Text answer + image prompt + web search **mix in one bubble** |

**Goal:** ChatGPT-like sequencing — answer first, transition line, then artifact — for **any** formulation the sorter understands.

---

## 2. Design principles

1. **Sorter classifies, planner maps, orchestrator executes** — no business logic in controllers.
2. **No `multi_task` use case** in Synapse catalogue or admin UI (see ROUTING_USE_CASES_PLAN §0).
3. **Backward compatible** — missing `BSTEPS` → current single-step path unchanged.
4. **Progressive delivery** — ship sorter JSON + parser first; remove regex in a follow-up PR.
5. **Internal contract** — step plan JSON is runtime-only, not admin-editable v1.
6. **English in code/comments**, user-facing labels via i18n keys (`config.routing.steps.*`).

---

## 3. Architecture

```
User message
    │
    ▼
SynapseRouter (Qdrant) ──► coarse primary_use_case_id (optional, low confidence OK)
    │
    ▼
MessageSorter (AI) ──► extended JSON incl. BSTEPS[]
    │
    ▼
ClassificationStepPlanner ──► StepPlan (0..N PlannedStep)
    │                              │
    │                              ├─ BSTEPS present → map 1:1
    │                              ├─ signal fallback (web_search + media) → 2 steps
    │                              └─ else → defaultStepsForUseCase (1 step)
    ▼
MessageProcessor ──► web search / RAG once per plan (step-scoped options)
    │
    ▼
StepOrchestrator ──► for each step: InferenceRouter → handler
    │                    SSE: step_plan, step_started, step_completed, step_failed
    │                    stream: step text → transition → file event
    ▼
StreamController ──► SSE complete + searchResults + file metadata
```

**Existing components reused:** `StepOrchestrator`, `PlannedStep`, `StepPlan`, `InferenceRouter`, SSE `step_*` handling in `ChatView.vue` / `ChatMessage.vue`.

**New / refactored:**

| Class | Responsibility | Max size target |
|-------|----------------|-----------------|
| `App\UseCase\ClassificationStepPlanner` | Build `StepPlan` from classification + optional `BSTEPS` | ~150 lines |
| `App\Service\Message\SortingResponseParser` | Parse + validate sorter JSON (`BSTEPS`, legacy fields) | ~120 lines |
| `App\UseCase\StepCapabilityMapper` | Map step descriptors → `PlannedStep` + capability enum | ~80 lines |

`RuleBasedStepPlanner` → deprecated alias delegating to `ClassificationStepPlanner` for one release, then removed.

---

## 4. Sorter JSON contract (`BSTEPS`)

### 4.1 Extended fields (additive)

The sorter **keeps** all current fields (`BTOPIC`, `BLANG`, `BWEBSEARCH`, `BMEDIA`, …) for backward compatibility and single-step routing.

**New optional field:**

```json
{
  "BTOPIC": "image-generation",
  "BLANG": "de",
  "BWEBSEARCH": 1,
  "BMEDIA": "image",
  "BSTEPS": [
    {
      "id": "answer",
      "capability": "CHAT",
      "web_search": true
    },
    {
      "id": "generate",
      "capability": "TEXT2PIC",
      "prompt_from": "message"
    }
  ]
}
```

### 4.2 Step descriptor schema

| Field | Required | Type | Description |
|-------|----------|------|-------------|
| `id` | yes | string | Stable step id (`answer`, `generate`, …) |
| `capability` | yes | enum | `CHAT`, `TEXT2PIC`, `TEXT2VID`, `TEXT2SOUND`, `ANALYZE`, `TEXT2DOC` |
| `web_search` | no | bool | Step needs search results (default: inherit plan-level) |
| `prompt_from` | no | enum | `message` \| `previous_step` — media prompt source |
| `input_from` | no | string | `steps.{id}.output.text` — chain output (existing convention) |
| `label_key` | no | string | i18n override; default from capability template |

**Rules for the sorter prompt:**

1. If the user message contains **multiple independent goals**, fill `BSTEPS` in **execution order**.
2. If only one goal → **omit** `BSTEPS` (legacy single-step).
3. **Never answer the user** in the sorter — JSON only (existing rule).
4. Capabilities must be from the allowed list (no free-text handlers).
5. Max **5 steps** per message (guard in parser; truncate + log warning).

### 4.3 Examples (language-agnostic)

| User message (any language) | BSTEPS |
|-----------------------------|--------|
| Döner price + image | `[CHAT+web_search, TEXT2PIC]` |
| Poem + read aloud | `[CHAT, TEXT2SOUND input_from write]` |
| Summarize PDF | `[ANALYZE, CHAT]` (or single `file_analytics` default) |
| Image + email | `[TEXT2PIC, CHAT send]` (comm step later) |

---

## 5. Planner logic (no regex for compounds)

```php
final readonly class ClassificationStepPlanner
{
    public function plan(string $messageText, array $classification): StepPlan
    {
        // 1. Explicit sorter steps (preferred)
        if ($steps = $this->fromSorterSteps($classification['steps'] ?? null)) {
            return new StepPlan($this->primaryUseCaseId($classification), $steps, true);
        }

        // 2. Signal fallback (no BSTEPS, but conflicting flags)
        if ($plan = $this->fromClassificationSignals($classification)) {
            return $plan;
        }

        // 3. Default single-step template per use case
        return $this->defaultPlan($classification, $messageText);
    }
}
```

**Signal fallback (interim, until BSTEPS is reliable):**

- `web_search === true` **and** `media_type` set **and** media intent → `CHAT` → `TEXT2PIC|VID|SOUND`

**Regex patterns in `RuleBasedStepPlanner`:** remove in Phase D3 after BSTEPS coverage in tests.

---

## 6. Orchestrator behaviour

Per step (`StepOrchestrator` — extend, do not rewrite):

| Step capability | Classification overrides | Options |
|-----------------|-------------------------|---------|
| `CHAT` | `topic=general-chat`, `intent=chat`, unset `media_type` | include `search_results` when step or plan has web search |
| `TEXT2PIC` / `TEXT2VID` / `TEXT2SOUND` | `intent=image_generation`, `topic=mediamaker`, set `media_type` | **exclude** `search_results`; use `MediaPromptExtractor` on original message |
| `ANALYZE` | `intent=file_analysis` | attachments from message |

**Between steps (UX):**

1. Stream step *N* content to the **same assistant bubble** (already supported).
2. Before media step: stream transition line (language from `BLANG`):
   - DE: `Einen Moment, ich generiere das Bild …`
   - EN: `One moment, generating the image …`
3. Emit `step_started` / `step_completed` for progress UI.
4. On step failure: partial success + `step_failed` (existing).

**Future improvement:** move transition strings to i18n via SSE metadata `label_key` + frontend render (avoid hardcoded DE/EN in PHP).

---

## 7. MessageProcessor integration

```php
// After classification, before inference:
$plan = $this->stepPlanner->plan($message->getText(), $classification);
$classification['step_plan'] = $plan->toArray();

if ($plan->isMultiStep() && ($this->stepOrchestrator->isEnabled() || $plan->isCompound)) {
    return $this->stepOrchestrator->executeStream(...);
}

return $this->router->routeStream(...); // legacy single hop
```

**Web search:** run once when **any** step needs it; pass via `$options['search_results']` only to those steps.

---

## 8. Prompt changes (`PromptCatalog::sortPrompt`)

Add section **§8 Multi-step detection (`BSTEPS`)**:

- When to populate (multiple goals, conjunctions, implicit “and then”).
- Execution order rules.
- Capability cheat sheet (internal).
- Explicit: “Do **not** add fields other than listed; do **not** answer BTEXT.”

Update allowed fields list:

```
BTOPIC, BLANG, BWEBSEARCH, BMEDIA, BINPUTMODE, BDURATION, BRESOLUTION, BSTEPS
```

Seed: `make -C backend seed-prompts` (idempotent).

---

## 9. Backend parsing (`SortingResponseParser`)

```php
final readonly class SortingResponseParser
{
    /**
     * @return array{
     *   topic: string,
     *   language: string,
     *   web_search: bool,
     *   media_type: ?string,
     *   steps: ?list<array{id: string, capability: string, ...}>
     * }
     */
    public function parse(string $rawJson, array $originalData): array;
}
```

- Validate each step capability against allow-list.
- Drop invalid steps; if empty → `steps: null` (fallback).
- Log parse warnings with `message_id` (no PII in BTEXT).

Wire into `MessageSorter::parseResponse()` — keep method thin, delegate parsing.

---

## 10. Classification payload (internal)

Extend classification array passed through pipeline:

```php
[
    'topic' => 'mediamaker',
    'granular_topic' => 'image-generation',
    'language' => 'de',
    'web_search' => true,
    'media_type' => 'image',
    'intent' => 'image_generation',
    'primary_use_case_id' => 'media_generation',
    'steps' => [ /* normalized from BSTEPS */ ],
    'step_plan' => [ /* StepPlan::toArray() */ ],
    'source' => 'synapse_ai_fallback',
]
```

No DB migration required — runtime only.

---

## 11. Frontend

### 11.1 SSE (existing)

Already handled: `step_started`, `step_completed`, `step_failed`, `step_plan`.

### 11.2 Dry-run / routing test

`AdminSynapseController::dryRun` and `PromptController::testRouting` must run **full sorter** (or include mock BSTEPS) so admin UI shows:

`message → primary intent → steps[] → models per capability`

Extend `RoutingTestResult` (OpenAPI + Zod):

```typescript
steps?: Array<{ id: string; capability: string; web_search?: boolean }>
web_search?: boolean
media_type?: string | null
intent?: string
```

Regenerate: `make -C frontend generate-schemas`

### 11.3 `buildDryRunPreview.ts`

Mirror backend planner:

1. If API returns `step_plan` → use it.
2. Else if `webSearch + mediaType` → 2-step preview.
3. Else regex fallback (remove in D3).

---

## 12. API / OpenAPI

Annotate dry-run responses with new fields (`step_plan`, `steps`, `web_search`, `media_type`).

No new public endpoint required for v1 — extend existing:

- `POST /api/v1/admin/synapse/dry-run`
- `POST /api/v1/prompts/test-routing`

---

## 13. Testing strategy

| Layer | Tests |
|-------|--------|
| `SortingResponseParserTest` | Valid/invalid BSTEPS, max steps, unknown capability |
| `ClassificationStepPlannerTest` | BSTEPS mapping, signal fallback, single-step default |
| `StepOrchestratorTest` | CHAT→TEXT2PIC ordering, search_results scoped per step |
| `MessageProcessorTest` | compound triggers orchestrator without feature flag |
| Frontend | `routingDryRunPreview.spec.ts`, step progress in ChatMessage |

Fixture messages (multilingual):

- DE döner + image
- EN “what is X and draw Y”
- FR/ES samples (BSTEPS from sorter mock, not regex)

Run before commit (AGENTS.md gate):

```bash
make lint && make -C backend phpstan && make test && docker compose exec -T frontend npm run check:types
```

---

## 14. Rollout phases

### Phase D1 — Sorter contract (1–2 days)

- [ ] Extend `sortPrompt` with BSTEPS instructions
- [ ] `SortingResponseParser` + unit tests
- [ ] `MessageSorter` passes `steps` into classification
- [ ] `seed-prompts`

### Phase D2 — Planner + orchestrator (1–2 days)

- [ ] `ClassificationStepPlanner` (BSTEPS + signal fallback)
- [ ] Wire `MessageProcessor` (always run compound plans)
- [ ] Step-scoped `search_results` + transition stream
- [ ] Integration test: döner compound end-to-end (mock media provider)

### Phase D3 — Cleanup (0.5–1 day)

- [ ] Remove regex from `RuleBasedStepPlanner` / `routingDryRunPreview.ts`
- [ ] Dry-run returns full classification for admin preview
- [ ] Transition strings → i18n metadata (optional polish)
- [ ] Update [SYNAPSE_ROUTING.md](./SYNAPSE_ROUTING.md) troubleshooting section

### Phase D4 — Optional LLM planner (later)

Only when sorter returns `is_compound: true` without usable BSTEPS:

- Small dedicated prompt (`tools:step_planner`) → JSON step plan
- Behind `STEP_PLANNER_LLM_ENABLED` config flag

---

## 15. Non-goals (v1)

- Arbitrary 10+ step workflows
- User-editable step templates in admin UI
- Replacing Synapse / removing AI sort fallback
- New Qdrant collection for `multi_task`
- Outlook / comm compound flows (separate release)

---

## 16. File map (implementation)

| Action | Path |
|--------|------|
| Edit sort prompt | `backend/src/Prompt/PromptCatalog.php` |
| Parse BSTEPS | `backend/src/Service/Message/SortingResponseParser.php` (new) |
| Planner | `backend/src/UseCase/ClassificationStepPlanner.php` (new) |
| Deprecate | `backend/src/UseCase/RuleBasedStepPlanner.php` |
| Orchestrator tweaks | `backend/src/Service/Message/StepOrchestrator.php` |
| Pipeline | `backend/src/Service/Message/MessageProcessor.php` |
| Classification | `backend/src/Service/Message/MessageClassifier.php` |
| Dry-run | `backend/src/Controller/AdminSynapseController.php` |
| Frontend preview | `frontend/src/utils/routingDryRunPreview.ts` |
| i18n | `frontend/src/i18n/{de,en}.json` (`processing.stepMediaTransition`) |
| Tests | `backend/tests/Unit/UseCase/`, `backend/tests/Unit/Service/Message/` |

---

## 17. Success criteria

1. Döner test: web search answer → transition → image in **one** assistant message, correct order.
2. Same behaviour for EN/DE phrasing **without** code changes (only sorter output differs).
3. Single-step chat unchanged (no regression in latency).
4. Admin dry-run shows step plan when sorter returns BSTEPS.
5. PHPUnit + PHPStan + frontend typecheck green.

---

## 18. Related docs

- [ROUTING_USE_CASES_PLAN.md](./ROUTING_USE_CASES_PLAN.md) — product vision, Release D
- [SYNAPSE_ROUTING.md](./SYNAPSE_ROUTING.md) — Qdrant tiers, confidence threshold
- [FRONTEND_CONFIG_ROUTING_UX.md](./FRONTEND_CONFIG_ROUTING_UX.md) — step progress UI
- [API_PATTERNS.md](./API_PATTERNS.md) — OpenAPI + Zod workflow
