# Platform Self-Awareness — Master Plan

Status: planned (see `STATUS.md`)
Date: 2026-09-02
Supersedes: `../20260623-release4.0/06_self-aware-routing.md` (Release 4.0
Feature 6, never started). That document stays as history; every decision it
took is either carried over or explicitly replaced in Decision 1–9 below.
Related: `../20260902-office-docs/` (PDF export is the canonical
install-dependent capability), `../20260606-routing/` and
`../20260816-saved-task-workflows/` (planner vocabulary), `synaplan-docs`
(the knowledge source, separate public repo).

## Scope

Synaplan can *do* a lot but cannot *talk about* what it does. A user who asks
**"Can you create PDFs?"**, **"Would you create a song like the Beatles?"**,
**"Was kannst du?"** or **"How do I connect WhatsApp?"** today lands in the
`general` topic, whose prompt forbids "meta-commentary about your
limitations", so the model guesses — and invents features, fakes files, or
denies things the installation can actually do.

This plan makes the chat **self-aware** in two grounded ways:

1. It knows what **this installation** can do right now (models, keys, flags,
   plugins, engines), and says plainly what it cannot — with the closest
   alternative.
2. It knows what **the product** can do and how, from the official
   documentation in `synaplan-docs`, kept current with each release, and it
   links to the page it used.

Out of scope: a separate help center UI, in-app tutorials (the existing
`useHelp` tours stay as they are), and answering questions about the user's
*own* documents (that is ordinary RAG and already works).

## Goal

1. **"Can you X?" is answered truthfully for this install.** Yes / needs setup
   / not available — derived from live sources, never from a hand-written
   list. A "no" always names the nearest thing that *is* possible.
2. **"What can you do?" / "How do I…?" is answered from the docs**, in the
   user's language, with a clickable link to the page used.
3. **It stays current by itself.** The docs corpus refreshes when the docs
   change or a new release is detected; the capability inventory is computed
   per request from the running system.
4. **Impossible task requests degrade gracefully.** "Create a PDF of this"
   on an install without the office engine gets "not here, but I can deliver
   it as DOCX — want that?", not a fabricated download link.
5. **Discoverable.** An empty chat suggests "What can you do here?"; `/help`
   asks it explicitly.

## What exists today (investigated 2026-09-02)

**Nothing of the 4.0 plan was built.** No `synaplan` topic in
`backend/src/Prompt/PromptCatalog.php`, no system-owned RAG group, no
`SELF_AWARE.*` flags.

- **Routing.** `MessageClassifier::classify()` → fast path (default OFF,
  `CLASSIFIER.FAST_PATH_ENABLED`, `MessageClassifier.php` ~L590) →
  `/` tool commands (`TOOL_COMMANDS`, L32) → attachments → `MessageSorter::classify()`.
  The sorter fills `[DYNAMICLIST]` from
  `PromptRepository::getTopicsWithDescriptions(0, $userId, excludeTools: true)`,
  so a new routable topic is visible to the AI sorter and the multitask
  planner (`TaskPlanner::buildSystemPrompt()`) without new code. Both prompts
  are snapshotted in `backend/tests/Characterization/`.
- **The `general` prompt** (`PromptCatalog::generalPrompt()`) has the right
  anti-hallucination rules (never fake a file, never claim to have done
  something) but rule 3 tells the model to *redirect* any file request and the
  style section forbids talking about limitations. It has no idea what the
  installation can do, so "can you make PDFs?" gets a guess.
- **Live capability knowledge is scattered across nine places** and nothing
  aggregates it:
  `SkillCatalog::renderCapabilityList()` (planner-facing, per-user
  flag-aware), `Capability` enum, `ModelConfigService::CAPABILITY_TAGS` +
  `ModelRepository` (which model, if any, serves `TEXT2PIC` / `TEXT2VID` /
  `TEXT2SOUND` / `SOUND2TEXT` / `PIC2TEXT` / `VECTORIZE`),
  `ChatReadinessService::isChatReady()` (provider key present),
  `ConfigController::getRuntimeConfig()` (`features.help|memoryService|savedTasks|desktopAgentEnabled`,
  `plugins[].chatCommands`, `billing`, `build.version`),
  `MultitaskRoutingConfig` (`URL_FETCH_ENABLED`, `MCP_*`, `EMAIL_SEARCH_ENABLED`),
  `PluginManager`, `CapabilityService` (upload formats — the existing
  `GET /api/v1/config/capabilities` is about files, not AI features),
  `UpdateStatusService` (running + published version).
- **RAG is user-scoped at the storage level.** `QdrantVectorStorage` and
  `MariaDBVectorStorage` filter on `user_id` + `group_key`;
  `VectorSearchService::semanticSearch($query, $userId, $groupKey)` embeds
  with `getDefaultModel('VECTORIZE', $userId)`. `ChatHandler::loadRagContext()`
  (~L1649) skips RAG for `general` without a group key and otherwise uses
  `TASKPROMPT:{topic}`. `KnowledgeContextFormatter::formatRagContext()` emits
  plain `[Source N]` text; memories have clickable `[Memory:ID]` badges via
  `formatMemoriesContext()` + the `memories_loaded` SSE status +
  `frontend/src/components/MessageText.vue`.
- **Ingestion precedent that fits exactly:**
  `backend/src/MessageHandler/CrawlWidgetUrlMessageHandler.php` fetches a URL,
  prefixes `Source:`/`Title:`, calls
  `VectorizationService::vectorizeAndStore($text, $ownerId, $fileId, $groupKey)`
  with a deterministic `crc32` file id and `deleteByFile()` first, so re-runs
  replace instead of duplicate. No `BFILES` row is required.
- **Scheduler.** `_docker/backend/lib/container-runtime.sh` runs a daily
  slot on the `scheduler` role: `app:updates:check` (release manifest →
  BCONFIG) and `app:digest:run`. A third daily command drops in there.
- **The docs (`synaplan-docs`).** Custom PHP + League CommonMark site at
  `https://docs.synaplan.com`, **29 flat Markdown pages, ~209 KB, English
  only**, no front-matter, no versioning, no search index, no machine-readable
  export. Page metadata (title, description, keywords, section) lives in
  `index.php` `$docsMap`; `sitemap.php` lists the clean URLs. Best "what can it
  do" anchors: `docs/intro.md` (What's New, Core Features), `docs/using-synaplan.md`,
  `docs/dag-routing.md` (the capability table), `docs/faq.md`.
- **Two facts that make the examples in the request non-trivial:**
  PDF output is explicitly unsupported today (`MessageClassifier.php` ~L642,
  officemaker prompt) and arrives only when the office engine of
  `../20260902-office-docs/` is configured (`OFFICE_CONVERT_URL`) — the
  same question has different true answers on different installs. And **no
  music/song generation exists anywhere** (no `Capability`, no model tag) —
  an absence cannot be derived from any registry.
- **Locales are five** now: `de`, `en`, `es`, `fr`, `tr`
  (`frontend/src/i18n/index.ts`); the 4.0 plan assumed four.

## Decision 1 — two truths, one answer

Two sources feed every self-referential answer, with a fixed precedence:

| Source | Answers | Authoritative for |
| ------ | ------- | ----------------- |
| **Live capability inventory** (computed per request from the running system) | "Can you X *here*?" | what is available / needs setup / absent on **this installation**, for **this user** |
| **Docs corpus** (`synaplan-docs`, vectorized into a system-owned RAG group) | "What is X?", "How do I…?", "What's new?" | how the product works in general, where to click, links |

The inventory wins on conflict: if the docs describe video generation but no
video model is configured, the answer is "not set up here". There is **no
third, hand-maintained feature list** anywhere — the only curated text is a
tiny list of *known absences* (Decision 2), because "we do not compose music"
cannot be derived from a registry.

## Decision 2 — the inventory is a service, not a prompt

New `backend/src/Service/SelfAware/PlatformCapabilityInventory.php`
(`final readonly`, constructor DI) returns a typed `CapabilityReport`: a list
of `CapabilityFact { id, label, state: available|needs_setup|absent, detail,
alternative, adminHint, docsSlug }`, built only from sources that already
decide behaviour (see `01_sprint_1_inventory_and_routing.md` §SA1 for the
full source table). `CapabilityReportRenderer` turns it into one compact
prompt block, `[PLATFORM_CAPABILITIES]`, budgeted at **≤ ~350 tokens**, cached
per user for 5 minutes (Symfony cache, keyed on user id + flag epoch).

`KNOWN_ABSENT` is a `public const` on the inventory (≈ 6–8 entries: music /
song production, arbitrary code execution, phone calls, browsing behind a
login, editing an existing PDF in place, …), each with an `alternative` and a
`docsSlug`. It is reviewed in every release PR that adds a capability
(`04_sprint_4_eval_and_rollout.md` §SA11 adds this to the release checklist).

## Decision 3 — routing: a `synaplan` topic plus inventory in `general`

- Seed a **routable system topic `synaplan`** (owner 0, no `tools:` prefix —
  the AI sorter must be able to pick it) in `PromptCatalog`, with a sharp
  `[DYNAMICLIST]` description: *questions about Synaplan itself — what it can
  do, how to use a feature, what's new, pricing/plans, "are you ChatGPT"*.
  Add one matching rule to `tools:sort` and `tools:plan` (the planner routes
  it to a single `chat` node with `topic_id: synaplan`, optionally preceded by
  `rag_query`).
- Inject `[PLATFORM_CAPABILITIES]` into the **`synaplan` and `general`**
  system prompts (flag `SELF_AWARE.INVENTORY_IN_GENERAL`, default ON). This is
  what makes *task* requests degrade gracefully: "create a PDF of this" still
  routes wherever it routes today, but the model now knows PDF is
  `needs_setup` and offers DOCX instead of a fake link. Rewrite `general`
  rule 3 and the "no meta-commentary" style rule accordingly.
- **Fast path guard.** The fast path is default-OFF today, but when it is
  re-enabled a 30-character "can you make PDFs?" would be shortcut to
  `general` and never reach the sorter. `canFastPathClassify()` gets a
  multilingual meta-question guard (`can you|kannst du|puedes|peux-tu|
  yapabilir misin` + `what can you|was kannst|qué puedes|que peux|ne yapabilirsin`
  + the word `synaplan`) that only *defers to the sorter* — it never routes by
  itself, so a false positive costs one sorter call, not a misroute.
- `/help` joins `MessageClassifier::TOOL_COMMANDS` → topic `synaplan`.

Rejected: tool-calling ("let the model call `get_capabilities`"). Provider
tool support is not universal yet (`../20260902-office-docs/` B2 introduces
`ToolCallingChatProviderInterface`); prompt injection works on every provider
today and costs one cached render.

## Decision 4 — docs corpus: `SYSTEM:synaplan`, owner 0, fed by a manifest

- **Storage.** Reserved group key `SYSTEM:synaplan`, **owner id 0** — the
  same owner as system prompts (`BPROMPTS.BOWNERID = 0`) and system config
  (`BCONFIG` owner 0). Every listing, stats, and delete path in
  `VectorStorageFacade` is user-scoped, so the corpus is invisible to every
  real user without a single new filter. Embedding and querying both go
  through owner 0 (`getDefaultModel('VECTORIZE', 0)`), which guarantees the
  corpus and the query use the same embedding model regardless of per-user
  overrides. No schema change: `vectorizeAndStore()` with file id
  `crc32("docs:{slug}")`, exactly like `CrawlWidgetUrlMessageHandler`.
- **Source.** `synaplan-docs` publishes a machine-readable
  **`/docs-manifest.json`** (and an `/llms.txt` for humans and other agents),
  generated from `$docsMap` in `index.php`: per page `slug`, `title`,
  `section`, `description`, `url`, `raw_url` (the page's Markdown served by
  the docs site itself at `/raw/{slug}.md`, so mirrors work without GitHub),
  `sha256`, `updated_at`; plus a top-level `generated_at` and `version`.
  Synaplan fetches the manifest, diffs `sha256` per slug against its stored
  sync state, and re-vectorizes only changed pages; removed slugs are deleted
  by file id. Markdown (not the rendered HTML) is ingested — headings survive
  chunking, navigation noise does not exist.
- **Configurable URL.** `SELF_AWARE.DOCS_MANIFEST_URL` (BCONFIG, owner 0,
  default `https://docs.synaplan.com/docs-manifest.json`) so self-hosters can
  point at a mirror or at their own docs. Unreachable manifest ⇒ keep the
  current corpus, log once, answer from the inventory alone with a link.
  Air-gapped installs lose nothing they have today.
- **Retrieval-time metadata.** The sync state (BCONFIG `SELF_AWARE.DOCS_SYNC_STATE`,
  JSON: `{slug: {sha256, file_id, title, url, section, synced_at}}`) is also
  the `file_id → slug/title/url` map the answer path uses for citations.

Rejected: vendoring the Markdown into this repo (a second copy that goes
stale between releases, 200 KB of non-code in a PHP tree); crawling the
HTML via `sitemap.xml` (nav noise, lost structure); the GitHub API per query
(latency, 60 req/h unauthenticated); authoring the corpus in five languages
(the docs are English-only, see Decision 6).

## Decision 5 — freshness follows releases, not boot

- `app:selfaware:sync-docs` (idempotent, `--force` to ignore hashes,
  `--dry-run` to print the diff) runs in the **daily scheduler slot** of
  `container-runtime.sh`, right after `app:updates:check`.
- When `app:updates:check` records a **new published version**, it dispatches
  one `SyncPlatformDocsMessage` (transport `async_index`, like
  `ReVectorizeMessage`) so the corpus follows a release within minutes, not a
  day.
- **Never inside `app:seed` or the container entrypoint's blocking path** — no
  network call may delay boot. A fresh install therefore has an empty corpus
  until the first scheduler tick or a manual run; the inventory path works
  from the first request.
- "What's new?" is answered from `docs/intro.md` ("What's New" section) plus
  the running/published version from `UpdateStatusService`.

## Decision 6 — language

The docs are English. Retrieval is cross-lingual (bge-m3, 1024-dim) and the
answer is written in the user's language by the existing
`LanguageDirectiveBuilder`. The `synaplan` topic prompt is one English text,
seeded like `general`. **This replaces 4.0 decision 3** (author the corpus in
four languages). All *UI* strings (chip, `/help` description, badge tooltip)
ship in all five locales and are gated by `localeParity.spec.ts`.

## Decision 7 — citations you can click

Docs hits are formatted by a new `formatPlatformDocsContext()` in
`KnowledgeContextFormatter` that instructs `[Doc:slug]` references (one slug
per bracket, only slugs from the list — the `[Memory:ID]` rules verbatim). The
stream emits a `docs_loaded` status with `[{slug, title, url}]` before
generation, mirroring `memories_loaded`; `MessageText.vue` renders
`[Doc:slug]` as a link pill (`title` as text, `url` as href, new tab). No URL
is ever hardcoded in the frontend — it comes from the event. `[Source N]` for
user RAG is unchanged.

## Decision 8 — guardrails (kept from 4.0, sharpened)

1. Never claim a capability the inventory does not list as `available`.
2. Never fabricate a file or link (the `general` hard rules apply verbatim).
3. **Never quote prices, plan limits, or quotas.** When `billing.enabled` is
   true, link the pricing page; when it is false (self-host), do not mention
   plans at all.
4. `adminHint` ("System Config → AI Models → add an image model") is rendered
   only when the asking user is an admin; everyone else gets "ask your
   administrator".
5. **Widget conversations are excluded** by default. A widget answers for
   the operator's business, not for Synaplan; `tools:widget-default` and
   `WidgetPublicController` are not touched. WhatsApp and e-mail go through
   the same pipeline as web chat and get the same behaviour.
6. Identity: "I am the AI assistant of this Synaplan workspace, running on the
   model your workspace selected (shown in the model selector)". Never reveal
   keys, provider account details, or internal hostnames.
7. Song-like-the-Beatles class: offer *original* lyrics in the spirit of the
   style; never reproduce copyrighted lyrics; mention text-to-speech only if
   `text2sound` is `available`.

## Decision 9 — flags and rollout

BCONFIG group `SELF_AWARE`, owner 0, seeded by `SelfAwareConfigSeeder`
(`BConfigSeeder::insertIfMissing`, wired into `SeedAllCommand` as step 18
before `demo-widget`). New rows are inserted on existing installs by the
next container start; values are never overwritten.

| Setting | Default | Effect when OFF |
| ------- | ------- | --------------- |
| `ENABLED` | `true` | `synaplan` topic hidden from `[DYNAMICLIST]`, no inventory block anywhere, `/help` falls through to `general` — byte-identical to today |
| `INVENTORY_IN_GENERAL` | `true` | inventory block only in the `synaplan` topic |
| `DOCS_RAG_ENABLED` | `true` | no docs retrieval, no `docs_loaded`; sync still runs |
| `DOCS_MANIFEST_URL` | `https://docs.synaplan.com/docs-manifest.json` | empty string disables sync entirely |

Defaults are ON because sprint 1 is cheap (one cached render, no network)
and sprint 2 only ever runs on the scheduler.

## Sprints

| Sprint | Content | Detail |
| ------ | ------- | ------ |
| 1 | Capability inventory + `synaplan` topic + routing rules + graceful inability in `general` (no RAG, no network) | `01_sprint_1_inventory_and_routing.md` |
| 2 | Docs corpus: manifest endpoints in `synaplan-docs`, sync service + command, scheduler wiring, `SYSTEM:synaplan` | `02_sprint_2_docs_corpus.md` |
| 3 | Grounded answers: topic ↔ corpus binding, `[Doc:slug]` citations, `docs_loaded`, suggestion chip, `/help`, i18n | `03_sprint_3_grounded_answers_and_chat_ux.md` |
| 4 | Eval corpus + `app:selfaware:eval`, characterization review, docs, mobile-impact classification, rollout | `04_sprint_4_eval_and_rollout.md` |
| — | The golden question set the whole plan is measured against | `05_eval_question_set.md` |

Sprint 1 alone is shippable and delivers most of the felt value ("no, not
here — but DOCX yes"). Sprint 2 + 3 add "how do I…" depth and links.

## Work breakdown

| ID | Step | Layer | Size | Depends | Acceptance |
| -- | ---- | ----- | ---- | ------- | ---------- |
| SA1 | `PlatformCapabilityInventory` + `CapabilityReport` + renderer + unit tests | backend | M | — | fixture install with no keys renders only `needs_setup`/`absent`; ≤ 350 tokens |
| SA2 | `synaplan` topic, sorter/planner rules, `general` rewrite, `SelfAwareConfigSeeder`, snapshot re-record | backend | M | SA1 | Q1–Q6 of `05_eval_question_set.md` route to `synaplan`; N1–N4 do not |
| SA3 | Inventory injection in `ChatHandler`, fast-path guard, `/help` command, widget exclusion | backend | S | SA1, SA2 | "create a PDF of this" on a no-engine install answers with the DOCX alternative and no link |
| SA4 | `synaplan-docs`: `/docs-manifest.json`, `/llms.txt`, `/raw/{slug}.md` | docs repo | S | — | manifest validates against the JSON schema in `02_…` §SA4; `sha256` matches file |
| SA5 | `PlatformDocsManifestClient`, `PlatformDocsSyncService`, `app:selfaware:sync-docs`, `SyncPlatformDocsMessage` + handler, sync state | backend | M | SA4 | second run is a no-op; changed page re-vectorized; removed page deleted |
| SA6 | Scheduler wiring + trigger from `app:updates:check` + `docs/ADMIN.md` | docker/backend | S | SA5 | daily slot logs one sync line; new version ⇒ message dispatched |
| SA7 | `PlatformDocsRetriever` bound to `synaplan` (chat + multitask), `formatPlatformDocsContext()`, `docs_loaded` SSE | backend | M | SA3, SA5 | "How do I connect WhatsApp?" cites `[Doc:channels]` |
| SA8 | `docs_loaded` handling + `[Doc:slug]` pill in `MessageText.vue`, i18n ×5 | frontend | S | SA7 | pill readable light/dark/V2; unknown slug renders as plain text |
| SA9 | Empty-chat suggestion chip, `/help` in `ToolsDropdown.vue`, `features.selfAware` in runtime config + schema regen | frontend/backend | S | SA3 | chip hidden when `ENABLED=false`; parity test green |
| SA10 | `tests/Eval/self_aware_eval_corpus.json` + `app:selfaware:eval` (pattern: `PlanEvalCommand`) | backend | M | SA7 | all rows of `05_…` pass on a dev install with one chat key |
| SA11 | Docs (`using-synaplan.md`, `administration.md`, `faq.md`), README line, release checklist entry for `KNOWN_ABSENT`, `.github/mobile-impact-policy.json` | docs/repo | S | SA9 | `node scripts/mobile-impact.mjs` classifies new paths |
| SA12 | Rollout on fresh install + existing install, STATUS update | ops | S | all | flags present after container start; first scheduler tick fills the corpus |

Definition of done for every step: unfiltered gate green, all five locales,
characterization diff reviewed line by line, no new hardcoded URL, no new
schema. The plan is done when every row of `05_eval_question_set.md` passes
in `app:selfaware:eval` on a dev install with one chat provider key.

## Compatibility invariants

- **C1** — Flags OFF ⇒ byte-identical behaviour to today (prompts, routing
  snapshots, SSE events).
- **C2** — No network call on the boot path (`app:seed`, entrypoint,
  migrations). Sync runs only on the scheduler, on demand, or from a Messenger
  message.
- **C3** — No database schema change. The corpus is vectors + one BCONFIG JSON
  row; the topic is a `BPROMPTS` row; flags are `BCONFIG` rows.
- **C4** — The docs corpus is never visible in any per-user file, group, or
  stats listing, and never deleted by a user action. Owner 0 must never be a
  real login.
- **C5** — Widget, `tools:widget-default`, `WidgetPublicController`, and the
  guest landing are untouched.
- **C6** — The inventory never states `available` without a live source that
  already gates the behaviour it describes.
- **C7** — No prices, limits, or quotas in any generated answer.
- **C8** — Every user-facing string exists in `de`, `en`, `es`, `fr`, `tr`.

## Ground rules (every step)

- Feature branch per step, Conventional Commits, never on `main`, no AI
  attribution.
- Full gate before every commit — filtered runs are not the gate:

  ```bash
  make lint && make -C backend phpstan && make test && docker compose exec -T frontend npm run check:types
  ```

- Any change to `PromptCatalog` (`tools:sort`, `tools:plan`, `general`),
  `MessageClassifier`, or `MessageSorter` ⇒ re-record
  `tests/Characterization/` snapshots (`UPDATE_ROUTING_SNAPSHOTS=1`) and
  review every changed line. The `synaplan` topic appearing in
  `[DYNAMICLIST]` WILL change `planner_system_prompt.txt`.
- New/changed runtime-config fields ⇒ OpenAPI annotations ⇒
  `make -C frontend generate-schemas` ⇒ `vue-tsc`. No hand-written TS
  interfaces.
- New UI text ⇒ all five locales; `localeParity.spec.ts` gates it.
- Colors only via `style.css` tokens; check the `[Doc:slug]` pill and the
  suggestion chip in light, dark, and V2.
- New paths ⇒ classify in `.github/mobile-impact-policy.json` (`backend/**`
  backend-only, `frontend/src/**` ota-candidate, `_docker/**` and docs
  no-app-impact).
- `SA6` edits `_docker/backend/lib/container-runtime.sh` — a Docker runtime
  file. Per `AGENTS.md` "Ask first", that PR is opened only after explicit
  go-ahead and touches nothing but the daily slot.
- This repository is public: no production hostnames, node IPs, or
  credentials here; the docs manifest URL is the public docs site.
