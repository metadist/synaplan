# The research engine

**Status:** Draft 2026-10-17. Design for RS1–RS10 and RW3. Decisions it rests
on are §0 of [`00_master_plan.md`](./00_master_plan.md); model roles are
[`02_model_roles.md`](./02_model_roles.md); prompts are
[`04_prompting.md`](./04_prompting.md).

---

## 1. What a run is

A **research run** takes a question and produces a cited report. It is
read-only, bounded by a budget, durable for 48 hours, and observable through a
sequence of events any client can resume.

| In | Out |
| -- | --- |
| Question, depth (`standard`, `thorough`; internal `quick`), sources (web, knowledge folders, Library), optional language, output form, research rules, recent chat turns, role models, world, seed sources, parent run | Report (Markdown with `[n]` citations), sources (title, host or file, date, quotes used, why), run summary (time, searches, sources read and blocked, fact-check result, models used, cost), outcome (closed code + one sentence) |

---

## 2. Phases

```text
Brief ─► Plan ─► ┌─ Gather ─► Read ─► Reflect ─┐ ─► Write ─► Check ─► Deliver
                 └────── until covered ────────┘
```

### 2.1 Brief (sorter)

- Input: question, recent turns (resolve "this", "das", "esto"), research
  rules, today's date and the person's time zone, sources switched on.
- Output (JSON, `ResearchBriefSchema`): goal in one sentence, output form
  (`answer`, `report`, `table`, `comparison`, `list`), audience, language,
  time frame as absolute dates, region, sources the person named to use or to
  avoid, and `ambiguity` with up to three multiple-choice questions.
- Questions are asked only in Thorough, and in Standard only when the brief
  marks the request as ambiguous (§0 #10). Each question has a **Skip** that
  means "use your best judgement".
- Event `brief`; when questions exist, event `question` and the run waits for
  `input` (§7.4), at most 10 minutes, then continues as if skipped.

### 2.2 Plan (lead)

- Output (`ResearchPlanSchema`): three to seven points, each with a title a
  non-technical person understands, why it matters, two to four search queries
  (with language — German sources for German law, English for most technical
  topics) and the source scope (`web`, `files`, `both`); plus an outline of the
  report and an estimate (minutes, cost) from the budget table.
- Standard: event `plan`, then gathering starts at once. Thorough: event `plan`
  and `plan_waiting`; the run waits for `approve_plan` or `edit_plan` (at most
  24 hours, then it stops with `stopped_waiting`).

### 2.3 Gather (sorter + search)

- Web: `WebSearchGateway::search()` per query with language and freshness;
  results deduplicated by canonical URL and host; at most N per host.
- Knowledge: hybrid search over the selected folders (§10) inside the person's
  owner scopes (`RagScope`) — the same scopes chat uses, never wider.
- Triage (sorter, `ResearchTriageSchema`): for each result — relevant to which
  point, kind (`official`, `primary`, `news`, `academic`, `documentation`,
  `vendor`, `forum`), freshness, read or skip, with a short reason. The engine
  then picks what to read within the budget, preferring host diversity and the
  person's named sources.
- Events `search` (point, query, scope, count) and `source` with status
  `found`.

### 2.4 Read (readers)

- Fetch: HTML through `UrlContentService::fetchForReading()` (SSRF guard,
  robots.txt, noindex, redirects, login walls, shortlink interstitials); PDF and
  office files through the extraction chain (Tika) with a 20 MB, 15-second cap
  (§0 #12); knowledge files as the matching chunks plus their neighbours, or the
  whole document when it fits the reader's budget. Page fetches overlap like
  `WebResearchService::deepen()` does today (first hop started for all, then
  consumed).
- Reader call (`ResearchEvidenceSchema`): one source, the plan's points as the
  lens → an **evidence card** per useful finding (§5). Readers have no tools;
  the source text is wrapped and labelled as untrusted data (`04` §3).
- **Quote check (deterministic):** each quote is normalised (whitespace,
  quotes, hyphenation) and searched in the extracted source text. A quote that
  is not found is dropped; a finding with no verified quote is dropped. This
  check is free and is the floor of citation integrity.
- Long sources: `ContextCondenser::fit()` first narrows the text to the parts
  relevant to the points (extractive, fast model), then the reader reads.
- Events `source` with status `reading`, `read` (cards count) or `blocked`
  (reason: login wall, robots, timeout, too large, no text).

### 2.5 Reflect (lead) — the loop

- Input: the plan, a compact ledger summary (per point: findings with source
  ids, kinds, dates, conflicts), the queries already run, the budget left.
- Output (`ResearchDecisionSchema`):

```json
{
  "points": [
    { "id": "p1", "status": "answered", "note": "Two official sources agree." },
    { "id": "p2", "status": "conflicting", "note": "Price differs: 12.40 (s3, 2026-03) vs 11.90 (s8, 2025-11)." },
    { "id": "p3", "status": "open", "note": "No source on warranty terms yet." }
  ],
  "actions": [
    { "type": "search", "point": "p3", "query": "Lieferant A Gewährleistung Bedingungen 2026", "language": "de", "scope": "web" },
    { "type": "reread", "source": "s3", "question": "Is 12.40 the net or the gross price, and since when?" },
    { "type": "read", "source": "s14" }
  ],
  "finish": null
}
```

- Action types: `search`, `read` (a found, unread source), `reread` (a read
  source with a sharper question), `ask_user` (only for a choice only the person
  can make; counts against the question budget), `finish` (`covered`, `budget`,
  `no_progress`).
- The engine executes the actions — searches first, then reads — validates
  them (unknown source ids, repeated queries and actions beyond the budget are
  dropped, never sent back as errors), and calls reflect again.
- **Stop rules**, checked by the engine in this order:
  1. the person pressed **Stop** or **Write now** (`stop_gathering`);
  2. the lead returned `finish`;
  3. every point is `answered`;
  4. a budget is spent (searches, sources read, rounds, wall clock, cost);
  5. **no progress:** a round added no evidence to any `open`, `thin` or
     `conflicting` point → one more round with the hint "change strategy:
     other languages, other source kinds, broader or narrower queries", then
     stop.
- Event `round` (n of max) and `point` (status) after each reflection.

### 2.6 Write (writer)

- Input: brief, outline, evidence cards grouped by point (ids, facts, quotes,
  dates, kinds, conflicts) — **never** raw source text.
- Output: Markdown. Every factual sentence ends with evidence ids in brackets;
  a **Not confirmed** section lists open and thin points and conflicts with both
  sides and dates; no outside knowledge for facts; the summary answers the
  question first (`04` §4.6).
- Standard writes in one call. Thorough writes section by section (summary
  last, so it reflects the sections), each within the writer's output cap.
- Evidence ids become the reader-facing numbers `[1]…[n]` per source in
  delivery (§5.3).
- Event `writing` (section) and `section` (title) as each section is ready.

### 2.7 Check (checker)

1. **Deterministic:** every cited id exists; every cited card has at least one
   verified quote; numbers and dates in the sentence appear in the cited quotes
   (normalised); sentences that state facts carry a citation (a sentence with a
   number, a date or a proper name and no citation is flagged).
2. **Checker model** (`ResearchCheckSchema`), batched: each cited statement
   with its quotes → `supported`, `partial` (which part is not), `unsupported`,
   `contradicted`.
3. **Revision:** Thorough runs one writer revision with the verdicts (soften
   partial statements, remove unsupported ones, state contradictions). Standard
   removes unsupported statements and moves them to Not confirmed without a
   rewrite. A statement is never "fixed" with knowledge that has no source.
4. **Result sentence** in the report and the run summary:
   "35 of 37 statements are backed by a source. 2 were removed because no source
   confirmed them."
- Event `checking`, then `check` (statements, supported, softened, removed).

### 2.8 Deliver

- Final state: report, sources (with the quotes actually cited), run summary,
  outcome, models used, cost.
- Event `complete` (or `stopped` / `failed` — §7.5).
- The client persists it: Desktop as a card in the thread plus
  `out/<slug>.md` and `out/<slug>.sources.json`; the web as the assistant
  message with its report file.

---

## 3. Why an engine-driven loop

| | Engine-driven (structured decisions) | Model-driven (native tool calls) |
| - | ------------------------------------ | -------------------------------- |
| Providers | Any model with JSON output, including local models without tool calling | Only models whose tool calling works through our translators |
| Known failure classes | JSON drift → healing retry exists | Desktop CHANGES C2, C4, C10: unserved server tools, lost `thought_signature`, Harmony "tools should have a name" |
| Parallelism | The engine runs a decision's searches and reads together | Depends on the model emitting parallel calls |
| Budget and progress | Engine-enforced, a plan checklist the UI can show | Inferred from tool calls |
| Flexibility | Bounded to the action types | Open-ended |
| Testability | Each stage is a unit with a schema; `TestProvider` replays decisions | Requires replaying multi-turn tool transcripts |

The model-driven variant stays a spike (`00` §9 #2) for models that pass a
tool-call conformance suite; if it beats the engine on `06` metrics, it becomes
a per-model option, not a replacement.

---

## 4. Budgets

BCONFIG group `RESEARCH`, global, admin-tunable; seeded insert-if-missing.

| Budget | `quick` (internal) | Standard | Thorough |
| ------ | ------------------ | -------- | -------- |
| Plan points | — | 3–5 | 4–7 |
| Reflect rounds | 0 | ≤ 3 | ≤ 8 |
| Searches | ≤ 2 | ≤ 10 | ≤ 30 |
| Results per search | 8 | 10 | 10 |
| Sources read | ≤ 4 | ≤ 15 | ≤ 40 |
| Pages fetched at once | 4 | 4 | 6 |
| Wall clock | 60 s | 6 min | 20 min |
| Cost cap per run (catalog currency) | 0.10 | 1.00 | 5.00 |
| Clarifying questions | 0 | ≤ 1, only if ambiguous | ≤ 3 |
| Plan review | none | visible, starts at once | waits for **Start research** |
| Fact-check | deterministic only | deterministic + checker | deterministic + checker + one revision |
| Report length | ≤ 300 words | ≤ 1,500 words | ≤ 6,000 words, section by section |

Keys: `RESEARCH.BUDGET.<DEPTH>.<NAME>` (for example
`RESEARCH.BUDGET.THOROUGH.SEARCHES`), `RESEARCH.MAX_ACTIVE_THOROUGH_PER_USER`
(1), `RESEARCH.READ_DOCUMENTS_MAX_MB` (20), `RESEARCH.VERIFY_CANDIDATES`,
`RESEARCH.PRESET.<WORLD>.<DEPTH>.<ROLE>`.

Local-world runs use the same counts with a wall clock first: when the
estimate from the first two reader calls exceeds the wall clock, the engine
lowers the number of sources and says so in Details.

Before each phase the engine calls `RateLimitService::checkCostBudget()`; a
run that would exceed the person's monthly budget stops with
`stopped_budget` and writes with what it has. Every model call records usage
with the role and source `RESEARCH`; searches record `WEB_SEARCH`, rerank
`RERANK`. Plan limits per subscription level are §0 #25.

---

## 5. Evidence ledger

### 5.1 Shape

```json
{
  "run": "rr_01JAB…",
  "brief": { "goal": "…", "language": "de", "output": "comparison", "audience": "purchasing team",
             "time_frame": { "from": "2025-10-17", "to": "2026-10-17" }, "region": "DE",
             "must_use": [], "must_avoid": ["forum"] },
  "plan": { "points": [ { "id": "p1", "title": "Who offers X in Germany", "status": "answered" } ],
            "outline": ["Summary", "Suppliers", "Prices and terms", "Reviews", "Not confirmed"] },
  "sources": [
    { "id": "s3", "kind": "web", "url": "https://lieferant-a.example/preise", "host": "lieferant-a.example",
      "title": "Preisliste 2026", "published": "2026-03-01", "class": "vendor",
      "status": "read", "read_by": "openai:gpt-6-luna:chat" },
    { "id": "s9", "kind": "file", "file_id": 4711, "title": "Rahmenvertrag_A.pdf", "status": "read" }
  ],
  "evidence": [
    { "id": "e12", "source": "s3", "point": "p2", "fact": "Base price 12.40 EUR per unit (net).",
      "quotes": [ { "text": "Grundpreis netto 12,40 € je Einheit", "location": "Tabelle 1", "verified": true } ],
      "confidence": "high" }
  ],
  "queries": [ { "point": "p2", "q": "Lieferant A Preisliste 2026", "scope": "web", "results": 10 } ],
  "budget": { "searches": [7, 30], "sources_read": [12, 40], "rounds": [3, 8], "seconds": [341, 1200],
              "cost": { "estimate": 2.10, "spent": 0.92 } },
  "models": { "sorter": "…", "reader": "…", "lead": "…", "writer": "…", "checker": "…" }
}
```

### 5.2 Rules

- Ids are stable for the run (`s*`, `e*`, `p*`); a follow-up run inherits its
  parent's ledger and continues the numbering.
- A source that could not be read stays in the ledger with its reason; the
  report may say "the official page needs a login" instead of guessing.
- `published` is what the source states, or null — never inferred.
- Conflicts are kept as separate cards; the lead and writer see both.

### 5.3 Citations the person reads

- The report numbers **sources**, not cards: `[3]` means source s3. One
  sentence may cite several sources.
- Opening `[3]` shows the source title, host or file, date and the exact quotes
  used for *that* sentence, with **Open page** / **Open file**.
- The sources list at the end of the report gives each source once, with what
  it was used for.

---

## 6. Durability

- **State:** Redis `research:run:{id}` (ledger + phase checkpoint),
  `research:events:{id}` (sequenced events), `research:active:{userId}`
  (concurrency cap). TTL 48 hours from the last change (§0 #7).
- **Execution:** `RunResearchCommand` on `async_ai_high`; the handler runs the
  phases and writes a checkpoint after each phase and after each source read.
  A retried message resumes from the checkpoint instead of starting over
  (retry once). Cancel is a flag checked between sources and between phases.
- **Waiting:** a run waiting for an answer or plan approval holds no worker;
  `input` re-dispatches it.
- **Resume:** events carry `id: <seq>`; `GET …/events` with `Last-Event-ID`
  (or `?after=`) replays from there, then streams live — the `ChatRunBuffer`
  pattern.
- **Lost state** (Redis flushed, TTL expired): the client gets 404, shows
  "The research was interrupted on the server", and offers **Start again**,
  which sends the sources it already received as `seed_sources`.
- **Client gone:** nothing changes for the run. Desktop reattaches when it
  starts or reconnects; the web reattaches like a chat run.

---

## 7. API

Two thin controllers over one `ResearchRunService`: `/v1/research*` for API
keys (a paired desktop key reaches it through `desktop:messages`; no new
scope) and `/api/v1/research*` for the web session. Complete OpenAPI on both;
generated Zod schemas for the web; `research-contract/` fixtures for Desktop
(§0 #9).

### 7.1 Endpoints

| Method | Path | Purpose |
| ------ | ---- | ------- |
| `GET` | `/v1/research/capabilities` | `enabled`, depths, `web_search_available`, `knowledge_available`, `read_documents`, `max_active_thorough`. **404 when the flag is off** — the client hides the feature |
| `POST` | `/v1/research` | Start a run → `202 { id, status, events }` |
| `GET` | `/v1/research/{id}` | Current state (§7.3) |
| `GET` | `/v1/research/{id}/events` | SSE, resumable (`Last-Event-ID`, `?after=`) |
| `POST` | `/v1/research/{id}/input` | Answer a question, approve or edit the plan, add a note or a source, write now |
| `POST` | `/v1/research/{id}/cancel` | Stop; terminal `stopped` |
| `POST` | `/v1/research/{id}/followups` | Follow-up question, revision, go deeper, refresh → a new run with `parent` |

### 7.2 Start request

```json
{
  "question": "Welche Anbieter liefern X in Deutschland, zu welchen Preisen und Bedingungen?",
  "depth": "standard",
  "sources": { "web": true, "knowledge": ["DESKTOP:42"], "library": false },
  "language": null,
  "output": "auto",
  "instructions": "Prefer manufacturer and official sources. Ignore forums.",
  "context": { "turns": [ { "role": "user", "text": "…" }, { "role": "assistant", "text": "…" } ] },
  "models": { "lead": "anthropic:claude-sonnet-5-5:chat", "reader": "openai:gpt-6-luna:chat" },
  "world": { "providers": ["anthropic", "openai", "groq", "ollama"] },
  "seed_sources": [],
  "parent": null
}
```

Validation: question 1–4,000 characters; `instructions` ≤ 4,000; `context`
≤ 6 turns, 2,000 characters each; `knowledge` group keys must belong to the
caller's scopes; model keys must be active `chat` rows inside `world`; unknown
fields are rejected with the field named.

### 7.3 State

`status` (`briefing`, `waiting_answer`, `planning`, `waiting_plan`,
`gathering`, `writing`, `checking`, `complete`, `stopped`, `failed`), depth,
brief, plan with point statuses, sources (id, kind, title, host or `file_id`,
published, status, reason), report (Markdown, when written), check summary,
outcome, models used, budget used, cost (`estimate`, `spent`), `created_at`,
`expires_at`.

### 7.4 Input

```json
{ "type": "answer", "question": "q1", "choice": "EU" }
{ "type": "skip_question", "question": "q1" }
{ "type": "approve_plan" }
{ "type": "edit_plan", "points": [ { "id": "p1" }, { "title": "Delivery times" } ] }
{ "type": "add_note", "text": "We already work with supplier B." }
{ "type": "add_source", "url": "https://…" }
{ "type": "stop_gathering" }
```

### 7.5 Events and outcomes

| Event | Data |
| ----- | ---- |
| `run` | id, depth, status |
| `brief` | goal, language, output, time frame |
| `question` | id, text, options, `allow_text` |
| `plan` | points (id, title), outline, estimate (minutes, cost) |
| `plan_waiting` | — |
| `search` | point, query, scope, results |
| `source` | id, kind, title, host or file, status (`found`, `reading`, `read`, `blocked`), reason |
| `round` | n, max |
| `point` | id, status |
| `writing` / `section` | section title |
| `checking` / `check` | statements, supported, softened, removed |
| `complete` | outcome |
| `stopped` / `failed` | outcome |
| `ping` | every 15 s |

**Outcome codes** (closed enum; clients localize by code and params, the
English sentence is a fallback):

| Code | When | English sentence (U8) |
| ---- | ---- | --------------------- |
| `complete` | All points covered | "Done: {statements} statements, {supported} backed by a source." |
| `complete_partial` | Budget or no progress left points open | "Done with gaps: {open} of {points} points could not be confirmed. They are listed under Not confirmed." |
| `complete_snippets_only` | No page could be read | "No page could be opened, so this report is based on search summaries only. It says so where it matters." |
| `stopped_by_user` | Stop | "Stopped. Nothing was charged after you pressed Stop; what was found so far is kept." |
| `stopped_budget` | Monthly budget reached | "Stopped because your monthly AI budget is used up. The report covers {covered} of {points} points." |
| `stopped_waiting` | Plan or answer not given in time | "Stopped because the plan was not confirmed. Start it again when you are ready." |
| `failed_no_sources` | Web off or unavailable and no files | "There was nothing to research: turn on the web or add files to this project." |
| `failed_role_unavailable` | A role cannot be filled in the world | "The {role} model of this project is not available, and no other model in this project can stand in. Nothing was charged." |
| `failed_model_error` | A role kept failing after retries | "The {role} model stopped answering. Nothing else was charged; try again or pick another model." |
| `failed_interrupted` | State lost | "The research was interrupted on the server. Start it again — the sources found so far are kept here." |

No outcome ever contains an HTTP code, a provider message or a stack trace.

---

## 8. Security and privacy

| Concern | Rule |
| ------- | ---- |
| Prompt injection from sources | Readers only; no tools; delimited data; lead, writer and checker see evidence cards only; actions validated against a closed schema (§0 #3) |
| Network | Every fetch through `UrlContentService` / `SsrfGuard`; robots.txt and noindex respected; social and login-walled hosts skipped (existing lists) |
| Documents from the web | Size and time caps; extracted in the run and discarded — never stored as the person's file unless they save the report with it |
| Knowledge | Only the caller's owner scopes and folders shared at "Can use" (`RagScope`), as in chat |
| Writes | None. Saving, mailing, uploading are separate actions under `ToolExecutionGate` / Approvals (§0 #13) |
| Logs | Ids, counts, timings, model keys — never question, source text, quotes or report |
| Retention | Run state expires 48 h after its last change; nothing persists server-side unless the client saves it |
| Account deletion | `UserDeletionService` deletes `research:*` keys of the user (code cleanup V4 S0: every user-owned store is erased) |
| Sovereignty | World rule (`02` §7.2); models listed on every run |

---

## 9. Code shape

```text
backend/src/Service/Research/                       existing: UrlContentService, WebResearchService, ReadPagesResult
backend/src/Service/Research/Run/
  ResearchRunService.php        start, state, input, cancel, follow-ups
  ResearchRoleResolver.php      roles, world rule, cross-family rule (not in ModelConfigService — frozen at 1,327 lines)
  ResearchBudget.php            limits from BCONFIG, spend
  ResearchStateStore.php        Redis state, checkpoints, TTL
  ResearchEventLog.php          sequenced, resumable events
  ResearchOutcome.php           closed codes + params
  Ledger/  EvidenceLedger.php · Source.php · EvidenceCard.php · QuoteVerifier.php
  Stage/   BriefStage · PlanStage · GatherStage · ReadStage · ReflectStage · WriteStage · CheckStage
  Source/  ResearchSourceReader.php (HTML via UrlContentService, documents via the extraction chain, knowledge chunks)
backend/src/Message/RunResearchCommand.php
backend/src/MessageHandler/RunResearchCommandHandler.php
backend/src/Controller/ResearchApiController.php    /v1/research*
backend/src/Controller/ResearchController.php       /api/v1/research*
backend/src/AI/StructuredOutput/Schema/Research{Brief,Plan,Triage,Evidence,Decision,Check}Schema.php
backend/src/Module/Chat/ResearchModule.php
backend/src/Seed/ResearchConfigSeeder.php
backend/src/Prompt/PromptCatalog.php                + tools:research_* (catalog data, exempt from the size ratchet)
```

- Controllers stay under 50 lines per method; all logic in services.
- Stages share one `StageContext` (run, ledger, budget, roles, emitter) so no
  constructor exceeds ten parameters.
- deptrac: `Service\Research\Run\*` is Application layer; it calls `AiFacade`,
  `WebSearchGateway`, `VectorSearchService`, `RateLimitService`; no entity
  imports a service.
- Mobile impact: `backend/**` is already classified `backend-only`.

**Frozen files the quick wins touch** (size ratchet, `backend/tests/Architecture/sizeRatchetBaseline.json`):

| File / method | Frozen at | Consequence |
| ------------- | --------- | ----------- |
| `GatewayToolLoop.php` | 1,432 lines, constructor 14, `runComplete` 148, `runStream` 116, `executeOurs` 125, `collectStreamedTurn` 191 | RS1 edits constants and strings only; RS2 / RS3 add tools as classes reached through `GatewayToolCatalog` (502 lines) and a tagged native-tool registry, not new constructor parameters or branches |
| `MessagesContextInjector::sessionBlock` | 109 lines | RS3 moves its search call into the helper `knowledge_search` shares (the method shrinks); what the injection returns stays the same |
| `UrlContentService.php`, `fetchForReading` | 1,258 lines, 122 | RS4 reads documents in `ResearchSourceReader`; `deepen` swaps one call, does not grow |
| `WebResearchService::deepen` | 122 lines | Same |
| `ModelConfigService.php` | 1,327 lines | RS5 resolution lives in `ResearchRoleResolver` |

---

## 10. Hybrid knowledge search (RS9)

- **Meaning:** today's vector search (`VectorSearchService`), owner scopes.
- **Keyword:** a Qdrant full-text payload index on the chunk text of
  `user_documents` (collection configuration, no MariaDB schema change) queried
  with the same scopes; MariaDB VECTOR installs get the keyword leg only if a
  FULLTEXT index is approved (§0 #14).
- **Fusion:** reciprocal rank fusion, reusing the fusion code of
  `SmartSearchService`; then `RerankStage` when a reranker is allowed in the
  world.
- **Document cards:** at indexing time for knowledge folders, the reader model
  writes a short card per file (title, date, parties, topics, one-paragraph
  summary). Gather triages files by card before reading chunks — the "which of
  these 50 contracts matter?" step. Costed and shown as "Reading 23 files" in
  the folder's status. Off unless the folder is used for research.
- **Measure:** extend `app:rag:eval-rerank` with a `--hybrid` pass on the
  fixture corpus (planted article numbers, product codes, names).

The same search backs the gateway's `knowledge_search` tool (RS3 starts with
vector-only and gains the keyword leg here).

---

## 11. Follow-ups and changes (RS10)

| Follow-up | What the engine does |
| --------- | -------------------- |
| Question about the report | `quick` depth on the parent ledger; searches only if the ledger has nothing on it; answer with citations; offers **Add to report** |
| Revise ("shorter", "for the board", "translate to French", "add a comparison table", "rewrite section 2") | Writer on the parent ledger and report; citations preserved; check re-run on changed sentences |
| Go deeper | Thorough budget on top of the parent ledger; the plan gains points from Not confirmed |
| Refresh | Re-gather points whose sources are older than the brief's time frame; mark what changed |

Every follow-up produces a new run with `parent`; the client keeps versions
(Desktop: earlier report versions in the project; web: `DocumentRevision` on
the report file).

---

## 12. Gateway quick wins (RS1–RS3)

| Step | Change | Default | Use case that turns green |
| ---- | ------ | ------- | ------------------------- |
| RS1 | `WRAP_UP_AFTER_SEARCH` becomes length-neutral: "Answer the user's question now from these results, as completely as the question needs, and cite the URLs you use. Do not search again." `WEB_SEARCH_WRAP_AFTER` reads `MESSAGES_GATEWAY.WEB_SEARCH_MAX_ROUNDS` (default 2, today's value). | Today's round count | UC-03 stays green; UC-04 answers keep the full content |
| RS2 | For non-Anthropic upstreams, the `web_fetch` declaration is replaced by an executable `web_fetch` of the same name that Synaplan runs (`UrlContentService::fetchForReading`, condensed with the reader model when long) — the same move `WebSearchTool` made for `web_search`. Anthropic keeps its own server tool. `WEB_FETCH_MODE=off` still turns it off. | On with `WEB_FETCH_MODE=auto` after UC-04 is green on Groq, OpenAI and Gemini | UC-04 page facts on every route |
| RS3 | When `x-synaplan-rag-group-key` (or an assistant with knowledge scopes) is present, the gateway offers `knowledge_search(query, limit)` and `read_document(file_id, around)`, executed by Synaplan inside the caller's scopes. The single injection stays for the first turn. | On for paired desktop keys; off for other keys until the coding-client walk | New UC-05e: a question that needs three files |

**One knowledge search path.** MCP `rag_search` already serves paired keys
over `/mcp`, but it searches only the caller's own documents
(`VectorSearchService::semanticSearch`, no shared folders, no assistant
scopes) and lives outside the gateway loop, so a model on a `/v1/messages` turn
cannot call it. `knowledge_search` is the gateway-owned twin: it shares one
search helper with `MessagesContextInjector` (`semanticSearchByVector` with the
explicit scopes), and RS9 adds the keyword leg to that helper once.

**Desktop contract for RS2 and RS3.** Desktop's agent loop treats `web_search`
and `web_fetch` as server tools it must never run or answer
(`agent.rs::SERVER_TOOL_NAMES`, `is_server_tool_use_block`), and replays only
matched server-tool pairs. The executable replacements run inside the gateway
loop exactly where `WebSearchTool` runs today — including requests that mix
client tools (`write_file`) with server tools — so Desktop never receives an
unpaired call. `knowledge_search` and `read_document` are gateway-owned the
same way; Desktop adds both names to its server-tool list in RD1. A
fixture-based test on each side covers the mixed case.

---

## 13. Research as a Saved Task step (RW3)

A `research` capability for the planner and Saved Tasks: `quick` or
`standard` depth, web and folder sources, no questions, no plan review, report
delivered as the step's text and file. Use case 3 (weekly regulation brief)
becomes one step: research → email me. Approvals apply only to the steps that
write (email, save to folder), as today.
