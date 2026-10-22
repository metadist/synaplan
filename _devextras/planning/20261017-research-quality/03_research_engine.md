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
  `input` (§7.4). **Question timeout policy (one rule everywhere):** after
  10 minutes without an answer the sweeper treats every open question as
  skipped and dispatches the next unit — the person started this run and
  Skip is the documented default. Only an unconfirmed **plan** (Thorough,
  §2.2) ends the run, as `stopped_waiting` after 24 hours.

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

- Web: `WebSearchGateway::search()` per query with language and freshness —
  always Synaplan's own provider, never a model's built-in search tool, so every
  model sees the same results; results deduplicated by canonical URL and host;
  at most N per host. In a local or EU world a hosted provider is disclosed on
  the plan card or refused under strict mode (§0 #29).
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
  office files through the document extraction chain with a 20 MB, 15-second
  cap (§0 #12), **filtered by world**: today's `extractionChain($family,
  $hasCloudStt)` flag selects the audio chain only, so RS4 adds a cloud marker
  to the extractor contract and a filtered chain (`pdf_vision` and every other
  cloud-backed adapter dropped) for local and EU worlds, with a test that a
  local-world PDF never reaches a cloud vision model; knowledge files as the
  matching chunks plus their neighbours, or the whole document when it fits the
  reader's budget. Page fetches overlap like
  `WebResearchService::deepen()` does today (first hop started for all, then
  consumed).
- **Passages (§0 #39):** before any model sees it, the engine splits the raw
  extracted text into numbered passages (`§1…§n`: paragraph, table row, list
  item; a paragraph over 600 characters into sentences) and keeps the map with
  the source's raw text. The reader sees the text with its passage numbers.
- Reader call (`ResearchEvidenceSchema`): one source, the plan's points as the
  lens → an **evidence card** per useful finding (§5), each citing one or more
  passage ids, optionally with a short quote; plus up to five **leads** —
  links on the page that are worth reading for a point (§0 #38). Readers have
  no tools; the source text is wrapped and labelled as untrusted data (`04` §3).
- **Concurrent readers (§0 #37):** the reader calls of one unit run at once,
  up to `BUDGET.<DEPTH>.READ_PARALLEL` (4 · 6 · 8), through one
  `ConcurrentStructuredCaller` that multiplexes the HTTP requests, records
  usage per request and checks the cancel flag before each one. Local worlds
  use `LOCAL_PARALLEL`.
- **Passage check (deterministic):** every cited passage id must exist in the
  source's map; a quote, when present, is normalised (whitespace, quotes,
  hyphenation, decimal and thousands separators) and must be found **inside
  the cited passages of the raw extracted text** — never in a condensed
  version. A finding with no existing passage is dropped; a quote that is not
  found is removed from the card, the passage citation stays. The share of
  dropped findings per reader model is recorded (`06` §3). This check is free
  and is the floor of citation integrity.
- **Leads:** the engine keeps a lead only when its URL is in the link list the
  extractor took from the page itself (canonicalised); kept leads enter the
  ledger as `found` sources with `origin: link` and the point they serve, and
  are read like any other source (SSRF guard, robots, budget).
- Long sources: `ContextCondenser` first narrows the text to the parts
  relevant to the points (extractive, then the **run's reader model** — the
  engine hands the model in; the condenser's own SORT → ANALYZE → CHAT order is
  not used), then the reader reads. Input per call is bounded by the reader
  model's context window (`00` §0 #31); a source that does not fit is read in
  its first part and the Details say so.
- **Politeness and caching:** at most `FETCH_PER_HOST` (1) in-flight fetch per
  host per run, `Retry-After` honoured, robots.txt as today; the extracted text
  of a URL is cached for `FETCH_CACHE_MINUTES` (60) keyed by URL only, never by
  question or person.
- **Retention:** the raw text of every source stays in the run's Redis state
  (so `reread` and the check can use it) and is deleted at `deliver` after the
  check, or with the run's TTL — it is never stored as the person's file.
- **Titles are data:** titles and snippets from search results are truncated
  (120 / 300 characters), control characters stripped, and shown to the lead
  inside the same data wrapper as source text.
- Events `source` with status `reading`, `read` (cards count) or `blocked`
  (reason: login wall, robots, timeout, too large, no text).

### 2.5 Reflect (lead) — the loop

The lead runs in one of two modes (§0 #2); budgets, validation, stop rules and
the quarantine are the same in both.

#### 2.5a Tool mode (default where the lead model qualifies)

The lead model gets the plan, the ledger summary and the budget, and five
tools it calls natively, one or several per turn:

| Tool | Arguments | Returns (never raw page or file text) |
| ---- | --------- | ------------------------------------- |
| `search` | `point`, `query`, `language`, `scope` | result list: id, sanitised title (120), host, kind, date, snippet (300), inside the data wrapper |
| `read_sources` | `ids[]` (found or lead sources), `point` | one evidence card per source (facts with passage ids, leads), or `blocked` with the reason; the readers run concurrently |
| `reread` | `source`, `question` | evidence cards for the sharper question |
| `ask_user` | `question`, `options[]` | the answer or `skipped` (counts against the question budget; the run waits without a worker) |
| `finish` | `reason` (`covered` · `budget` · `no_progress`), `points[]` with status and note | ends the loop |

- A tool call beyond its budget does not run; it returns
  `{"error": "budget_used", "left": {…}}` so the model can only move on to
  `finish`. Unknown ids and repeated queries return a short error the same way.
- **Durability:** one `round` unit is one lead turn plus the execution of its
  tool calls. The lead conversation (tool calls and their card results) is
  checkpointed in the run state after every unit, so a lost worker repeats at
  most one turn.
- **Context:** once the conversation passes 60 % of the lead's input budget
  (§0 #31), the engine replaces older tool results with the current ledger
  summary and keeps the tool-call records (tool-result clearing); the plan and
  the brief stay at the top and are cached (§0 #32).
- **Hard stops** are the engine's, not the model's: Stop / Write now, every
  budget, the wall clock, `MAX_MODEL_CALLS`. A lead that ends a turn without a
  tool call and without `finish` gets one reminder, then the engine finishes
  with `no_progress`.
- Events are the same as in round mode (`search`, `source`, `point`, and
  `round` per lead turn), so clients do not know which mode ran.

#### 2.5b Round mode (fallback)

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
  can make; counts against the question budget). `finish` is **not** an action:
  it is the top-level field of the decision (`null`, `covered`, `budget` or
  `no_progress`, `10` §1.5); when it is set, `actions` must be empty and the
  engine ignores any that are present.
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
- The report is written **in one call** whenever the writer's
  `meta.max_output` covers the depth's word budget (§0 #42) — Standard always,
  Thorough on every current frontier writer. A writer whose output cap is too
  small writes section by section, **sequentially**, each call seeing the
  sections already written, and the summary last; sections are never written
  in parallel.
- Evidence ids become the reader-facing numbers `[1]…[n]` per source in
  delivery (§5.3).
- Event `writing` (section) and `section` (title) as each section is ready.

### 2.7 Check (checker)

1. **Deterministic:** every cited id exists; every cited card cites at least
   one existing passage; numbers and dates in the sentence appear in the cited
   passages
   after normalisation (`12,40` = `12.40`, `1.234,5` = `1,234.5`, units and
   percent signs stripped, dates to ISO); sentences that state facts carry a
   citation (a sentence with a number, a date or a proper name and no citation
   is flagged); a revision that introduces a fact without a citation is
   rejected and the pre-revision text kept.
2. **Checker model** (`ResearchCheckSchema`), batched: each cited statement
   with the text of its cited passages (short excerpts, inside the data
   wrapper — the same exposure the quotes had) → `supported`, `partial` (which part is not), `unsupported`,
   `contradicted`.
3. **Revision:** a verdict other than `supported` never stays in the factual
   text. Thorough runs one writer revision: `partial` → only the supported
   part stays; `unsupported` and `contradicted` → removed, and listed under
   Not confirmed when they matter to the goal. Standard, which has no revision
   call, removes every `partial`, `unsupported` and `contradicted` statement
   whole and lists it under Not confirmed. `contradicted` means the draft says
   the opposite of its own cited quote — it is a writing error, not a
   disagreement between sources; disagreements between sources arrive as
   conflict cards from the readers and are shown as two versions with dates
   (§2.6). A statement is never "fixed" with knowledge that has no source.
4. **Result sentence** in the report and the run summary:
   "35 of 37 statements are backed by a source. 2 were removed because no source
   confirmed them."
- Event `checking`, then `check` (statements, supported, softened, removed).

### 2.8 Deliver

- Final state: report with its citation map (`10` §2.5), sources (with
  URL or file id and the verified quotes actually cited), run summary,
  outcome, models used, cost.
- Event `complete` (or `stopped` / `failed` — §7.5).
- **Delivery does not depend on a connected client.** A web run carries the
  `chat_id` it was started from (§7.2); the `deliver` unit itself writes the
  assistant message and the report file (with a `DocumentRevision`) into that
  chat, so a person who closed the tab finds the report in the chat list and
  the Library (U2). A Desktop run has no server-side chat; the state and its
  report stay readable for the TTL, Desktop reattaches through
  `GET /v1/research?active=1` and writes `out/<slug>.md` and
  `out/<slug>.sources.json` from the final state when it next connects.

---

## 3. Why two lead modes

The baseline thesis (`01` §1) is that an IDE agent is better because the model
**drives** the work and sees every result before its next step. Round mode
alone would not deliver that: the lead decides a batch of actions, waits for
all of them, and can react only in the next round. Current deep-research
systems (Anthropic's research system, Gemini Deep Research, LangChain's Open
Deep Research — `08` §2b) all let the model call the tools. What makes the
plan safe is not who decides, but what the tools return and who enforces the
budget — and both stay with the engine.

| | Round mode (structured decisions) | Tool mode (native tool calls) |
| - | --------------------------------- | ----------------------------- |
| Providers | Any model with JSON output, including local models without tool calling | Models on `TOOL_MODE_MODELS` after the conformance suite |
| Reacts to a result | Next round only | Next turn — search, look, search again |
| Known failure classes | JSON drift → healing retry exists | Desktop CHANGES C2, C4, C10 — the reason for the conformance suite and the fallback |
| Parallelism | The engine runs a decision's searches and reads together | Parallel tool calls per turn; `read_sources` reads concurrently either way |
| Budget and progress | Engine-enforced | Engine-enforced inside every tool; same events |
| Quarantine | Cards and sanitised result lists only | Same |
| Testability | `TestProvider` replays decisions | `TestProvider` replays tool-call turns (the gateway loop tests are the precedent) |

**Conformance suite** (`app:research:conformance <catalog key>`): twenty
scripted turns on the test doubles — parallel calls, a tool error, a budget
answer, a long tool result, `finish` with points — through the path the run
uses: `AiFacade::chat()` with `tools`, whose providers normalise native calls
to `ToolCall` via `ToolCallParser` (the loop precedent is
`Service/Document/ChatToolLoop.php`). A model that passes every turn twice is added to
`TOOL_MODE_MODELS` by RQ3; a provider change that breaks a listed model shows
up in the next run of the suite.

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
| Reader calls at once (§0 #37) | 4 | 6 | 8 |
| Wall clock | 60 s | 6 min | 20 min |
| Cost cap per run (catalog currency) | 0.10 | 1.00 | 5.00 |
| Clarifying questions | 0 | ≤ 1, only if ambiguous | ≤ 3 |
| Plan review | none | visible, starts at once | waits for **Start research** |
| Fact-check | deterministic only | deterministic + checker | deterministic + checker + one revision |
| Report length | ≤ 300 words | ≤ 1,500 words | ≤ 6,000 words, section by section |

These counts are **starting hypotheses** (§0 #41). External deep-research
agents use about 80 searches for a typical task; RQ2b and RQ3 sweep searches
and sources (Standard 10 / 20, Thorough 30 / 60), plot fact recall against cost,
and seed the knee of the curve.

Two more hard stops per depth: `MAX_ACTIONS` per reflect decision (6 / 10)
and `MAX_MODEL_CALLS` per run (quick 6, Standard 60, Thorough 200) — the
latter ends a pathological loop as `complete_partial` whatever the other
counters say. The full key table with defaults and ranges is `10` §4.

Local-world runs use the same counts with a wall clock first: readers run with
`RESEARCH.LOCAL_PARALLEL` (1) because one GPU serialises them anyway; when the
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
      "passages": ["§14"],
      "quotes": [ { "text": "Grundpreis netto 12,40 € je Einheit", "passage": "§14", "verified": true } ],
      "confidence": "high" }
  ],
  "leads": [ { "source": "s15", "from": "s3", "point": "p3", "anchor": "Allgemeine Geschäftsbedingungen (PDF)" } ],
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
- Opening `[3]` shows the source title, host or file, date and the cited
  passages (with the quote highlighted when there is one) used for *that*
  sentence, with **Open page** / **Open file**.
- The sources list at the end of the report gives each source once, with what
  it was used for.

---

## 6. Durability

- **State:** Redis `research:run:{id}` (ledger + checkpoint + expected unit
  sequence), `research:events:{id}` (sequenced events),
  `research:text:{id}:{sourceId}` (raw source text, dropped at `deliver`),
  `research:active:{userId}` and `research:active` (concurrency caps),
  `research:idem:{userId}:{key}` (24 h). TTL `STATE_TTL_HOURS` (48) from the
  last change (§0 #7). Rough size: 1–3 MB per Thorough run while it runs,
  a few hundred KB after `deliver`.
- **Execution in units (§0 #8):** `RunResearchUnit { runId, unit, seq }` on
  `async_ai_high`. One message does one bounded unit — `brief_plan`, one
  `round` (round mode: execute the previous decision's actions, then one
  reflect call; tool mode: one lead turn plus its tool calls, §2.5a),
  one `write` call (or one section for a small writer), `check`, `deliver` — within `UNIT_SECONDS`
  (120), writes the checkpoint, advances `seq`, dispatches the next unit and
  returns.
- **Exactly one execution per unit, also with several workers:** before any
  paid call the handler takes an atomic claim `research:claim:{id}:{seq}`
  (`SET NX PX` with a lease of 2 × `UNIT_SECONDS` and a random token); a
  delivery that does not get the claim exits — a Messenger retry, a double
  dispatch and two workers racing all end the same way. Every checkpoint write
  is fenced with the token (a Lua compare-and-set), so a worker whose lease
  expired cannot overwrite a newer checkpoint. The sweeper (§0 #28) recovers an
  expired lease by re-dispatching the same `seq`. A test delivers the same
  unit twice concurrently and asserts one set of provider calls.
- **Fairness on the single consumer:** `messenger:consume async_ai_high
  async_extract async_index` serves the receivers in that order, so a queue
  that is never empty starves the other two. The next unit is therefore
  dispatched with a `DelayStamp` of `RESEARCH.UNIT_GAP_MS` (3,000): the Redis
  transport holds it in its delayed set, `async_ai_high` is momentarily empty,
  and extraction and indexing messages are served in between. `MAX_ACTIVE_RUNS`
  bounds how many such chains exist at once. The dev-stack check for this
  (an upload indexed while a Thorough run is active) is an RS6 exit item.
- Cancel is checked at every unit start, between sources and **before each
  paid model call**; a provider request already in flight when Stop arrives
  finishes and may be billed — the outcome copy says so (§7.5), and the cost
  line shows the real spend.
- **Queue wait:** when the next unit has not started 20 s after dispatch, the
  state emits `queued { since_seconds }` every 20 s; after 5 minutes the module
  status says "No worker has picked up research units for 5 minutes."
- **Waiting:** a run waiting for an answer or plan approval holds no worker;
  `input` dispatches the next unit.
- **Sweeper (§0 #28):** `app:research:reap` runs in the scheduler's `tick`
  lane. Three transitions, each tested: a claim whose lease expired with the
  unit not checkpointed → re-dispatch the same `seq` once, then on a second
  expiry `failed_interrupted` with the report of what was found (the `write`
  unit runs once on the ledger as it is); `waiting_answer` past 10 minutes →
  open questions marked skipped and the next unit dispatched (§2.1);
  `waiting_plan` past 24 hours → `stopped_waiting`.
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
| `GET` | `/v1/research/capabilities` | `enabled`, `research_protocol`, depths, `web_search_available` and the provider's hosting, `knowledge_available` and the index model, `read_documents`, `strict_world`, `max_active_thorough`, plan limits (`10` §2.2). **404 when the flag is off** — the client hides the feature |
| `POST` | `/v1/research` | Start a run → `202 { id, status, events, research_protocol: 1 }`; header `Idempotency-Key` (≤ 64 chars, 24 h) returns the same `id` for a retried start (§0 #33) |
| `GET` | `/v1/research?active=1` | The caller's runs of the last 48 h (id, status, depth, question, created_at) — Desktop reattaches from it |
| `GET` | `/v1/research/{id}` | Current state (§7.3) |
| `GET` | `/v1/research/{id}/events` | SSE, resumable (`Last-Event-ID`, `?after=`) |
| `POST` | `/v1/research/{id}/input` | Answer a question, approve or edit the plan, add a note or a source, write now |
| `POST` | `/v1/research/{id}/cancel` | Stop; terminal `stopped` |
| `POST` | `/v1/research/{id}/followups` | Follow-up question, revision, go deeper, refresh → a new run with `parent` |
| `DELETE` | `/v1/research/{id}` | Removes state, events and cached source texts at once (Desktop's "Delete report" calls it) |

An id that is not the caller's answers 404, never 403. Full API keys reach
these routes like paired keys; the `RESEARCH` rate-limit action applies to
every caller.

**Module off (§0 #35).** `capabilities`, `POST /research`, `followups` and
`input` answer 404 — nothing new starts and no UI shows (U11). `GET
/research?active=1`, `GET /research/{id}`, `GET …/events` and `DELETE` keep
working for the caller's own runs until their TTL, so a person can still read
the `stopped_by_admin` outcome, the partial findings and delete them. A unit
that starts while the module is off ends its run as `stopped_by_admin` after
writing what was found. `ResearchModule::routeNames()` lists only the four
gated routes.

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
  "chat_id": 4711,
  "models": { "lead": "anthropic:claude-sonnet-5-5:chat", "reader": "openai:gpt-6-luna:chat" },
  "world": { "providers": ["anthropic", "openai", "groq", "ollama"] },
  "seed_sources": [],
  "parent": null
}
```

Validation: question 1–4,000 characters; `instructions` ≤ 4,000; `context`
≤ 6 turns, 2,000 characters each; `knowledge` group keys must belong to the
caller's scopes; model keys must be active `chat` rows inside `world`;
`seed_sources` ≤ 50 and every URL passes the SSRF guard; unknown fields are
rejected with the field named. An optional `assistant_id` (the web's pinned
assistant or Desktop's bound one) sets the source defaults: web off when the
assistant's `tools.internet` is off, its knowledge scopes added to `knowledge`.
`chat_id` is required on the session route and must be a chat the caller owns:
the run's `deliver` unit writes the report into it (§2.8). It is absent on
`/v1/research` — Desktop and API callers persist the result themselves.

### 7.3 State

`status` (`briefing`, `waiting_answer`, `planning`, `waiting_plan`,
`gathering`, `writing`, `checking`, `complete`, `stopped`, `failed`), depth,
brief, plan with point statuses, sources (id, kind, title, **`url`** or
**`file_id`**, host, published, class, status, reason), **evidence** (id,
source, point, fact, verified quotes with location), report (Markdown plus a
**citation map**: for every `[n]` occurrence its sentence, source id and
evidence ids — the shape is `10` §2.5), check summary, outcome, models used,
budget used, cost (`estimate`, `spent`), `created_at`, `expires_at`. A client
needs nothing beyond the state to render **Open page**, the quotes used for a
sentence and the sources list, and to write `.sources.json` after a reconnect.

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
| `run` | id, depth, status, `research_protocol: 1` (first frame after every attach; clients ignore unknown event names) |
| `queued` | `since_seconds` — the next unit waits for a worker, every 20 s |
| `brief` | goal, language, output, time frame |
| `question` | id, text, options, `allow_text` |
| `plan` | points (id, title), outline, estimate (minutes, cost), disclosures (world rule, §0 #29) |
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
| `stopped_by_user` | Stop | "Stopped. No new work was started after you pressed Stop; a step that was already underway may still count. What was found so far is kept." |
| `stopped_budget` | Monthly budget reached | "Stopped because your monthly AI budget is used up. The report covers {covered} of {points} points." |
| `stopped_waiting` | The plan was not confirmed within 24 hours (questions never stop a run — §2.1) | "Stopped because the plan was not confirmed within a day. Start it again when you are ready." |
| `failed_no_sources` | Web off or unavailable and no files | "There was nothing to research: turn on the web or add files to this project." |
| `failed_role_unavailable` | A role cannot be filled in the world | "The {role} model of this project is not available, and no other model in this project can stand in. Nothing was charged." |
| `failed_model_error` | A role kept failing after retries | "The {role} model stopped answering. Nothing else was charged; try again or pick another model." |
| `failed_interrupted` | State lost, or the sweeper found no live unit | "The research was interrupted on the server. Start it again — the sources found so far are kept here." |
| `stopped_by_admin` | The module was turned off while the run was active | "Research was turned off on your Synaplan workspace. What was found so far is kept." |

No outcome ever contains an HTTP code, a provider message or a stack trace.
"Nothing was charged" is only said when no model call ran at all in the run;
after a Stop the copy says "no new work was started" because a provider
request in flight may still be billed, and the cost line shows what was.

---

## 8. Security and privacy

| Concern | Rule |
| ------- | ---- |
| Prompt injection from sources | Readers only; no tools; delimited data; titles and snippets sanitised and wrapped as data too; lead, writer and checker see evidence cards only; actions validated against a closed schema (§0 #3) |
| Network | Every fetch through `UrlContentService` / `SsrfGuard` (also `add_source` and `seed_sources`); robots.txt and noindex respected; social and login-walled hosts skipped (existing lists); one in-flight fetch per host per run |
| Documents from the web | Size and time caps; extracted in the run, kept in the run's Redis state for `reread` and the check, dropped at `deliver` — never stored as the person's file unless they save the report with it |
| Knowledge | Only the caller's owner scopes and folders shared at "Can use" (`RagScope`), as in chat; an assistant's knowledge scopes when one is pinned |
| Writes | None. Saving, mailing, uploading are separate actions under `ToolExecutionGate` / Approvals (§0 #13) |
| Logs | Channel `research`, JSON: ids, unit, role, model key, durations, token counts, outcome — never question, title, source text, quotes or report (`10` §8) |
| Status | `ResearchModule::status()`: active, queued and failed-in-24h counts, "no worker picked up units for N minutes"; a row on the system status page |
| Retention | Run state expires `STATE_TTL_HOURS` after its last change; `DELETE` removes it at once; nothing persists server-side unless the client saves it |
| Account deletion | `UserDeletionService` deletes `research:*` keys of the user (code cleanup V4 S0 erases every user-owned table; Redis needs this explicit step and its own unit test) |
| Sovereignty | The five-leg world rule (§0 #29): models (`02` §7.2), search provider disclosed or refused, document extraction without cloud steps, condenser on the reader model, index model named or knowledge skipped under strict mode; every leg's decision is a `disclosures` entry on the plan and in Details; models listed on every run |
| Ownership | Every route answers 404 for an id the caller does not own |

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
  Ledger/  EvidenceLedger.php · Source.php · EvidenceCard.php · PassageSplitter.php · PassageVerifier.php
  Stage/   BriefStage · PlanStage · GatherStage · ReadStage · ReflectStage · WriteStage · CheckStage
  Lead/    LeadModeResolver.php (auto · tools · rounds, TOOL_MODE_MODELS) · LeadToolbox.php (the five tools, budget inside each)
           LeadConversation.php (tool-mode transcript, checkpointed, tool-result clearing)
  ConcurrentStructuredCaller.php  concurrent reader calls over AiFacade (§0 #37)
  Source/  ResearchSourceReaderInterface.php · ResearchSourceReader.php (HTML via UrlContentService, documents via extractionChain('document', allowCloud), knowledge chunks)
           FixtureSourceReader.php (test environment only — fixture.synaplan.test)
backend/src/Service/RAG/KnowledgeSearchHelper.php   shared by MessagesContextInjector, KnowledgeSearchTool and GatherStage (RS3, RS9)
backend/src/Message/RunResearchUnit.php             { runId, unit, seq }
backend/src/MessageHandler/RunResearchUnitHandler.php
backend/src/Command/ReapResearchRunsCommand.php     app:research:reap (ScheduledJobs::LANE_TICK)
backend/src/Command/ResearchConformanceCommand.php  app:research:conformance (tool-mode suite, §3)
backend/src/Command/ResearchSpikeCommand.php        app:research:spike (RQ2b, in-process, eval account only; removed or kept as a dev tool after the gate)
backend/src/Controller/ResearchApiController.php    /v1/research*
backend/src/Controller/ResearchController.php       /api/v1/research*
backend/src/AI/StructuredOutput/Schema/Research{Brief,Plan,Triage,Evidence,Decision,Check}Schema.php
backend/src/Module/Chat/ResearchModule.php
backend/src/Seed/ResearchConfigSeeder.php
backend/src/Plug/WebSearch/Adapter/TestWebSearchAdapter.php   test environment only
backend/src/Plug/WebSearch/WebSearchHosting.php     provider → self-hosted / country (world disclosure)
backend/src/Prompt/PromptCatalog.php                + tools:research_* (catalog data, exempt from the size ratchet)
_devextras/testing/research/fixtures/               wire fixtures, vendored by Desktop
backend/tests/Fixtures/research/{search,pages,injection}/   fixtures for the doubles
```

Signatures for every class are in [`10_contracts.md`](./10_contracts.md) §5.

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

The node is a **waiting node**, not an inline run: `RunSavedTaskCommandHandler`
calls `SavedTaskRunner::run()` synchronously, so executing the units inline
would hold the worker for the whole budget — the problem §0 #8 removes. The
runner starts the run (`chat_id` of the task's chat, no questions), records the
run id on the node and returns a *waiting* result, exactly as `AskUserRunner`
pauses a DAG; the `deliver` unit then resumes the Saved Task through the
existing resume path (`SavedTaskResumeService`) with the report as the node's
output. A run that ends `failed_*` fails the node with its outcome sentence;
dependents skip as they do for any failed node.
