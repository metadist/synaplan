# Handover — 2026-10-24

For the next agent on the research-quality track. Step log:
[`STATUS.md`](./STATUS.md). Plan: [`00_master_plan.md`](./00_master_plan.md),
step briefs in [`07_sprints.md`](./07_sprints.md), contracts in
[`10_contracts.md`](./10_contracts.md). Product rules: repository root
`AGENTS.md`. How a plan becomes pull requests:
[`../AGENTS.md`](../AGENTS.md).

Read those three files before the first edit. This file says where the track
stands and what the owner expects; it does not replace them.

---

## 1. Where the track stands

Phase C (the research engine) is open. The go / no-go gate of §0 #40 (RQ2b)
is decided: **go, by an explicit owner override.** RS5 is merged. The next
step is **RS6**.

| Step | State | Pull request |
| ---- | ----- | ------------ |
| RQ0 | done | plan, baseline, roles, engine, prompts, journeys, evaluation |
| RQ1 | done | desktop use-case run `V42D2`, 5 passed, 6 failed (recorded in STATUS) |
| RQ2 | in progress | corpus, scorer, test doubles, `app:research:eval`; B0 recorded; B4 not scored (stopped on cost) |
| RQ2b | **done** | [#2658](https://github.com/metadist/synaplan/pull/2658) spike, [#2670](https://github.com/metadist/synaplan/pull/2670) citation judge + hard corpus + owner go |
| RS1 | in progress | gateway wrap-up, [#2637](https://github.com/metadist/synaplan/pull/2637); UC-03 still red |
| RS2 | in progress | `web_fetch` on non-Anthropic `/v1/messages`; `OpenAiGatewayToolLoop` is a follow-up |
| RS3 | in progress | `knowledge_search` / `read_document` on pinned folders; UC-06 facts 0/3 |
| RS4 | in progress | document reader behind `WEB_SEARCH.READ_DOCUMENTS` (flag stays **off**) |
| RD1 | in progress | desktop file tools, B1; J-RQ-1 not walked — **do not mark done** |
| RS5 | **done** | [#2688](https://github.com/metadist/synaplan/pull/2688) |
| RS6 | **next** | — |
| RS7–RS10, RQ3, RD2–RD9, RW1–RW3, RX1–RX3 | planned | — |

The RS5 row in `STATUS.md` already says "done" with a link to #2688.

---

## 2. What was done (2026-10-22 to 2026-10-24)

### 2.1 RQ2b — spike against today's paths (#2658)

An in-process research spike (`backend/src/Service/Research/Spike/`,
`app:research:spike`) in two lead modes:

- **S-T, tool mode:** the lead calls `search` / `read` / `finish` as native tools.
- **S-R, round mode:** one JSON decision per round.

It reuses `PassageSplitter`, `PassageCheck`, `LeadUrlFilter`, `SpikeReader`,
`SpikeBoard`. These are the pieces RS6 should take over "where they proved
themselves" (07 §RS6).

### 2.2 RQ2b — citation judging, B1+ web search, hard corpus (#2670)

- `app:research:citations`: extracts cited statements from saved answers,
  loads the cited passage (an evidence id or a re-read URL), and lets two
  judges vote (`openai:gpt-6.1-sol`, `xai:grok-4.7`). A third
  (`google:gemini-3.1-pro-preview`) decides a split. It writes `report.md`,
  `pairs.json` and a blind `sample.json` of 40 pairs for people to label.
- `--answers` on `app:research:spike` and `app:research:eval` saves every
  answer with its passages (`AnswerFile`).
- `--web-search` on `app:research:eval` lets B1+ declare Anthropic's
  `web_search` tool; the gateway answers it with Synaplan search.
- New corpus `backend/tests/Eval/research/corpus-hard.json`: 12 items, five
  languages, two traps, all facts after the writer's training data. **The gold
  was checked by an agent against official sources, not by a person.**

Hard-corpus result (repeat 2, writer Claude Sonnet 5.5):

| Arm | Fact recall | Citation precision (uncalibrated) | Cost per answer | Time p50 |
| --- | --- | --- | --- | --- |
| B1+ (no web search) | 0.03 | — | 0.021 USD | 7.5 s |
| B1+ with web search | 0.96 | 64.0 % | 0.059 USD | 10.1 s |
| Spike, tool mode | 0.96 | 87.3 % (91.9 % without "not confirmed" sentences) | 0.070 USD | 34.5 s |

The gate needs ≥ 10 points more recall, or ≥ 92 % precision where B1+ is
below 92 % at ≤ 2× cost. Both clauses missed narrowly. **The owner accepted
go anyway** (recorded in STATUS, "Owner decision, 2026-10-23"): same recall
as B1+ with search, clearly better sourced, cost within 2×. That makes it an
override, not a pass. Human calibration of the judges is still open.

### 2.3 RS5 — model slots for reading and fact-checking (#2688, merged)

- `ModelCatalog::CAPABILITY_TAGS` += `READ`, `VERIFY` (both `chat`).
  `DefaultModelConfigSeeder::TEST_DEFAULTS` points both at `-1`. **No
  production binding.** RQ3 seeds them. They are not in
  `PickerCapabilities::ALL`, so the AI Models page is unchanged (RW2 adds them).
- `App\Service\Research\Run\ResearchRoleResolver::resolve(User, ResearchDepth,
  ResearchWorld, ResearchModelHints, ?int $leadModelId): ResearchRoles`:
  - Reader: `READ → SORT → ANALYZE → CHAT`.
  - Checker: `VERIFY → ANALYZE → CHAT`, then the cross-family rule against
    `RESEARCH.VERIFY_CANDIDATES` (JSON list of catalog keys, not seeded yet).
    No other family → keep it, `checkerSameFamily = true`, disclosure
    `same_family`.
  - Lead: Thorough preset → desktop hint → chat's model → `CHAT`. Writer =
    lead unless a hint splits it.
  - A retired model follows its successor. A model the group disallows, that
    the health monitor marks offline, or whose provider is outside a desktop
    world, is skipped. Nothing left → `RoleUnavailable($role)` with a plain
    sentence ("No model is available for reading sources.").
  - `ResearchInputBudget`: min(role cap, 60 % of the context window − output
    cap). A 33k reader gets 18,300 tokens and a `short_context` disclosure.
- `ConfigResearchModelLookup` implements `ResearchModelLookup` on top of
  `ModelConfigService::getDefaultModel()` (unchanged — the class is frozen),
  `GroupPolicyService::isModelAllowed()`, `ModelHealthRepository` and
  `ProviderRegistry`. **Nothing calls the resolver yet**; RS6 is the first
  caller.
- `meta.family` on the 22 preset chat rows, and `meta.jurisdiction` where it
  was missing (`US`, `EU`, `DE`, `local`). The test is `PresetModelFamilyTest`.
- `App\Plug\WebSearch\WebSearchHosting`: only SearXNG is self-hosted.
- Docs: `docs/CONFIGURATION.md` § "Research model slots",
  `docs/PRICING_MAINTENANCE.md` (meta fields).
- `Version20261008120000Test` now compares against the catalog row as it was
  on the migration date. The new meta fields reach existing installs through
  `ModelSeeder`, not through that migration. The migration itself is unchanged.

---

## 3. What is next

### 3.1 RS6 — research runs that plan, read and write a cited report

The full brief is 07 §RS6. It is the largest step in the track. In short:

- The engine of `03` without reflect and checker: units `brief_plan`, one
  `round` (gather + read), `write`, `deliver`.
- Ledger, state store, event log, outcomes, budgets, usage per role, cost
  check per unit.
- `RunResearchUnit` and its handler on `async_ai_high`, with an atomic claim,
  a fenced checkpoint and a `DelayStamp`. Routed to `sync` in `when@test`.
- `ResearchReportDelivery`: a web run writes its own message and report file
  into `chat_id`, with no tab open.
- Both controllers with complete OpenAPI, idempotency, list and delete.
- `ResearchModule` (a `FeatureModule`, default off, `RESEARCH_ENABLED` /
  `RESEARCH.ENABLED`). With the flag off, every route answers 404 and the
  routing snapshots stay byte-identical.
- Also: `ResearchConfigSeeder`, `app:research:reap`, the five
  `tools:research_*` prompts with schemas, `PassageSplitter` /
  `PassageVerifier`, `ConcurrentStructuredCaller`, one-call writing with the
  sequential fallback, the Anthropic `cache_system` option, erasure of
  `research:*`, fixtures, and `docs/RESEARCH.md`.

**Split it before you start.** One topic per pull request (`../AGENTS.md`),
and the size ratchet. A reasonable split, each piece backend-only and default
off:

1. Ledger, budget, passages, state store and event log (pure classes and Redis, no HTTP).
2. Stages and prompts with replayed test outputs.
3. Messenger unit, claim and checkpoint, reaper.
4. Module, controllers, OpenAPI, delivery into the chat, erasure, docs.

Propose the split to the owner first, and agree each piece's commit line.

**Ask the owner before you do any of these:**

- **`TestProvider.php` mocks.** The brief asks for them, but the owner said
  not to edit `TestProvider.php`. Propose another way, for example a test-only
  provider or a replay double in `tests/`, and wait for an answer.
- **New dependencies** (composer or npm), and any **Docker, CI or compose
  change** beyond the two `docker-compose.minimal.yml` lines the brief names.
- **`ConcurrentStructuredCaller`.** §0 #37 is marked "Ask (provider-layer change)".
- **Schema changes.** Research state lives in Redis per `03` §6. If a table
  turns out to be needed, it needs a Doctrine migration, Galera-safe (raw
  idempotent `addSql`, no Schema API).

### 3.2 Smaller open items from #2670 (Bugbot, not blocking)

Fix these in their own small pull request when it fits. They change judge
numbers, so re-run the hard-corpus judging afterwards and record it:

1. **High:** in `CitationExtractor`, a markdown link or bare URL after the
   sentence's full stop (`… text. ([source](url))`, which `B1PlusAdapter`
   writes) becomes its own fragment and is dropped under `MIN_WORDS`. Treat
   trailing links like trailing evidence ids and attach them to the
   sentence before.
2. **Low:** in `CitationPassages::forStatement`, a readable URL whose passages
   share no word or number with the statement returns `ok` with an empty
   passage. Return `unreadable` (or a new `no_match`) instead, so it is left
   out of precision rather than counted as "does not support".

### 3.3 Still open elsewhere in the track

- **Judge calibration (06 §4):** two people label the 40 pairs in
  `backend/var/research-eval/citations-hard/sample.json`, and each judge needs
  κ ≥ 0.7. Until then, judge numbers are a signal, never a gate.
- **RS1–RS4, RD1:** their exits are in STATUS (UC-03, UC-06, a PDF smoke item
  for RS4). Leave them unless the owner asks.
- **RQ3 bake-off** seeds `DEFAULTMODEL.READ` / `VERIFY`, the presets and
  `RESEARCH.VERIFY_CANDIDATES`. New slot rows are insert-if-missing. Changing
  an existing default needs a migration.

---

## 4. Rules the owner set on this track (still in force)

- **Chat in German, short and plain. Code, comments, commits, pull requests
  and repo docs in English.**
- **Measure, never invent.** Every number in STATUS names its command, corpus
  hash, model and window. If something was not run, say "not run".
- **After a verified change, stop and say "Volle Suite und PR sind der
  nächste Schritt."** Wait for the owner's "los". Then run the full gate,
  commit, push, and open the pull request with the template
  (`.github/pull_request_template.md`).
- Never commit to `main`. Conventional Commits; `feat` only for real
  features (it bumps the minor version). **No AI attribution** anywhere.
- One step ID per pull request. The owner merges. Sometimes the owner tells
  you to merge once CI and Bugbot are green — only then, with
  `gh pr merge <n> --squash --admin --delete-branch`.
- **Do not:**
  - deploy;
  - turn `WEB_SEARCH.READ_DOCUMENTS` on;
  - start RS6 sub-steps without agreeing the split;
  - start C3b, C7 or the PDF smoke unless asked;
  - mark RD1 done;
  - add a delete button;
  - edit `TestProvider.php`;
  - grow the size ratchet or the deptrac baseline;
  - add dependencies without asking.
- Do not change MCP `rag_search` or `POST /v1/chat/completions`. Do not touch
  the `protocol: 1` fixtures.
- **Never print the desktop harness key** (`/tmp/synaplan-desktop-harness.creds`).
  Pass it through a temp file: `docker compose cp` it to `/tmp/desktop.key`,
  `export RESEARCH_EVAL_API_KEY=$(cat /tmp/desktop.key)` inside the
  container, then `rm` both copies.

---

## 5. Environment notes (this machine)

- **Docker context:** use `m4` when it answers (`docker context use m4`), else
  `colima`. With `m4`, files sync through Mutagen (alias `m4on` in
  `~/.aliases`). Commands against `m4` may need the full network permission.
- **Edit on the host only.** Mutagen reverts edits made inside the container,
  including php-cs-fixer runs there. Run the fixer as a dry-run in the
  container and apply the changes on the host, or run it on paths and re-check.
- `make -C backend lint` fails on a missing cache file here. Use
  `docker compose exec -T backend vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --cache-file=/tmp/php-cs-fixer.cache`.
- Long jobs: `docker compose exec -d -T backend sh -c '… > var/research-eval/x.log 2>&1'`.
  A detached host `docker exec` loses its output.
- **Known local PHPUnit failures** (unrelated, red on `main` too):
  `DocumentSummaryTest` ×2, `MessageEnhanceTest`,
  `UserMemoryParseTest` ×2. Anything else red is yours.
- `scripts/mobile-impact.mjs` prints only a hash here. Classify from the
  paths: `backend/**`, `docs/**` and `_devextras/**` are backend-only;
  `frontend/**` is ota-candidate.
- Judge calls write no usage row. B1+ cost comes from `BUSELOG` windows
  (`BUSERID=1`). The spike's cost comes from its token counts.
- Seeded login: `demo@synaplan.com` / `demo123`. Writer: Claude Sonnet 5.5
  (BID 379). Judge BIDs: GPT-6.1 Sol 381, Grok 4.7 367, Gemini 3.1 Pro 185,
  Mistral Large 4 387.

---

## 6. The quality bar (AGENTS.md, UX contract)

RS6 is backend-only, but it decides what people will see in RD3–RD5 and RW1.
Build it so the screens can meet `docs/UX_CONTRACT.md` (U1–U12):

- **Every run reaches a terminal state** with one sentence a non-technical
  person understands: no HTTP code, no stack trace, a named recovery. It says
  what did and did not happen. No stuck "running"; the reaper proves it.
- **Outcomes are codes plus parameters** (`ResearchOutcome`); the copy is
  translated in the UI, in all five locales, when the UI step lands.
  `RoleUnavailable` already names the job in plain words — keep that style.
- **Works without an open tab.** A web run delivers its report into its chat
  server-side. The person finds it in ten seconds in the chat it started from.
- **Stop is one click and always works.** Cancel is checked before every paid
  call.
- **Disclose, don't hide:** a same-family checker, a short context, a
  US-hosted search provider inside an EU or local world — each becomes a
  disclosure the result card can show (02 §7.2).
- **Default off.** With the module off, nothing changes for anyone; the
  routing snapshots prove it.
- Backend craft:
  - `final readonly` services, constructor DI, strict types;
  - controllers under 50 lines with complete OpenAPI;
  - all queries in repositories;
  - no hardcoded model names (catalog keys via `ModelCatalog::findBidByKey`);
  - internal prompts carry the `tools:` prefix;
  - new code goes in new classes instead of growing frozen ones (the size
    ratchet only shrinks).
- After OpenAPI changes: `make -C frontend generate-schemas`, then `vue-tsc`.
  Run `make test-e2e` before any push that touches routes, OpenAPI the UI
  consumes, chat or auth.
