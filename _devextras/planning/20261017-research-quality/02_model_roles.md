# Model roles — combining models for research

**Status:** Draft 2026-10-17. Candidates and prices come from the live dev
catalog (`BMODELS`, prices per 1M tokens in / out) on that day. Every cost in
§6 is an **estimate** from list prices and stated token assumptions; RQ3
replaces it with measured cost from `BUSELOG`. No quality claim here is
measured yet — §8 is how we measure it.

---

## 1. Two job families

The owner's split is the right one. Research work falls into two families
with opposite needs:

| | **Organize** — summarizing, sorting, indexing | **Create** — creation, modification, enhancement |
| - | --------------------------------------------- | ------------------------------------------------- |
| Volume per run | High: 20–80 calls | Low: 5–20 calls |
| Input | Long (pages, PDFs, chunks), mostly untrusted | Short and curated (evidence cards, outline) |
| Output | Short, structured (JSON, quotes) | Long prose, tables, decisions |
| Parallel | Yes, independent per source | Mostly sequential |
| What matters | Faithfulness, recall, strict format, price, speed, long context | Reasoning, judgement, structure, style, language quality, long output |
| What does not | Eloquence | Price per input token (inputs are small) |
| Measured by | Quote exactness, fact recall, triage agreement, recall@k | Fact recall in the report, citation precision, coverage, judge rating |

Planning and gap analysis ("what is still missing, where do I look next?")
need the same judgement as writing, so the **lead** role sits in the Create
family. Fact-checking is a classification task, but it needs careful reading
and above all **independence** from the writer, so the **checker** is a Create
role filled from a different provider.

---

## 2. Six roles

| Role | Family | Job in a run | UI name (Desktop, Models → Research team) |
| ---- | ------ | ------------ | ----------------------------------------- |
| **Sorter** | Organize | Turn the question into a brief; fan out search queries; drop duplicates; triage results (relevant, authoritative, fresh); pick what to read | not shown (Details only) |
| **Reader** | Organize | Read one source with the plan's points as the lens; return an evidence card: facts citing numbered passages (optionally a short quote), date, kind, warning flags, links worth following | Reading sources |
| **Indexer** | Organize | Embed, keyword-index and rerank knowledge passages; at upload, write a one-paragraph document card per file | not shown (workspace setting) |
| **Lead** | Create | Plan the points and the outline; after each round, judge each point (answered, thin, conflicting, open) and choose the next actions; decide when to stop | Thinking and writing |
| **Writer** | Create | Write the report from evidence only, section by section; later rewrite, shorten, translate, add a table | Thinking and writing |
| **Checker** | Create | Check each cited statement against its quotes: supported, partial, unsupported, contradicted | Fact-checking |

Lead and writer are one UI row because people think of "the model that thinks
and writes"; the engine keeps them separate so an admin can split them.

---

## 3. Requirements per role

| Role | Calls per Thorough run | Typical input → output | Must have | Nice to have | Reasoning level | Output cap |
| ---- | ---------------------- | ---------------------- | --------- | ------------ | --------------- | ---------- |
| Sorter | 20–40 | 1–4k → 0.2–1k tokens | Reliable JSON, low latency, low price | Multilingual queries | low / minimal | 1,000 |
| Reader | 20–60 | 5–60k → 0.5–1.5k (bounded by the model's context, §0 #31) | Faithful extraction, low input price, ≥ 32k context (128k+ for whole documents) | 1M context for whole documents; cache pricing | low | 1,500 |
| Indexer | per chunk | — | Multilingual embeddings in one vector space with the workspace | Local reranker for EU / local worlds | — | — |
| Lead | 5–15 (round mode) · 10–40 turns (tool mode) | 10–80k → 1–4k | Judgement over many rounds, reliable JSON, prompt caching | Reliable native tool calling (passes the conformance suite → tool mode), large context, reasoning effort control | medium (Standard), high (Thorough) | 4,000 |
| Writer | 2–8 | 15–60k → 3–16k | Long output (≥ 32k), language quality in five locales, citation discipline | 128k output for long reports | medium | 8,000 per section |
| Checker | 1–4 | 10–40k → 1–3k | Different provider family from the writer, reliable JSON, strict reading | Low price | medium | 3,000 |

No role **requires** native tool calling (§0 #2): in round mode every decision
comes back as JSON, so local models behind `OllamaProvider` can fill every
role. A lead model that passes the conformance suite runs in tool mode and
drives the search itself; that is the default for frontier leads once RQ3
lists them in `TOOL_MODE_MODELS`.

---

## 4. Why combining helps — and when it hurts

**Helps:**

1. **Cost.** Reading is the largest single block of input (about 40 % of a
   Thorough run's input tokens in §6's assumptions) and the text with the least
   value per token. Moving reading, sorting and checking to models that are
   5–40 times cheaper per input token halves the estimated cost of a Thorough
   run even with a top-tier lead and writer (§6).
2. **Quality of thinking.** The lead's context holds condensed evidence, not
   forty raw pages: less "lost in the middle", more room for reasoning, fewer
   distractions. Each reader works with one narrow lens and extracts more
   precisely.
3. **Speed.** Fast inference hosts (Groq, Cerebras) sort in well under a
   second; reads run in parallel.
4. **Independence.** A checker from another lab does not share the writer's
   blind spots; the same model tends to agree with its own mistakes.
5. **Safety.** Readers quarantine untrusted text (§0 #3): an instruction hidden
   in a web page reaches a model that has no tools and whose output is a JSON
   card, never the model that decides.
6. **Sovereignty.** Each role can be filled inside a world (EU, local) on its
   own terms — §5.

**Hurts:**

1. More calls, more latency → Ask stays the fast path; Standard is short.
2. Condensing can drop the decisive detail → quotes with locations, a `reread`
   action, and the writer may ask for a source again.
3. Mixed styles → only the writer writes prose the person reads.
4. More moving parts → engine-level fallbacks inside the world and one honest
   outcome sentence when a role cannot be filled.

---

## 5. Candidates and presets

### 5.1 Candidates from the catalog (2026-10-17)

| Model (catalog key) | $/1M in / out | Cache read | Context | Max output | Jurisdiction in catalog | Fits |
| ------------------- | ------------- | ---------- | ------- | ---------- | ----------------------- | ---- |
| `groq:openai/gpt-oss-120b:chat` | 0.15 / 0.60 | — | 131k | 16k | — | Sorter (today's SORT / PLAN; routing prompts tuned for it), reader for pages |
| `cerebras:gpt-oss-120b:chat` | 0.35 / 0.75 | 0.35 | 131k | 41k | US | Sorter (very fast host) |
| `openai:gpt-6-luna:chat` | 0.10 / 0.50 | 0.01 | 1.05M | 128k | — | Reader (whole documents), sorter |
| `trustedtokens:zai-org/GLM-5.3-Flash:chat` | 0.15 / 0.30 | 0.03 | 1.05M | 33k | DE | Reader (EU world) |
| `trustedtokens:openai/gpt-oss-120b:chat` | 0.15 / 0.60 | 0.05 | 131k | 16k | DE | Sorter (EU world) |
| `trustedtokens:Qwen/Qwen3.6-35B-A3B-FP8:chat` | 0.25 / 1.50 | 0.05 | 262k | 33k | DE | Reader (EU world) |
| `google:gemini-3.8-flash:chat` | 0.75 / 3.75 (promotional to 2026-12-31) | 0.075 | 1.05M | 66k | — | Reader, checker |
| `mistral:mistral-large-4:chat` | 0.68 / 2.09 | 0.068 | 524k | 131k | — (EU provider) | Lead / writer / checker (EU world) |
| `trustedtokens:zai-org/GLM-5.3:chat` | 1.50 / 4.50 | 0.30 | 1.05M | 33k | DE | Lead / checker (EU world) |
| `meta:muse-spark-1.3:chat` | 1.25 / 4.25 | 0.15 | 1M | 33k | US | Reader, lead |
| `anthropic:claude-sonnet-5-5:chat` | 2 / 10 | 0.10 | 1M | 128k | — | Lead / writer (Standard), checker |
| `openai:gpt-6.1-sol:chat` | 2 / 10 | 0.10 | 1.05M | 128k | — | Lead / writer, checker |
| `google:gemini-3.1-pro-preview:chat` | 2 / 12 | — | 1.05M | 66k | — | Lead / writer |
| `xai:grok-4.7:chat` | 2 / 6 | 0.50 | 500k | 33k | — | Lead |
| `anthropic:claude-opus-5-5:chat` | 4 / 20 | 0.20 | 1M | 128k | — | Lead / writer (Thorough) |
| `meta:muse-wonder-1.0:chat` | 4 / 20 | 0.20 | 1.05M | 33k | US | Lead |
| `ollama:gpt-oss:120b:chat` | local | — | 128k | 16k | local | Every role (local world) |
| `ollama:gpt-oss:20b:chat` | local | — | 128k | 16k | local | Sorter (local world) |
| `ollama:qwen3.5:35b:chat` | local | — | 33k | 33k | local | Checker (local world, other family) |

Indexer: `ollama:bge-m3:vectorize` (local, the workspace default; Cloudflare
bge-m3 shares its vector space), rerank `openaicompatible:BAAI/bge-reranker-v2-m3:rerank`
(TEI, local), `cohere:rerank-v3.5:rerank`, `jina:…`, `voyage:rerank-2`.

### 5.2 Presets (hypotheses for the bake-off)

Presets are **seeded catalog keys**, never names in code. The UI shows only
the depth; the preset fills the roles inside the project's or person's world.

**Cloud world**

| Role | Standard | Thorough |
| ---- | -------- | -------- |
| Sorter | `groq:openai/gpt-oss-120b:chat` | same |
| Reader | `openai:gpt-6-luna:chat` or `google:gemini-3.8-flash:chat` (bake-off) | same |
| Lead | the chat's model (default install: Claude Sonnet 5) | preset `anthropic:claude-opus-5-5:chat` or `openai:gpt-6.1-sol:chat` (bake-off); falls back to the chat's model |
| Writer | = lead | = lead |
| Checker | another family than the writer: `google:gemini-3.8-flash:chat` | `openai:gpt-6.1-sol:chat` when the writer is Anthropic, `anthropic:claude-sonnet-5-5:chat` when it is OpenAI |

**EU world** (every role from an EU provider or an EU-hosted model)

| Role | Standard and Thorough |
| ---- | --------------------- |
| Sorter | `trustedtokens:openai/gpt-oss-120b:chat` |
| Reader | `trustedtokens:zai-org/GLM-5.3-Flash:chat` |
| Lead / writer | `mistral:mistral-large-4:chat` |
| Checker | `trustedtokens:zai-org/GLM-5.3:chat` (another family than Mistral) |

**Local world** (nothing leaves the installation)

| Role | Standard and Thorough |
| ---- | --------------------- |
| Sorter | `ollama:gpt-oss:20b:chat` |
| Reader, lead, writer | `ollama:gpt-oss:120b:chat` |
| Checker | `ollama:qwen3.5:35b:chat` (another family; 33k context → claims checked in batches) |
| Rerank | local TEI reranker, or none |

The local world is limited by hardware time, not price; its budgets are wall
clock first (`03` §4).

---

## 6. Cost estimates

**Formula.** cost = Σ over roles (input tokens × input price + cached input ×
cache price + output tokens × output price). Search provider fees are
separate (self-hosted SearXNG has none; commercial APIs charge per query).

**Token assumptions — Thorough:** sorter 30 × (2,000 in / 400 out); reader
40 sources × (12,000 / 800); lead 12 rounds × (30,000 average / 1,500), 70 %
of lead input served from the prompt cache where the model has a cache price;
writer 6 sections + 1 revision = 180,000 in / 18,000 out; checker 3 ×
(20,000 / 1,500).

**Token assumptions — Standard:** sorter 10 × (2,000 / 400); reader 15 ×
(10,000 / 700); lead 4 × (20,000 / 1,200), 60 % cached; writer 30,000 / 3,000;
checker 15,000 / 1,000.

| Run | Team | Sorter | Reader | Lead | Writer | Checker | **Total** |
| --- | ---- | ------ | ------ | ---- | ------ | ------- | --------- |
| Thorough | Single model: Opus 5.5 for every role | 0.48 | 2.56 | 0.84 | 1.08 | 0.33 | **≈ 5.29** |
| Thorough | gpt-oss-120b · Gemini 3.8 Flash · Opus 5.5 · Opus 5.5 · GPT-6.1 Sol | 0.02 | 0.48 | 0.84 | 1.08 | 0.17 | **≈ 2.58** |
| Thorough | gpt-oss-120b · GPT-6 Luna · Opus 5.5 · Opus 5.5 · GPT-6.1 Sol | 0.02 | 0.06 | 0.84 | 1.08 | 0.17 | **≈ 2.17** |
| Thorough | gpt-oss-120b · GPT-6 Luna · Sonnet 5.5 · Sonnet 5.5 · GPT-6.1 Sol | 0.02 | 0.06 | 0.42 | 0.54 | 0.17 | **≈ 1.21** |
| Thorough | EU: gpt-oss-120b (DE) · GLM-5.3-Flash · Mistral Large 4 · Mistral Large 4 · GLM-5.3 | 0.02 | 0.08 | 0.13 | 0.16 | 0.11 | **≈ 0.50** |
| Standard | Single model: Sonnet 5.5 for every role | 0.08 | 0.41 | 0.12 | 0.09 | 0.04 | **≈ 0.73** |
| Standard | gpt-oss-120b · Gemini 3.8 Flash · Sonnet 5.5 · Sonnet 5.5 · Gemini 3.8 Flash | 0.01 | 0.15 | 0.12 | 0.09 | 0.02 | **≈ 0.38** |
| Standard | gpt-oss-120b · GPT-6 Luna · Sonnet 5.5 · Sonnet 5.5 · Gemini 3.8 Flash | 0.01 | 0.02 | 0.12 | 0.09 | 0.02 | **≈ 0.25** |

All amounts in US dollars as the catalog stores them. **Caching is not free in
the code today:** `cache_control` exists only in the gateway's Anthropic
passthrough translator, not in `AnthropicProvider` for `AiFacade` calls, so
the lead rows above assume §0 #32 lands. Without it the Opus lead costs about
1.80 instead of 0.84 and the Sonnet lead about 0.90 instead of 0.42 — the
Thorough team totals become ≈ 3.5 / 3.1 / 1.7 instead of 2.58 / 2.17 / 1.21.
OpenAI and Gemini cache long prefixes without a flag; Mistral and local models
do not cache. RQ3 records the measured hit rate per provider.

**Reading the table:** the team cuts the estimated cost of a Thorough run by
about half against a single top model, and the choice of reader moves the total
more than any other role except lead and writer. That is why §0 #4 adds a
reading slot instead of reusing ANALYZE (today Claude Sonnet 5, $2 / $10). The
EU team is cheapest on paper; whether its quality holds is exactly what RQ3
must show.

---

## 7. Role resolution

### 7.1 Slots and fallbacks (web, Saved Tasks)

| Role | Resolution order | Notes |
| ---- | ---------------- | ----- |
| Lead | Thorough preset (if set and inside the world) → the chat's selected model → `CHAT` | What the person already trusts; Thorough may get a stronger lead |
| Writer | = lead → `CHAT` | Split only by an admin preset |
| Reader | `READ` → `SORT` → `ANALYZE` → `CHAT` | New slot (§0 #4) |
| Sorter | `SORT` | Unchanged slot |
| Checker | `VERIFY` → `ANALYZE` → `CHAT`, then the cross-family rule | New slot |
| Indexer | `VECTORIZE`, `RERANK` | Unchanged; rerank skipped when its provider is outside the world |

`ModelConfigService::getDefaultModel()` already walks user → group → global,
skips disallowed providers, follows retirements and falls back by capability;
the research resolver adds the order above and the two rules below.

### 7.2 World rule

- **Desktop** sends the models it allows: `models.lead`, `models.writer`,
  `models.reader`, `models.checker` as catalog keys, plus `world.providers` —
  the providers already used by the project's eight slots. Roles it leaves out
  are filled by the server **only** from `world.providers`.
- **Web** fills roles inside the person's group allow-lists.
- Embeddings and rerank obey the same rule: a local project never sends
  passages to a cloud reranker; if no allowed reranker exists, rerank is
  skipped and Details says so. Query embeddings use the operator's index model
  (the one the knowledge folder was indexed with — there is no other vector
  space); its key is named in Details, and under `RESEARCH.STRICT_WORLD`
  knowledge search is skipped when that model's provider is outside the world.
- **The search provider and the extraction chain are part of the world** (§0
  #29): only SearXNG is self-hosted; Brave, Tavily, Exa, Firecrawl and
  Perplexity are US-hosted. Outside the world the plan card discloses it or
  strict mode refuses the web source; documents from the web are extracted
  with the cloud-filtered document chain RS4 adds (today's
  `extractionChain()` flag covers audio only) so no `pdf_vision` call reaches
  a cloud vision model; the condenser runs on the reader model.
- **Input budgets follow the model** (§0 #31): per call min(role cap, 60 % of
  `meta.context_window` − output cap). A 33k local model reads a page in its
  first 15k tokens and the Details say so; the lead's ledger summary is
  compacted to its budget.
- A role that cannot be filled fails closed (`role_unavailable`, with the role
  name). There is no silent fallback to another provider.

### 7.3 Cross-family rule for the checker

If the checker's model family equals the writer's, take the first available
model of another family from `RESEARCH.VERIFY_CANDIDATES` (seeded catalog keys,
in order) inside the world. If none exists, keep the checker and record
"checked by a model of the same family" in the run summary.

**Catalog gap:** families are labs, not hosts — gpt-oss on Groq, Cerebras,
TrustedTokens and Ollama is OpenAI lineage. RS5 adds `meta.family` (`anthropic`,
`openai`, `google`, `xai`, `meta`, `mistral`, `alibaba`, `zhipu`, `moonshot`,
`deepseek`, `minimax`) to every chat row used by presets, and fills
`meta.jurisdiction` where it is missing (Mistral EU; Anthropic, OpenAI, Google,
xAI, Groq US; Ollama and Triton local), so the EU world and the cross-family
rule are computable instead of guessed.

---

## 8. The bake-off (RQ3)

**Corpus:** RQ2 (`06` §2) — 40 items, 10 of them as the smoke set.

**Stages** (not a full factorial — each stage fixes the winner of the last):

| Stage | Varies | Fixed | Metrics | Cost control |
| ----- | ------ | ----- | ------- | ------------ |
| 1 Reader | Gemini 3.8 Flash, GPT-6 Luna, GLM-5.3-Flash, gpt-oss-120b (Groq), Muse Spark 1.3 | Lead / writer Sonnet 5.5 | Passage integrity and dropped findings (deterministic), evidence fact recall, leads that settled a point, cost per source, time per source | Reader calls only, on stored source texts — no new searches |
| 2 Lead / writer | Opus 5.5, Sonnet 5.5, GPT-6.1 Sol, Gemini 3.1 Pro, Grok 4.7 | Stage 1 reader | Report fact recall, citation precision, coverage, abstention, judge rating, cost, time | Same frozen search results for every arm |
| 2b Lead mode | Tool mode vs round mode for every stage-2 lead that passed the conformance suite | Stage 2 winners | Fact recall, citation precision, search efficiency, cost, time | Live loop on the smoke set (modes differ in what they search, so frozen search results do not apply) |
| 3 Checker | Same family vs other family; Gemini 3.8 Flash, GPT-6.1 Sol, Sonnet 5.5 | Stage 2 winner | Detection rate of seeded false statements, false alarms on true ones | Drafts reused |
| 4 Single vs team | Stage 1–3 team vs single model (lead model for every role) vs B1+ and B4 | — | All report metrics, cost, time | Smoke set + 10 Thorough items |
| 5 Worlds | EU and local presets | — | All report metrics, time | Smoke set |

**Frozen inputs.** Stage 2 and later replay recorded search results and fetched
source texts, so arms differ only by the model. The live loop is measured
separately in stage 4.

**Decision rule.** Per depth and world, seed the cheapest team within two
points of the best on fact recall *and* citation precision that meets the
latency targets of `06` §5. Record the full table and the command in STATUS.
New slots (`READ`, `VERIFY`) seed into existing installs too, because their rows
do not exist yet; changing an existing default needs a migration (AGENTS.md).

**Re-run.** The bake-off is a command (`app:research:eval --bakeoff=<stage>`);
re-run it when a new model family enters the catalog or a promotional price
ends.
