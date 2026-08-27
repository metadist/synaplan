# Sprint 1 — Summary Quality Eval Harness

Branch: `feat/summary-eval-harness`
Answers: *"Does the rolling summary work with our main chat models — Anthropic,
OpenAI, Google, HuggingFace, maybe big Ollama models — and does it hold its size?"*

## Why first

Sprint 2 changes the summary storage and wiring; later sprints tune retrieval.
Without a measuring instrument every prompt/model discussion is anecdotal. The
codebase already has the pattern: `PlanEvalCommand` runs a golden corpus against the
live planner model, outside the CI gate, invoked via `make -C backend plan-eval`.
We copy that pattern.

## Deliverables

### 1. Golden corpus — `backend/tests/Eval/summary_eval_corpus.json`

Synthetic but realistic conversations, each with:

- `id`, `language` (at least: en, de, one non-Latin-script case)
- `messages`: 20–60 turns (user/assistant), long enough that a real older span
  exists; include: durable facts (names, numbers, dates), a decision, an open
  question, a topic shift, and an "external result" (web search snippet)
- `probes.required`: substrings/regexes the summary MUST contain (fact retention)
- `probes.forbidden`: strings that indicate hallucination (facts NOT in the source)
- `expect_language`: language the summary must be written in

Cases for both prompt modes:

- **bootstrap**: full older span → summary (tiered/gradient compression)
- **incremental**: previous summary + newly aged-out messages → folded summary
  (includes a case where new messages RESOLVE an open question — the summary must
  move it out of "Open questions")

Include one WhatsApp-style corpus case (short messages, colloquial) and one
email-style case (long formal turns) so channel tone is covered.

### 2. Eval command — `backend/src/Command/SummaryEvalCommand.php`

`app:summary:eval`, modeled on `PlanEvalCommand`:

- Options:
  - `--models=provider:model,provider:model,...` — explicit list; default: the
    configured SUMMARIZE resolution for the global scope
  - `--corpus`, `--filter`, `--repeat` (stability), `--mode=bootstrap|incremental|both`
- Reuses the REAL prompts by extracting them from `ConversationSummaryService` into
  a small `ConversationSummaryPrompts` class (pure refactor, no behavior change) so
  the eval can never drift from production prompts.
- Calls `AiFacade::chat()` with the same options as production
  (`temperature 0.2`, same token budget formula).
- **Skips gracefully**: providers without a configured API key (or unreachable
  Ollama) are reported as `SKIPPED`, not failed — the command must be runnable on
  any install.

### 3. Scoring (deterministic, no judge model in v1)

Per case × model:

| Metric | Pass criterion |
| ------ | -------------- |
| Size | `mb_strlen(summary) <= SUMMARY_MAX_CHARS` (also report chars used) |
| Fact retention | all `probes.required` present (case-insensitive) |
| Hallucination | no `probes.forbidden` present |
| Language | summary language == `expect_language` (heuristic: stopword sampling; keep simple) |
| Structure | contains at least the `## Topic` heading; no preamble before first heading |
| Latency / tokens | reported, not scored |

Output: per-model table + overall pass rate, machine-readable `--json` flag for
recording results in `STATUS.md`.

### 4. Make target

`backend/Makefile`: `summary-eval` target mirroring `plan-eval`. NOT part of the CI
gate (needs live models) — document in the Makefile help text.

## Steps

1. Extract prompts to `ConversationSummaryPrompts` (+ keep `ConversationSummaryService`
   green: existing unit tests must not change assertions).
2. Author corpus (start with 6–8 cases; grow later).
3. Implement command + scorer (`backend/src/Service/Eval/SummaryEvalScorer.php` so
   the scorer is unit-testable without the console layer).
4. Run live against: Anthropic, OpenAI, Google, HuggingFace, Groq (current default),
   and — where hardware allows — a big Ollama model. Record results + chosen
   recommendation in `STATUS.md`.

## Tests (sprint gate)

- `backend/tests/Unit/Service/Eval/SummaryEvalScorerTest.php` — every metric:
  pass/fail/edge (empty summary, oversized, wrong language, forbidden string).
- Corpus validity test (like the plan-eval corpus): JSON parses, required keys
  present, probes non-empty — so a broken corpus fails CI even though the live eval
  itself does not run in CI.
- `ConversationSummaryPromptsTest` — prompts still contain the load-bearing
  instructions (size cap substitution, gradient wording, heading list).
- Full gate: `make -C backend lint && make -C backend phpstan && make -C backend test`.

## Out of scope

- Judge-model scoring (LLM-as-judge) — possible follow-up if deterministic probes
  prove too coarse.
- Changing the production prompts — only measure in this sprint; prompt changes are
  their own PRs afterwards, each accompanied by an eval run in the PR description.
