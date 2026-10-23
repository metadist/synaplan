# Contracts — schemas, events, keys, classes

**Status:** Draft 2026-10-17 (review finding `08` G12). These are the shapes
an implementer codes against. They are binding for the step that first ships
them and are only changed with the step that changes them; the fixtures in §7
freeze the wire parts. Field names are `snake_case` on the wire (as the
Messages gateway and the desktop job contract are), `camelCase` in TypeScript
after the generated Zod schemas.

---

## 1. Structured-output schemas (model → engine)

Each is an `App\AI\StructuredOutput\Schema\*` class with `name()`, `schema()`
(JSON Schema draft 2020-12, additionalProperties false) and `example()`. The
engine validates every answer; a violation goes through the existing healing
retry once, then the stage fails with `failed_model_error` for that role.

### 1.1 `ResearchBriefSchema` (`tools:research_brief`)

```json
{
  "goal": "Which suppliers deliver X in Germany, at what prices and terms",
  "output": "comparison",
  "audience": "purchasing team",
  "language": "de",
  "time_frame": { "from": "2025-10-17", "to": "2026-10-17" },
  "region": "DE",
  "must_use": [],
  "must_avoid": ["forum"],
  "ambiguity": [
    { "id": "q1", "text": "Which region should the comparison cover?",
      "options": ["Germany", "EU", "Worldwide"], "allow_text": true }
  ]
}
```

`output` ∈ answer | report | table | comparison | list. `time_frame` nullable.
`ambiguity` max 3 items, options 2–4.

### 1.2 `ResearchPlanSchema` (`tools:research_plan`)

```json
{
  "points": [
    { "id": "p1", "title": "Who offers X in Germany", "why": "The goal depends on it.",
      "queries": [ { "q": "Anbieter X Deutschland 2026", "language": "de" } ],
      "scope": "web" }
  ],
  "outline": ["Summary", "Suppliers", "Prices and terms", "Not confirmed"],
  "estimate": { "minutes": 3, "cost": 0.35 }
}
```

`points` 3–7 (Standard 3–5, Thorough 4–7); `queries` 2–4 per point; `scope` ∈
web | files | both. `estimate` is filled by the engine from the budget table,
not by the model (the schema accepts it so one shape serves state and plan).

### 1.3 `ResearchTriageSchema` (`tools:research_triage`)

```json
{ "results": [
  { "id": "r7", "point": "p2", "kind": "official", "fresh": true, "read": true,
    "reason": "Price list on the manufacturer's site" }
] }
```

`kind` ∈ official | primary | academic | documentation | news | vendor | forum | other.

### 1.4 `ResearchEvidenceSchema` (`tools:research_read`)

```json
{
  "source_id": "s3",
  "useful": true,
  "published": "2026-03-01",
  "kind": "vendor",
  "findings": [
    { "point": "p2",
      "fact": "Base price 12.40 EUR per unit (net).",
      "passages": ["§14"],
      "quote": { "text": "Grundpreis netto 12,40 € je Einheit", "passage": "§14" },
      "confidence": "high" }
  ],
  "leads": [ { "link": "L7", "point": "p3" } ],
  "flags": []
}
```

`passages` 1–5 ids of the form `§<n>` from the passage map the engine built
(§0 #39); `quote` nullable, `text` ≤ 25 words, `passage` one of `passages`.
The engine drops a finding whose passages do not all exist, removes a quote
not found inside its passage (the finding stays), and stores `verified` on the
quote. `leads` ≤ 5, `link` an `L<n>` id from the link list it passed in; the
engine resolves it to the extracted URL or drops it (§0 #38). `flags` ⊂
outdated | opinion_or_advertising | contradicts_itself | partial_text |
machine_translation | unclear_author.

### 1.5 `ResearchDecisionSchema` (`tools:research_reflect`)

```json
{
  "points": [ { "id": "p1", "status": "answered", "note": "Two official sources agree." } ],
  "actions": [
    { "type": "search", "point": "p3", "query": "…", "language": "de", "scope": "web" },
    { "type": "read", "source": "s14" },
    { "type": "reread", "source": "s3", "question": "Net or gross, and since when?" },
    { "type": "ask_user", "question": "…", "options": ["…", "…"] }
  ],
  "finish": null
}
```

`status` ∈ answered | thin | conflicting | open. `finish` ∈ null | covered |
budget | no_progress is a **top-level field, not an action type** — the prompt
says so in the same words (`04` §4.5); when it is set, `actions` must be `[]`
and the engine ignores any present. Actions ≤ `MAX_ACTIONS` (6 Standard, 10
Thorough) — the engine drops the rest.

### 1.5a Lead tools and `ResearchFinishSchema` (`tools:research_lead`, tool mode)

Declared natively on the lead call (`AiFacade::chat()` with `tools`); the
arguments are validated like a schema answer, results are JSON strings.

| Tool | Arguments (JSON Schema, additionalProperties false) | Result |
| ---- | ---------------------------------------------------- | ------ |
| `search` | `{ point: "p<n>", query: string ≤ 200, language: ISO 639-1, scope: web \| files \| both }` | `{ results: [ { id, title, host, kind?, date?, snippet } ], left: { searches } }` |
| `read_sources` | `{ ids: ["s<n>"] 1–8, point: "p<n>" }` | `{ cards: [ ResearchEvidence §1.4 with ids e<n> ], blocked: [ { id, reason } ], left: { sources } }` |
| `reread` | `{ source: "s<n>", question: string ≤ 300 }` | same as `read_sources` for one source |
| `ask_user` | `{ question: string ≤ 300, options: [string] 2–4 }` | `{ answer }` or `{ skipped: true }` (the unit ends and the run waits) |
| `finish` | `ResearchFinishSchema`: `{ reason: covered \| budget \| no_progress, points: [ { id, status, note } ] }` | — (ends the loop) |

Any call the engine refuses returns `{ error: budget_used | unknown_id |
repeated_query | not_allowed, left: {…} }`. Titles and snippets are truncated
and sanitised as in `03` §2.4 and sit inside the data wrapper.

### 1.6 `ResearchCheckSchema` (`tools:research_check`)

```json
{ "verdicts": [
  { "statement": "st12", "verdict": "partial",
    "unsupported_part": "since January", "note": "The quote gives the price, not the date." }
] }
```

`verdict` ∈ supported | partial | unsupported | contradicted. The envelope is
the object with the single key `verdicts` — the prompt asks for exactly that
(`04` §4.7); a bare array is a schema violation.

---

## 2. Wire contracts (engine ↔ clients)

### 2.1 Endpoints

| Method | Path (`/v1/…` for API keys, `/api/v1/…` for the session) | Body / query | Returns | Module off |
| ------ | --------------------------------------------------------- | ------------ | ------- | ---------- |
| GET | `research/capabilities` | — | §2.2 | 404 |
| POST | `research` | §2.3; header `Idempotency-Key` (≤ 64 chars, 24 h) | 202 `{ id, status, events, research_protocol: 1 }`; same key → same `id` | 404 |
| GET | `research?active=1` | — | `{ runs: [ { id, status, depth, question, created_at } ] }` (last 48 h) | works (own runs) |
| GET | `research/{id}` | — | §2.5 | works (own runs) |
| GET | `research/{id}/events` | `Last-Event-ID` header or `?after=<seq>` | SSE, §2.6 | works (own runs) |
| POST | `research/{id}/input` | §2.4 | §2.5 | 404 |
| POST | `research/{id}/cancel` | — | §2.5 (`stopped_by_user`) | works |
| POST | `research/{id}/followups` | `{ type: question|revise|deeper|refresh, text?, section? }` | 202 new run with `parent` | 404 |
| DELETE | `research/{id}` | — | 204; state, events and cached source texts removed | works |

Auth: an API key needs `desktop:messages` (paired keys have it); full keys
pass; the session routes need the signed-in user. Every id belongs to the
caller or the answer is 404 (never 403, to avoid id probing).

### 2.2 Capabilities

```json
{
  "enabled": true,
  "research_protocol": 1,
  "depths": ["standard", "thorough"],
  "web_search_available": true,
  "web_search_provider": { "id": "brave", "self_hosted": false, "country": "US" },
  "knowledge_available": true,
  "knowledge_index_model": "ollama:bge-m3:vectorize",
  "read_documents": true,
  "strict_world": false,
  "max_active_thorough": 1,
  "limits": { "standard_per_day": 3, "thorough_per_day": 0 }
}
```

### 2.3 Start request

```json
{
  "question": "…",
  "depth": "standard",
  "sources": { "web": true, "knowledge": ["DESKTOP:42"], "library": false },
  "language": null,
  "output": "auto",
  "instructions": "Prefer official sources.",
  "context": { "turns": [ { "role": "user", "text": "…" } ] },
  "chat_id": 4711,
  "assistant_id": null,
  "models": { "lead": "anthropic:claude-sonnet-5-5:chat", "reader": null, "writer": null, "checker": null },
  "world": { "providers": ["anthropic", "openai", "groq", "ollama"] },
  "seed_sources": [ { "url": "https://…", "title": "…" } ],
  "parent": null
}
```

Validation (400 with `{ error: { code, field, message } }`): `question`
1–4,000 chars; `instructions` ≤ 4,000; `context.turns` ≤ 6 × 2,000;
`knowledge` keys inside the caller's scopes; `models.*` active `chat` rows
inside `world.providers`; `seed_sources` ≤ 50, URLs pass the SSRF guard;
`chat_id` required on `/api/v1/research` and must be the caller's chat (the
`deliver` unit writes the report into it), rejected on `/v1/research`; unknown
fields rejected.

### 2.4 Input

```json
{ "type": "answer", "question": "q1", "choice": "EU" }
{ "type": "answer", "question": "q1", "text": "Austria and Switzerland" }
{ "type": "skip_question", "question": "q1" }
{ "type": "approve_plan" }
{ "type": "edit_plan", "points": [ { "id": "p1" }, { "title": "Delivery times" } ] }
{ "type": "add_note", "text": "We already work with supplier B." }
{ "type": "add_source", "url": "https://…" }
{ "type": "stop_gathering" }
```

### 2.5 State

```json
{
  "id": "rr_01JAB…", "research_protocol": 1,
  "status": "gathering", "depth": "standard",
  "brief": { … §1.1 … },
  "plan": { "points": [ { "id": "p1", "title": "…", "status": "answered" } ], "outline": [ … ] },
  "sources": [ { "id": "s3", "kind": "web", "title": "…", "url": "https://lieferant-a.example/preise",
                 "host": "lieferant-a.example", "file_id": null,
                 "published": "2026-03-01", "class": "vendor", "status": "read", "reason": null } ],
  "evidence": [ { "id": "e12", "source": "s3", "point": "p2", "fact": "Base price 12.40 EUR per unit (net).",
                  "passages": [ { "id": "§14", "text": "Grundpreis netto 12,40 € je Einheit, zzgl. Versand." } ],
                  "quote": { "text": "Grundpreis netto 12,40 € je Einheit", "passage": "§14", "verified": true } } ],
  "report": {
    "markdown": "… Der Grundpreis liegt bei 12,40 € netto je Einheit. [3] …",
    "version": 1,
    "citations": [ { "n": 1, "marker": "[3]", "source": "s3", "evidence": ["e12"],
                     "sentence": "Der Grundpreis liegt bei 12,40 € netto je Einheit." } ],
    "sources_order": ["s3", "s9"]
  },
  "check": null,
  "outcome": null,
  "models": { "sorter": "…", "reader": "…", "lead": "…", "writer": "…", "checker": "…" },
  "world": { "disclosures": [ { "kind": "web_search", "text": "Web search goes through Brave, hosted in the US." } ] },
  "budget": { "searches": [7, 10], "sources_read": [9, 15], "rounds": [2, 3], "seconds": [141, 360] },
  "cost": { "estimate": 0.35, "spent": 0.21, "currency": "USD" },
  "created_at": "…", "updated_at": "…", "expires_at": "…"
}
```

`status` ∈ briefing | waiting_answer | planning | waiting_plan | gathering |
writing | checking | complete | stopped | failed. `report` is null until
written; its `citations` list every `[n]` occurrence in reading order (`n` is
the occurrence index, `marker` the source number shown, `sentence` the text
the marker closes) so a client renders chips and the source drawer from the
state alone and can write `.sources.json` after a reconnect; `sources_order`
is the numbering of the sources list. `evidence` carries the cited passages
as text (so the source drawer shows them without the raw source, which is
dropped at `deliver`) and only verified quotes. Sources found through a lead
carry `origin: "link"` and `from: "s<n>"`.
`check` is `{ statements, supported, softened, removed }`; `outcome` is
`{ code, params, text }` (`03` §7.5). The `source` event (§2.6) carries `url`
or `file_id` too, so a live client needs no extra request.

### 2.6 Events

SSE frames `id: <seq>\nevent: <name>\ndata: <json>`. The first frame after
(re)attach is `run`. Clients ignore unknown event names (forward compatibility).

| Event | Data |
| ----- | ---- |
| `run` | `{ id, status, depth, research_protocol: 1 }` |
| `queued` | `{ since_seconds }` — the next unit is waiting for a worker (sent every 20 s while waiting) |
| `brief` | `{ goal, language, output, time_frame }` |
| `question` | `{ id, text, options, allow_text }` |
| `plan` | `{ points: [ { id, title } ], outline, estimate: { minutes, cost }, disclosures }` |
| `plan_waiting` | `{}` |
| `search` | `{ point, query, scope, results }` |
| `source` | `{ id, kind, title, url?, host?, file_id?, status, reason? }` |
| `round` | `{ n, max }` |
| `point` | `{ id, status }` |
| `writing` | `{ section }` |
| `section` | `{ title, index, total }` |
| `checking` | `{}` |
| `check` | `{ statements, supported, softened, removed }` |
| `complete` / `stopped` / `failed` | `{ outcome: { code, params, text } }` |
| `ping` | `{}` every 15 s |

---

## 3. Execution units (Messenger)

`App\Message\RunResearchUnit { string $runId; string $unit; int $seq }` on
`async_ai_high`. Units and their caps:

| `unit` | Does | Cap |
| ------ | ---- | --- |
| `brief_plan` | brief, questions (then waits), plan (then waits in Thorough) | 60 s |
| `round` | round mode: execute the last decision's actions (searches, then reads with `FETCH_PARALLEL` fetches and `READ_PARALLEL` concurrent reader calls), then one reflect call → next decision; tool mode: one lead turn and the execution of its tool calls, then checkpoint the lead conversation | `UNIT_SECONDS` (120); reads that do not fit roll into the next `round` unit with the same decision or tool call |
| `write` | one call; one section per unit only when the writer's `max_output` is too small for the depth (§0 #42; `seq` = section index) | 120 s |
| `check` | deterministic checks + checker batches; revision in Thorough | 120 s |
| `deliver` | final state, usage totals, `complete` event, drop source texts | 10 s |

The handler: (1) loads the state and exits if `seq` is not the expected next
unit; (2) takes the atomic claim `research:claim:{id}:{seq}` — `SET NX PX`
with a random token and a lease of 2 × `UNIT_SECONDS` — and exits if another
delivery holds it (two workers, a retry and a double dispatch all end here,
before any paid call); (3) runs the unit, checking cancel before every paid
call; (4) writes the checkpoint **fenced** with the token (compare-and-set in
Lua: the write happens only while the token still holds the claim), advances
`seq`, releases the claim; (5) dispatches the next unit with
`DelayStamp(RESEARCH.UNIT_GAP_MS)` so the single consumer serves
`async_extract` and `async_index` in between (`03` §6); (6) returns. The
sweeper re-dispatches a `seq` whose lease expired without a checkpoint. In
`test`, `RunResearchUnit` is routed to the `sync://` transport (`06` §8).
`RESEARCH.MAX_ACTIVE_RUNS` and `MAX_ACTIVE_THOROUGH_PER_USER` are enforced at
start.

---

## 4. BCONFIG keys

Group `RESEARCH` unless stated; global (owner 0) unless stated; seeded
insert-if-missing by `ResearchConfigSeeder`.

| Key | Default | Range / values | Read by |
| --- | ------- | -------------- | ------- |
| `ENABLED` | `0` (code), seeded `1` after RQ3 | 0/1; env pin `FEATURE_RESEARCH_ENABLED` | `ResearchModule`, controllers |
| `BUDGET.<DEPTH>.POINTS_MIN` / `POINTS_MAX` | 3/5 · 4/7 (quick: —) | 1–9 | `PlanStage` |
| `BUDGET.<DEPTH>.ROUNDS` | 0 · 3 · 8 | 0–20 | `ReflectStage` |
| `BUDGET.<DEPTH>.SEARCHES` | 2 · 10 · 30 | 0–100 | `GatherStage` |
| `BUDGET.<DEPTH>.RESULTS_PER_SEARCH` | 8 · 10 · 10 | 1–20 | `GatherStage` |
| `BUDGET.<DEPTH>.SOURCES` | 4 · 15 · 40 | 1–100 | `ReadStage` |
| `BUDGET.<DEPTH>.FETCH_PARALLEL` | 4 · 4 · 6 | 1–8 | `ReadStage` |
| `BUDGET.<DEPTH>.READ_PARALLEL` | 4 · 6 · 8 | 1–16 | `ConcurrentStructuredCaller` (§0 #37) |
| `LEAD_MODE` | `auto` | `auto` · `tools` · `rounds` | `LeadModeResolver` (§0 #2) |
| `TOOL_MODE_MODELS` | `[]` (code), seeded by RQ3 | JSON list of catalog keys that passed `app:research:conformance` | `LeadModeResolver` |
| `PASSAGE_MAX_CHARS` | 600 | 200–2000 | `PassageSplitter` (§0 #39) |
| `LEADS_PER_SOURCE` | 5 | 0–10 | `ReadStage` (§0 #38) |
| `BUDGET.<DEPTH>.WALL_SECONDS` | 60 · 360 · 1200 | 30–7200 | `ResearchBudget` |
| `BUDGET.<DEPTH>.COST_CAP` | 0.10 · 1.00 · 5.00 | ≥ 0 | `ResearchBudget` |
| `BUDGET.<DEPTH>.QUESTIONS` | 0 · 1 · 3 | 0–3 | `BriefStage` |
| `BUDGET.<DEPTH>.REPORT_WORDS` | 300 · 1500 · 6000 | 100–20000 | `WriteStage` |
| `BUDGET.<DEPTH>.MAX_ACTIONS` | 0 · 6 · 10 | 0–20 | `ReflectStage` |
| `BUDGET.<DEPTH>.MAX_MODEL_CALLS` | 6 · 60 · 200 | ≥ 1 | `ResearchBudget` (hard stop, `complete_partial`) |
| `UNIT_SECONDS` | 120 | 30–600 | handler |
| `UNIT_GAP_MS` | 3000 | 0–30000 | `DelayStamp` between units (fairness, `03` §6) |
| `MAX_ACTIVE_RUNS` | 5 | 1–100 | start |
| `MAX_ACTIVE_THOROUGH_PER_USER` | 1 | 0–10 | start |
| `STATE_TTL_HOURS` | 48 | 1–168 | state store |
| `READ_DOCUMENTS_MAX_MB` | 20 | 1–100 | source reader |
| `FETCH_PER_HOST` | 1 | 1–4 | source reader |
| `FETCH_CACHE_MINUTES` | 60 | 0–1440 | source reader |
| `LOCAL_PARALLEL` | 1 | 1–4 | reader concurrency when every role is local |
| `STRICT_WORLD` | 0 | 0/1 | world rule |
| `VERIFY_CANDIDATES` | seeded list of catalog keys (RQ3) | JSON list | role resolver |
| `PRESET.<WORLD>.<DEPTH>.<ROLE>` | seeded (RQ3) | catalog key | role resolver |
| `DOCUMENT_CARDS` | 0 | 0/1 | RS9 |
| `DEFAULTMODEL.READ`, `DEFAULTMODEL.VERIFY` | seeded (RQ3); user/group/global like every slot | BID | role resolver via `ModelConfigService` |
| `PLUGS` / `WEB_SEARCH.READ_DOCUMENTS` | 0 | 0/1 | `WebResearchService` (RS4) |
| `MESSAGES_GATEWAY.WEB_SEARCH_MAX_ROUNDS` | 2 | 1–6 | `GatewayToolLoop` (RS1) |
| `MESSAGES_GATEWAY.WEB_FETCH_MAX_PER_TURN` | 3 | 0–10 | `GatewayToolLoop` (RS2) |
| `MESSAGES_GATEWAY.KNOWLEDGE_TOOLS` | `desktop` | `off`, `desktop`, `all` | `GatewayToolCatalog` (RS3) |
| `RATELIMITS_<LEVEL>.RESEARCH_STANDARD_DAILY` / `RESEARCH_THOROUGH_DAILY` | anonymous 0/0 · new 3/0 · pro, team, business: owner's call | ≥ 0 | `RateLimitService` action `RESEARCH` |

Web search provider hosting (for disclosure), a constant table next to
`WebSearchCapabilities`: brave US, tavily US, exa US, firecrawl US,
perplexity US, searxng self-hosted (country unknown → "your own server").

---

## 5. PHP contracts

Namespace `App\Service\Research\Run` (Application layer; deptrac: may use
`AiFacade`, `WebSearchGateway`, `VectorSearchService`, `RateLimitService`,
`UrlContentService`, `ContextCondenser`; never imported by an entity).

```php
final readonly class ResearchRunService
{
    public function start(User $user, ResearchRequest $request, ?string $idempotencyKey): ResearchRunState;
    public function state(User $user, string $runId): ResearchRunState;           // throws ResearchRunNotFound
    public function listActive(User $user): array;                                 // list<ResearchRunSummary>
    public function input(User $user, string $runId, ResearchInput $input): ResearchRunState;
    public function cancel(User $user, string $runId): ResearchRunState;      // sets the cancel flag; the next paid call never starts
    public function followUp(User $user, string $runId, ResearchFollowUp $followUp): ResearchRunState;
    public function delete(User $user, string $runId): void;
}

/** Writes a finished web run into its chat (assistant message + report file with a DocumentRevision); no-op for API-key runs without chat_id. */
final readonly class ResearchReportDelivery { public function deliver(ResearchRunState $state): void; }

interface ResearchStage { public function run(StageContext $ctx): StageResult; }   // Brief, Plan, Gather, Read, Reflect, Write, Check, Deliver
final class StageContext { /* run, ledger, budget, roles, emitter, clock, cancelFlag, world */ }
final readonly class StageResult { public string $next; /* unit name or 'wait_answer' | 'wait_plan' | 'done' */ }

final readonly class ResearchRoleResolver
{
    public function resolve(User $user, ResearchDepth $depth, ResearchWorld $world, ResearchModelHints $hints, ?int $leadModelId): ResearchRoles; // throws RoleUnavailable(role)
}
final readonly class ResearchRoles { public int $sorter; public int $reader; public int $lead; public int $writer; public int $checker; public bool $checkerSameFamily; public array $disclosures; }

final class ResearchBudget
{
    public static function for(ResearchDepth $depth, ResearchBudgetConfig $config): self;
    public function canSearch(): bool; public function canRead(): bool; public function canRound(): bool; public function canCall(): bool;
    public function spend(string $counter, int $n = 1): void; public function secondsLeft(): int; public function toArray(): array;
}

final class EvidenceLedger
{
    public function addSource(Source $source): string;            // returns id, dedupes by canonical URL / file id
    public function markSource(string $id, string $status, ?string $reason = null): void;
    public function addEvidence(EvidenceCard $card): string;
    public function summaryForLead(int $maxChars): string;        // per point: findings, kinds, dates, conflicts; titles sanitised
    public function evidenceForWriter(): array;                    // grouped by point, verified quotes only
    public function unreadSources(): array; public function queries(): array;
    public static function fromArray(array $a): self; public function toArray(): array;
}

final readonly class PassageSplitter
{
    public function split(string $rawText, int $maxChars): PassageMap;    // §1…§n: paragraph, table row, list item; long paragraphs into sentences; same text → same ids
}

final readonly class PassageVerifier
{
    public function passagesExist(array $passageIds, PassageMap $map): bool;
    public function quoteInPassage(string $quote, string $passageId, PassageMap $map): ?QuoteMatch;   // whitespace, quotes, hyphenation, number separators normalised
    public function numbersSupported(string $sentence, array $passageTexts): bool;
}

/** Runs up to $parallel structured calls at once over AiFacade (HTTP multiplexing); usage recorded and cancel checked per request. */
final readonly class ConcurrentStructuredCaller
{
    public function callAll(array $requests, int $parallel, CancelFlag $cancel): array;   // list<StructuredResult|CallFailed>, same order as $requests
}

final readonly class LeadModeResolver { public function resolve(int $leadModelId, ResearchDepth $depth): LeadMode; }   // tools | rounds, from LEAD_MODE and TOOL_MODE_MODELS

final readonly class LeadToolbox
{
    public function definitions(): array;                                    // the five tools of §1.5a
    public function execute(ToolCall $call, StageContext $ctx): string;      // JSON result; enforces the budget, never returns raw source text
}

final class LeadConversation
{
    public function append(array $assistantTurn, array $toolResults): void;
    public function messagesFor(int $inputBudgetTokens, string $ledgerSummary): array;   // tool-result clearing past 60 % of the budget
    public static function fromArray(array $a): self; public function toArray(): array;
}

final readonly class ResearchStateStore
{
    public function save(ResearchRunState $state): void;           // research:run:{id}, TTL from STATE_TTL_HOURS
    public function load(string $runId): ?ResearchRunState;
    public function expectedSeq(string $runId): int;
    public function claim(string $runId, int $seq, int $leaseMs): ?string;                     // SET NX PX → token, or null when held
    public function checkpoint(ResearchRunState $state, string $token): bool;                  // fenced write; false when the lease was lost
    public function release(string $runId, int $seq, string $token): void;
    public function expiredClaims(): iterable;                                                 // for the sweeper
    public function activeCount(int $userId, ?ResearchDepth $depth = null): int; public function activeTotal(): int;
    public function sourceText(string $runId, string $sourceId): ?string; public function putSourceText(string $runId, string $sourceId, string $text): void;
    public function deleteUser(int $userId): int;                  // UserDeletionService
    public function delete(string $runId): void;
}

final readonly class ResearchEventLog
{
    public function append(string $runId, string $event, array $data): int;   // returns seq
    public function replay(string $runId, int $after): iterable;              // list<array{seq,event,data}>
}

interface ResearchSourceReaderInterface { public function read(Source $source, ReadBudget $budget, ResearchWorld $world): SourceText|BlockedSource; }
final readonly class ResearchSourceReader implements ResearchSourceReaderInterface {}   // HTML via UrlContentService, documents via extractionChain('document', allowCloud), knowledge chunks
final readonly class FixtureSourceReader implements ResearchSourceReaderInterface {}    // test env: fixture.synaplan.test → files

RQ2 ships a smaller stub of that interface: `read(string $url): SourceRead`.
RS6 replaces the stub with the signature above. The fixture host stays
`fixture.synaplan.test`, and any other host is refused without a network call.

final readonly class ResearchOutcome { public string $code; public array $params; public function text(): string; }
```

Controllers: `ResearchApiController` (`/v1/research*`) and `ResearchController`
(`/api/v1/research*`), every method < 50 lines: validate (a `ResearchRequest`
denormaliser with named-field errors), call the service, return. OpenAPI on
every route and DTO.

Module: `App\Module\Chat\ResearchModule` — `configuredBy(envKeys:
['RESEARCH_ENABLED'], bconfigKeys: ['RESEARCH.ENABLED'])`, `status()` with
active / queued / failed-24h counts and "no worker picked up units for N
minutes" when the oldest queued unit is older than 5 min; `routeNames()` the
research routes; `mobileClass()` backend-only.

Commands: `app:research:reap` (LANE_TICK), `app:research:eval` (RQ2),
`app:research:spike` (RQ2b, eval account only), `app:research:conformance`
(RS7, tool-mode suite).

---

## 6. Desktop contracts (Rust and Vue)

`synaplan-core/src/research.rs` (Tauri-free, tested with the vendored fixtures):

```rust
pub struct ResearchStartRequest { question, depth, sources, language, output, instructions, context, assistant_id, models, world, seed_sources, parent }  // strict serde
pub enum ResearchEvent { Run{..}, Queued{..}, Brief{..}, Question{..}, Plan{..}, PlanWaiting, Search{..}, Source{..}, Round{..}, Point{..}, Writing{..}, Section{..}, Checking, Check{..}, Complete{outcome}, Stopped{outcome}, Failed{outcome}, Ping, #[serde(other)] Unknown }
pub async fn capabilities(base, key) -> Result<Option<Capabilities>, ResearchError>;   // Ok(None) on 404 → feature hidden
pub async fn start(base, key, req, idempotency_key) -> Result<RunRef, ResearchError>;
pub async fn state(base, key, id) -> Result<RunState, ResearchError>;
pub async fn attach(base, key, id, after: Option<u64>, cancel, on_event: impl FnMut(u64, ResearchEvent)) -> Result<(), ResearchError>;   // reconnects with Last-Event-ID
pub async fn input(base, key, id, input) -> Result<RunState, ResearchError>;
pub async fn cancel(base, key, id) -> Result<RunState, ResearchError>;
pub async fn follow_up(base, key, id, req) -> Result<RunRef, ResearchError>;
pub async fn list_active(base, key) -> Result<Vec<RunSummary>, ResearchError>;
pub async fn delete(base, key, id) -> Result<(), ResearchError>;
```

Tauri commands (`src-tauri/src/commands/research.rs`): `research_capabilities`,
`research_start`, `research_attach` (emits `research://event { run_id, seq,
event }`, one attachment per run, re-attaches on reconnect), `research_input`,
`research_cancel`, `research_follow_up`, `research_list_active`,
`research_delete`, `research_save_report` (writes `out/<slug>.md` +
`.sources.json`, publishes to the Out board). The key never enters JS.

Persistence: a research run is referenced from the thread's local JSON as
`{ run_id, started_at, status, report_path, version }`; the report text is on
disk, not in the thread file.

Vue (`src/components/research/`, each ≤ 300 lines; `useResearchRun.ts` holds
the state machine built from events):

| Component | Props | Emits |
| --------- | ----- | ----- |
| `ResearchComposer.vue` | `capabilities`, `knowledgeReady: number`, `webOn`, `disabledReasons` | `start(request)` |
| `ResearchQuestionCard.vue` | `question` | `answer(choice|text)`, `skip` |
| `ResearchPlanCard.vue` | `plan`, `depth`, `editable`, `disclosures`, `estimate` | `start`, `cancel`, `edit(points)` |
| `ResearchProgressCard.vue` | `points`, `counters`, `current`, `queuedSince`, `details[]` | `stop`, `writeNow` |
| `ResearchResultCard.vue` | `report`, `check`, `savedPath`, `models` | `open`, `makeWord`, `more(action)`, `delete` |
| `ResearchReport.vue` | `markdown`, `sources`, `version`, `versions` | `cite(sourceId)`, `changeSection(i, kind)`, `ask(text)`, `restore(v)` |
| `SourceDrawer.vue` | `source`, `quotes` | `openPage`, `openFile`, `copyQuote` |
| `ResearchTeam.vue` (Models) | `roles`, `catalog`, `worldProviders` | `customize(role, key)`, `reset` |

i18n: every key under `research.*` in `src/i18n/{en,de,es,fr,tr}.json`
(the copy table of `05` §6 gives the first 30 keys); the locale parity test
covers them.

---

## 7. Fixtures (frozen; checksum test on both sides)

`_devextras/testing/research/fixtures/` in Synaplan, vendored byte for byte as
`synaplan-desktop/tests/fixtures/research-contract/`:

| File | Content |
| ---- | ------- |
| `capabilities.json` | §2.2 example |
| `start-request.json` | §2.3 example |
| `start-response.json` | `{ id, status, events, research_protocol }` |
| `state-gathering.json`, `state-complete.json`, `state-failed.json` | §2.5 |
| `events-standard.sse` | A full Standard run as SSE text, with `id:` sequence numbers |
| `events-thorough-waiting.sse` | Up to `plan_waiting`, then `input` and the rest |
| `input-*.json` | One per input type |
| `outcomes.json` | Every outcome code with params and English text |
| `README.md` | Source commit, how to regenerate (`app:research:fixtures`), the rule that a change here is a `research_protocol: 2` decision |

Test fixtures for the doubles (not vendored): `backend/tests/Fixtures/research/search/*.json`
(query → results), `backend/tests/Fixtures/research/pages/*.html|.pdf`
(served for `https://fixture.synaplan.test/<name>`), `tests/Fixtures/research/injection/*`.

---

## 8. Log and usage fields

- Log channel `research`, JSON lines: `run_id`, `user_id`, `unit`, `seq`,
  `role`, `model_id`, `duration_ms`, `tokens_in`, `tokens_out`,
  `outcome`, `source_count`; never the question, a title, a quote or the
  report.
- `BUSELOG` via `RateLimitService::recordUsage($user, 'MESSAGES', [...])`
  with `source: 'RESEARCH'`, `role`, `run_id`; searches `WEB_SEARCH`, rerank
  `RERANK`; the rate-limit action `RESEARCH` counts runs per day per depth.
