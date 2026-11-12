# The effort rating and the engine — 2026-10-28

Part of [`00_master_plan.md`](./00_master_plan.md). Steps AR1 (sorter), AR2
(engine), AR5 (gateway) and AR7 (eval) implement this file. Code names here
are proposals; the step may rename them, the contract may not change without
a STATUS line.

---

## 1. Three stages, one rating

### 1.1 Where the rating is made

The sorter already runs on every chat message that has no fixed prompt
(`MessageProcessor::processStream` → `TurnClassificationResolver::classify`
→ `SorterVerdict::classify` → `MessageSorter::classify`, prompt `tools:sort`,
model `DEFAULTMODEL.SORT`, ~300 ms). It already answers two of the three
questions the effort needs: *does this need the web* (`BWEBSEARCH`) and *is
snippet text enough or should pages be read* (`BREADPAGES`). This plan adds
the third question to the same call: *does one answer do, or does this need
a run with a plan?* No second model call, no new slot.

The rating is read from the sorter's JSON in a new class and acted on in a
new stage. `MessageSorter::parseResponse` (109 lines, frozen) does not grow:
the raw decoded JSON is already available to `SorterVerdict`, which adds the
two fields through `EffortVerdict::fromSorterJson()`.

```text
MessageProcessor::processStream
  ├─ classifications->classify()        tools:sort → BTOPIC, BLANG, BWEBSEARCH, BREADPAGES, BMULTI, BEFFORT, BEFFORT_WHY
  ├─ classifications->afterClassify()   overlays (forced search, planner corrections) — unchanged
  ├─ classifications->finish()
  ├─ NEW  autoResearch->decide()        AutoResearchGate: effort + limits + flags → start a run, or fall through
  │        └─ research_started          the turn ends with the notice; the report comes later as its own message
  ├─ webSearch->run()                   unchanged (`lookup` and the fallbacks of §2.4)
  └─ dispatcher->dispatchStreamed()     unchanged
```

`MessageProcessor` has six constructor parameters; the gate is the seventh
(limit ten). `ChatStreamOrchestrator` (ten, at the limit) is not touched:
the notice travels through the existing status callback as a status event
and the turn's reply is the notice text, persisted by `StreamReplyPersister`
like any assistant reply, with `providerIndex = RESEARCH_NOTICE` so the
client can render it as the notice block (`02` §2.2).

### 1.2 The two fields

Added to the sorter's answer format (`PromptCatalog`, `tools:sort`,
section "Answer format") and to `MessageSorter`'s field list in the system
sentence that names the allowed keys:

```text
* "BEFFORT": "answer" | "lookup" | "research"
* "BEFFORT_WHY": "simple" | "current_fact" | "several_parts" | "comparison" | "procedure" | "asked_short" | "asked_thorough" | "follow_up"
```

| `BEFFORT` | Meaning | What happens |
| --------- | ------- | ------------ |
| `answer` | The model answers from what it knows or from the thread. | Today's answer stream. `BWEBSEARCH` may still be 1 (one search feeds the answer, as today). |
| `lookup` | One current fact or a short list from a few pages. | Today's one-search path with page reading. **Look deeper** under the answer. |
| `research` | Several parts, a comparison, a procedure with authorities and documents, a regulation, a market or product overview — the answer needs a plan and sources per point. | A research run with depth `auto` (§2), when the gate allows it (§2.4). |

`BEFFORT_WHY` is a closed code; the client maps it to the sentence in the
person's language (`02` §3). The sorter writes no free text.

Parsing (`EffortVerdict`):

- `BEFFORT` outside the three values, missing, or on a `parse_failed` result
  → `answer`, `why = simple`, and the verdict is marked `voted = false` so
  the snapshots and the eval can tell "no vote" from "voted answer".
- `BEFFORT_WHY` outside the eight codes → `simple` for `answer`,
  `current_fact` for `lookup`, `several_parts` for `research`.
- `research` with `BWEBSEARCH = 0` is allowed (a run over the chat's
  folders only); the gate decides whether a source exists (§2.4).
- The verdict is flattened into the classification array as `effort` and
  `effort_why` (strings) beside `web_search` and `read_pages`, so
  `RoutingCharacterizationTest` records them.

### 1.3 The prompt addition

One new numbered rule after 6b in `tools:sort`, in the same voice as the
rules around it. Draft:

```text
6c. **Rate how much work the answer needs (BEFFORT)**. Three values:

   "answer" — one reply from general knowledge or from this conversation.
   This is the default. ALWAYS "answer" for: greetings and smalltalk; coding
   help; creative writing; translation, grammar, rephrasing, summarising or
   extracting from text or files the user provided; math and logic; opinions
   and advice; a message that asks for brevity ("short", "quickly", "in one
   sentence", "kurz", "schnell", "en bref", "kısaca"); and a follow-up that
   the previous answer in this conversation already covers.

   "lookup" — ONE current fact or a short list that a few web pages settle:
   a price, a date, an office holder, a version, the weather, opening hours,
   "what is the latest …". Usually BWEBSEARCH 1.

   "research" — the correct answer has SEVERAL parts that each need their own
   sources, and a person would open many pages to assemble it:
   - a procedure with authorities, documents and an order ("what do I need to
     marry a Chinese citizen in China — which offices, which documents"),
   - a comparison of several products, providers, laws or places,
   - a regulation or requirement that differs by country, state or case,
   - a market, vendor or literature overview,
   - an explicit wish for depth: "thorough", "in detail", "with sources",
     "research this", "gründlich", "ausführlich", "recherchiere",
     "à fond", "detalladamente", "ayrıntılı".
   When a message is between "lookup" and "research" and the user did not
   ask for brevity, choose "research". NEVER choose "research" for the
   "answer" list above, however long the message is.

   Set BEFFORT_WHY to the one reason that made you decide:
   "simple", "current_fact", "several_parts", "comparison", "procedure",
   "asked_short", "asked_thorough", "follow_up".

   Examples:
   - "Hi, wie gehts?" → BEFFORT: "answer", BEFFORT_WHY: "simple"
   - "Write a Python function that sorts a list" → "answer", "simple"
   - "Wie steht der Dollar zum Euro?" → "lookup", "current_fact"
   - "Who is the CEO of Siemens?" → "lookup", "current_fact"
   - "Was brauche ich, um eine Chinesin in China zu heiraten — welche Behörden, welche Dokumente, in welcher Reihenfolge?" → "research", "procedure"
   - "Compare Nextcloud, ownCloud and OpenCloud for a 50-person company" → "research", "comparison"
   - "Recherchiere gründlich, welche Förderprogramme es 2026 für Wärmepumpen in Bayern gibt" → "research", "asked_thorough"
   - "Kurz: was ist eine Wärmepumpe?" → "answer", "asked_short"
   - (after a report on heat pump subsidies) "Und in Österreich?" → "lookup", "follow_up"
   - "Summarize the attached contract" → "answer", "simple"
```

The prompt is seeded through `PromptCatalog` and reaches existing installs
the way every `tools:` prompt revision does (the catalog seeder updates
system prompts; a person's own copy of `tools:sort` is not overwritten — the
parser's defaults keep such an install on `answer`).

### 1.4 Explicit intent wins in both directions

| The person wrote | Rating | Why |
| ---------------- | ------ | --- |
| "short", "quickly", "briefly", "one sentence", "tl;dr", "kurz", "schnell" | `answer` (or `lookup` when a fact is needed) | A person who asked for little must not wait minutes. |
| "thorough", "in detail", "with sources", "research", "look into", "gründlich", "ausführlich", "recherchiere", "mit Quellen" | `research` | The ChatGPT lesson: "think hard" must work. |
| Both ("quick research on …") | `research` with the quick ceiling (§2.1, point count ≤ 2 forced) | Honour the depth wish and the brevity wish together. |

These are prompt rules for the sorter, not regexes in PHP. The eval corpus
(§4) contains at least six items per direction and per language.

### 1.5 What the rating never does

- It never overrides a fixed prompt, an Again turn, a forced image
  description, a Saved Task run, the widget (`withWidgetMode`) or a channel
  turn: `AutoResearchGate` returns "fall through" before reading the rating
  when the turn is any of these (§0 #13, #15).
- It never starts a run in an incognito chat (no `chat_id` to deliver into).
- It never changes `BTOPIC`, `BWEBSEARCH` or `BREADPAGES`: a `research`
  rating that the gate refuses falls through to exactly the turn the other
  fields describe.

---

## 2. Depth `auto` in the engine

### 2.1 The ceiling from the plan

`ResearchDepth` gets a fourth case, `Auto = 'auto'`. It is accepted on
`POST /api/v1/research` and `POST /v1/research` like the others (the
composer is gone, so the only callers are the gate, Desktop through the
gateway header, and the API). The run starts with the **standard** budget
row (`RESEARCH.BUDGET_STANDARD`, or the code default 10 / 15 / 3 rounds /
360 s / 1 USD) with `withoutQuestions()` and no plan wait, and after the
plan stage sizes itself:

| Plan points | Ceiling applied | Lead mode | Check |
| ----------- | --------------- | --------- | ----- |
| 1–2 | `BUDGET_QUICK` (2 searches, 4 sources, 60 s, 0.10 USD; code defaults) | rounds (as `quick`) | removal, as `standard` |
| 3 or more | `BUDGET_STANDARD` stays | `LeadModeResolver::decide` as for `standard` (tool mode when the lead model is on `TOOL_MODE_MODELS`) | removal, as `standard` |
| any, request carried `hint: brief` (§1.4 "quick research") | `BUDGET_QUICK`, point count capped at 2 by the plan stage | rounds | removal |

The resizing is one method on the budget (`ResearchBudget::shrinkTo()`),
called once by the plan stage; the ledger records a `plan` event with the
chosen ceiling in `extras`, so the card's Details log says "2 points — a
short look" or "5 points — a standard look". The `minutes` named in the
notice (`02` §3) are the ceiling's minutes rounded up to the nearest
minute: 1 for quick, 6 for standard.

Thorough is never chosen by `auto`. **Go deeper** / **Look deeper** on a
report keep today's `deeper` follow-up (thorough budget, cost named first,
one at a time per person — `MAX_ACTIVE_THOROUGH_PER_USER`).

### 2.2 What `auto` changes nowhere else

- Stop rules (`03_research_engine.md` §2.5 of row 21), outcomes, the
  checker, delivery, follow-ups, the reaper: unchanged. A run with depth
  `auto` reports `depth: auto` in every view and event; the client shows the
  word "Automatic" only in the Details log, never as a mode.
- `LeadModeResolver::decide` treats `Auto` as `Standard` for the mode
  decision; the quick ceiling of §2.1 switches the mode to rounds **before**
  the first lead call, so a run never changes mode mid-way.
- `ResearchCapabilities` lists `auto` under `depths` with `minutes` and
  `cost_cap` of the standard row (the honest upper bound) and a new boolean
  `automatic` = `RESEARCH.AUTO_ENABLED` and module on. The web app reads
  `automatic` to decide whether **Look deeper** exists at all (U11).

### 2.3 Tool mode by default for models that passed

`ResearchConfigSeeder` seeds `RESEARCH.TOOL_MODE_MODELS` with

```json
["anthropic:claude-sonnet-5-5:chat", "anthropic:claude-sonnet-5:chat"]
```

(the two keys that scored 20/20 in `app:research:conformance`, row 21
STATUS 2026-10-26). A migration `Version20261028120000` sets the same value
on existing installs **only where the row is still `[]`**, with a comment
that an administrator's list is never replaced. `LEAD_MODE` stays `auto`.
When a model is added to the list later, the admin tab's existing
conformance hint applies (RW2).

### 2.4 The gate

`App\Service\Message\Effort\AutoResearchGate::decide(Message, array
$classification, array $options): GateDecision`. Checked in this order; the
first that fails is the decision's `reason`, and the reason selects the
limit sentence (`02` §4):

| # | Check | Fall-through reason |
| - | ----- | ------------------- |
| 1 | Not a widget, channel, Saved Task, Again, fixed-prompt or incognito turn | *(silent — these paths never say anything about research)* |
| 2 | `ResearchModule` on and `RESEARCH.AUTO_ENABLED = 1` | *(silent — U11, the feature is absent)* |
| 3 | `effort === research` | *(silent — ordinary turn)* |
| 4 | A source exists: `ResearchSearch::available(userId)` **or** the chat has a Library folder to search (`withRagScope` set it) | `no_sources` |
| 5 | `ResearchRunService` limits: `activeCount < MAX_ACTIVE_RUNS`; cost budget (`RateLimitService::checkCostBudget`); row 21 #25's daily count once it exists | `too_many`, `budget`, `plan_limit` |
| 6 | The research store answers (Redis) | `unavailable` |

On pass: `ResearchRunService::start()` with

```json
{
  "question": "<the message text>",
  "depth": "auto",
  "sources": { "web": <available>, "knowledge": [<the chat's folder keys>] },
  "language": "<BLANG>",
  "chat_id": <this chat>,
  "assistant_id": <pinned agent or null>,
  "context": { "turns": [<last 6 turns, as the composer sent them>] },
  "hint": "brief" | null
}
```

(`hint` is a new optional body field on `ResearchRequest`, values `brief` |
`deeper`, ignored by every depth but `auto`)
and `Idempotency-Key = "msg:" + message id`, so a reconnect or a retried
stream never starts a second run. The decision carries the run id, the
ceiling's minutes and `effort_why`; the processor emits the status event
`research_started {run_id, minutes, why}` and returns the notice as the
turn's reply (§1.1). The report arrives later through
`ResearchReportDelivery` as today, as a new assistant message in the same
chat.

On fall-through with a reason (4–6): the turn continues as `lookup` when
`BWEBSEARCH = 1`, otherwise as `answer`, and the gate sets
`classification['effort_notice'] = <reason>` so the handler prepends the
limit sentence to the system prompt's "say first" slot — the answer **begins**
with it in the person's language (`02` §4; the sentence is written by the
server from the locale files of the backend, like the research outcome
texts in `ResearchOutcomeText`).

### 2.5 Answer now

A new input type on `POST /api/v1/research/{id}/input` (today: `answer`,
`skip_question`, `approve_plan`, `edit_plan`, `stop_gathering`):
`type: "answer_now"`. Semantics:

| Run state | Effect |
| --------- | ------ |
| Gathering or reading, at least one source read | = `stop_gathering`: write and deliver from what was read; outcome `complete_partial`; the report says so in its first line (today's copy). |
| Before the first source (brief, plan, queued) | The run is cancelled (`stopped_by_user`, no report), and the server answers the original message on the `lookup` path, delivered as a normal assistant message. The card closes with "Answered without the extra look." |
| Writing or checking | No-op with `202`; the report is seconds away. The button is disabled in those phases on the client. |

Desktop and the web use the same route.

### 2.6 Look deeper

`POST /api/v1/research` from the client with

```json
{
  "question": "<the original message text>",
  "depth": "auto",
  "sources": { "web": true, "knowledge": [...] },
  "seed_sources": [<the answer's cited pages, max 50>],
  "context": { "turns": [<the message and the answer>] },
  "chat_id": <chat>,
  "hint": "deeper"
}
```

`hint: deeper` makes the plan stage skip the quick ceiling (the person asked
for more) and tells the brief that a first answer exists and was found
thin. The card attaches under the answer; the report lands as a new message.
When the answer was itself held back by a limit (§2.4 reasons 5–6), the
button repeats the limit sentence on click instead of starting (the server
answers `429` / `503` with the same code; the client shows the same text).

---

## 3. The gateway

### 3.1 Without a header — a higher ceiling and effort rules

`MESSAGES_GATEWAY.WEB_SEARCH_MAX_ROUNDS` seeded **4** (was 2; the clamp
1–6 stays). Migration `Version20261028130000` raises rows whose value is
still `'2'` and leaves any other value alone. The wrap-up sentence
`WRAP_UP_AFTER_SEARCH` is unchanged. The gateway system prompt gets the
effort rules as one paragraph (new class `GatewayEffortRules` that returns
the text; `GatewayToolLoop` does not grow):

```text
Scale your searches to the question. One fact: one search, then answer.
A comparison or a procedure with several parts: one search per part, read
the pages that matter, then answer with every part and cite the pages.
Never search for greetings, code, writing, translation or questions this
conversation already answers.
```

Measured in AR5 on UC-03 / UC-04 of the desktop set and on the hard corpus
with `--arm=b1plus --web-search`, before and after (cost, recall, p50).

### 3.2 With `x-synaplan-effort: auto`

A new optional request header, documented in `MessagesApiController`'s
OpenAPI parameters and in `docs/ANTHROPIC_COMPATIBLE_API.md`. Values:
`auto` (rate and research when needed), `answer` (never research; the
default when the header is missing). Any other value is treated as the
default.

With `auto`, the gateway runs the same sorter call the chat runs
(`SorterVerdict` on the last user message; the gateway has no classification
today, so this is the one new model call on this path, on the SORT model,
only when the header is present) and the same `AutoResearchGate` with the
header's folder (`x-synaplan-rag-group-key`) as the knowledge source. On a
start, the response is a normal non-streaming / streaming message whose
text is the notice sentence (§1.1 words, in the request's language), with
the response headers

```text
x-synaplan-research-run: rr_<id>
x-synaplan-effort: research | lookup | answer   (what was decided, always set when the request header was present)
```

The client (Desktop) attaches its research card to that run id
(`GET /v1/research/{id}/events`), exactly as RD3 does for a chip-started
run today, and gets the report into `out/` and onto the Out board through
the delivery path that already exists for Desktop (RD4). Follow-ups,
Answer now and Look deeper use the `/v1/research` routes with the API key.

Without the header nothing on this path changes except §3.1 (mobile
policy: additive, optional, default behaviour unchanged).

---

## 4. The router corpus and its gate

### 4.1 Corpus

`backend/tests/Eval/research/router.json`, labelled by the owner before AR1
merges (the step opens with the file as a draft and the owner's ticks):

```json
{
  "id": "proc-01",
  "lang": "de",
  "text": "Was brauche ich, um eine Chinesin in China zu heiraten — welche Behörden, welche Dokumente, in welcher Reihenfolge?",
  "history": [],
  "label": "research",
  "why": "procedure"
}
```

Composition, ≥ 60 items:

| Group | Items | Label |
| ----- | ----- | ----- |
| Smalltalk, thanks, greetings | 6 | answer |
| Code, math, translation, rephrasing, summarise-the-attachment | 10 | answer |
| Creative writing and advice from general knowledge | 6 | answer |
| Brevity asked on a hard topic | 5 | answer |
| One current fact (price, office holder, weather, version) | 10 | lookup |
| Follow-ups under a report (with `history`) | 6 | lookup / answer |
| Procedures with authorities and documents (the marriage question and four more, in five languages) | 6 | research |
| Comparisons | 5 | research |
| Regulations by country / state | 4 | research |
| Depth asked explicitly | 5 | research |
| The 12 hard items and the 20 starter items of row 21, labelled | 32 | mixed |

Every label group appears in at least three of the five locales.

### 4.2 Command and metrics

`app:research:router-eval --corpus=router [--repeat=N] [--model=…]` runs
`MessageSorter::classify` on each item with the item's `history` as the
thread (through the same `SorterInput` the chat uses, so the prompt and
model are the production ones), and reports:

| Metric | Definition | Gate |
| ------ | ---------- | ---- |
| Agreement | items whose `effort` equals the label / all | ≥ 90 % |
| Over-escalation | `research` on an `answer` label / `answer` labels | ≤ 5 % |
| Under-escalation | `answer` or `lookup` on a `research` label / `research` labels | ≤ 10 % |
| Reason agreement | `effort_why` equals `why` on agreeing items | reported, no gate |
| No-vote rate | `voted = false` / all | ≤ 2 % |
| Extra calls | model calls per item beyond the sorter | 0 |
| Latency | sorter p50 / p95 before and after the prompt addition | p95 not worse by more than 20 % |

Repeat 3, majority per item; the table in STATUS names model, date, command
and the confusion matrix. The gate is checked in AR1 (merge gate) and again
in AR7 after AR3 and AR5 shipped, with the cost curve: mean cost per 100
corpus messages run through the chat on the dev stack before (`main` at
AR0) and after (AR7), from `BUSELOG`, with the number of automatic runs
started.

### 4.3 What the gate protects

- A sorter model that cannot carry the extra fields (small local models)
  degrades to `answer` with `voted = false`; an install on such a model
  simply never researches on its own. The admin tab (AR4) shows the no-vote
  rate of the last 24 hours as one sentence so an administrator sees why
  (`02` §5).
- The characterization snapshots (`routing_classification.json`,
  `ai_paths.json`) are re-recorded once in AR1 with the two new fields and
  read line by line; a later drift is a failing test, not a silent change.
