# Installations — one engine, every setup

**Status:** Draft 2026-10-17 (review finding `08` §1.4). Research must behave
honestly on every way Synaplan is installed: from a laptop with Ollama and no
internet to the three-node cloud. This file is the matrix the steps build
against and the tests prove. Profiles are named by what is *present*, not by
who runs them.

---

## 1. Profiles

| Id | Setup | Where it comes from | Models | Web search | Documents from the web | Knowledge (embeddings) | Worker |
| -- | ----- | ------------------- | ------ | ---------- | ---------------------- | ---------------------- | ------ |
| **P-DEV** | Developer stack | `docker-compose.yml` (`make up`) | Whatever keys are in `backend/.env` + Ollama (`local-ai` profile) | Brave if a key is set; SearXNG via profile | Tika (+ Docling profile) | Ollama `bge-m3`, Qdrant | One process, three transports, `--memory-limit=512M` |
| **P-TEST** | CI | `docker-compose.test.yml` / `.test.ci.yml` | `TestProvider` only (`DEFAULTMODEL.* = -1…-7`) | `test` adapter (fixtures) | `FixtureSourceReader` | `test-vectorize` (-2), `qdrant_test` | **None** — `when@test` maps the transports to `in-memory://`; `RunResearchUnit` is routed to `sync://` like `ExtractMemoriesCommand`, so units run inside the request (`06` §8) |
| **P-CLOUD** | Single-node production, cloud AI | `deploy/compose.yaml` (default profile), `deploy/quickstart/compose.yaml`, Umbrel, AWS Marketplace | Cloud providers the operator keyed; no Ollama | Brave / Tavily / Exa / Firecrawl / Perplexity with a key; none by default | Tika (in the compose) | Cloud embeddings (OpenAI / Cloudflare) or none until the operator binds one | One worker + one scheduler |
| **P-LOCAL** | Air-gapped or local-first | `deploy/compose.yaml` with `local-ai`; `docs/air-gapped.md` | Ollama only (`gpt-oss:20b/120b`, `qwen3.5:35b`), Triton | SearXNG if deployed, else none | Tika; no cloud step | Ollama `bge-m3`; MariaDB VECTOR or Qdrant | One worker; one GPU |
| **P-EU** | EU-only providers | Any of the above with Mistral + TrustedTokens keys and group allow-lists | Mistral, TrustedTokens (DE), Ollama | SearXNG self-hosted, or a US provider under disclosure | Tika / Docling | Ollama `bge-m3` or TEI | — |
| **P-MIN** | Minimal | `docker-compose.minimal.yml` override: every optional service env **blank** (no Tika, Docling, SearXNG, Ollama, compute) | Cloud keys only | None | **None** (native text only: `.txt`, `.md`, `.html`) | None unless a cloud embedding is bound | One worker |
| **P-K8S** | Kubernetes | `synaplan-charts` (`worker.transports`, `worker.replicaCount: 1`, RWO uploads PVC, optional external Redis) | As keyed | As configured | Tika sidecar when enabled | Qdrant or MariaDB VECTOR | `worker` deployment; transports configurable |
| **P-PROD3** | `web.synaplan.com` | `synaplan-platform` (private): three web nodes, Galera outside Docker, shared Redis, NFS | Full cloud catalog | Brave | Tika / Docling | Qdrant | Workers per node |

---

## 2. What research does in each profile

| Capability | P-DEV | P-TEST | P-CLOUD | P-LOCAL | P-EU | P-MIN | P-K8S | P-PROD3 |
| ---------- | ----- | ------ | ------- | ------- | ---- | ----- | ----- | ------- |
| Standard / Thorough runs | yes | yes (replayed) | yes | yes, slower; `RESEARCH.LOCAL_PARALLEL` = 1 | yes | **files only**, or absent | yes | yes |
| Web source | if provider keyed | fixtures | if provider keyed | SearXNG or **not set up** | SearXNG, or hosted under disclosure / refused in strict mode | **not set up** | as configured | yes |
| Pages read | yes | fixtures | yes | yes (local network rules unchanged: SSRF guard) | yes | yes if web | yes | yes |
| PDFs / office files from the web | yes | fixtures | yes (Tika) | yes, chain without cloud steps | yes | **no** — "could not read this kind of file" | if Tika | yes |
| Knowledge source | yes | yes | only after an embedding model is bound | yes | yes | only with a cloud embedding bound | yes | yes |
| Hybrid keyword leg (RS9) | Qdrant | Qdrant | Qdrant | Qdrant only; MariaDB VECTOR = meaning only, said in Details | per store | — | per store | yes |
| Rerank | if bound | skipped | if bound | TEI local or skipped | TEI local or skipped | skipped | if bound | yes |
| Fact-check (second provider) | yes | yes | yes if ≥ 2 families keyed; else same family, said in the summary | `qwen3.5:35b` vs `gpt-oss` | GLM vs Mistral | same family if one key | per keys | yes |
| Prompt caching | Anthropic / OpenAI / Gemini | n/a | per provider | no | Mistral no; measured | per provider | per provider | yes |
| Background continuation | yes | yes | yes | yes | yes | yes | yes, if `worker.transports` includes `async_ai_high` | yes |
| Cost shown | catalog prices | 0 | yes | 0 (local rows are priced 0) | yes | yes | yes | yes |

**Rule:** no profile ever shows a dead control. When a source is unavailable
its line on the composer says why and names the next step (`05` §8); when no
source is possible the Research chip is still present but **Start research**
is disabled with the sentence "Add files or turn on the web so there is
something to research." When the module is off (`RESEARCH.ENABLED = 0`) or
the server is older, there is no chip at all.

---

## 3. Degradations, in the person's words

| Situation | Composer / plan card copy (en) | Where it is decided |
| --------- | ------------------------------ | ------------------- |
| No web search provider | "the web — not set up on your Synaplan workspace" (disabled) | `GET …/capabilities` → `web_search_available: false` |
| Search provider outside the project's world (disclose) | "Web search goes through {provider}, hosted in {country}." on the plan card and in Details | `WebSearchCapabilities::selfHosted`, provider hosting table (`10` §4) |
| Same, strict mode | "the web — not available in this project's world" (disabled) | `RESEARCH.STRICT_WORLD = 1` |
| No embedding model bound | "this project's files — the workspace has no index model yet" (disabled); admins get a link | capabilities → `knowledge_available: false` |
| Index model outside the world | Details: "Files are searched with {model}, the workspace's index model." (disclose) or knowledge disabled (strict) | world rule |
| Tika absent (P-MIN) | Source line in Details: "PDF — this workspace cannot read this kind of file" | `extractionChain('document')` empty beyond native |
| Local world, one GPU | Estimate line: "About 25 minutes on your workspace's own hardware" | `RESEARCH.LOCAL_PARALLEL`, timing from the first two reader calls |
| No worker consumes research units | Progress card after 5 min without a unit start: "Waiting for a free worker on your Synaplan workspace." Status page: "No worker has picked up research units for 5 minutes." | `queued` event age; module status |
| One provider only | Result card: "Fact-check by a model of the same provider (no second provider in this project)." | cross-family rule |
| Context too small for a source | Details: "Read the first part of {host} (the model's memory is too small for the whole page)." | per-role input budget (`00` §0 #31) |

---

## 4. Tests per profile

| Profile | Automated | Where it runs | Manual walk (STATUS entry) |
| ------- | --------- | ------------- | --------------------------- |
| P-TEST | PHPUnit replays (every stage, every outcome, injection, multilingual, erasure, sweeper transitions, idempotency, the concurrent-claim race, flag-off read / delete); Playwright `@ci` journey J-RQ-8 with the doubles and the `sync` unit transport; contract fixtures | GitHub CI on every PR | — |
| P-DEV | `app:research:eval --corpus=smoke` on real providers; Desktop quick set (UC-00…16) against the dev stack; the fairness check — a file uploaded during an active Thorough run reaches `Ready` within its normal time (RS6 exit) | Before every PR that touches the path | J-RQ-1…7, 9 on Windows + one other OS |
| P-CLOUD | `app:research:eval --arm=preset:cloud.*` full set (RQ3); `deploy/compose.yaml` smoke: start a Standard run, close the browser, reopen → report present | RQ3; once before the flag is seeded on | J-RQ-10 (admin) |
| P-LOCAL | `app:research:eval --arm=preset:local.standard` smoke on the `local-ai` profile with `gpt-oss:20b` + `bge-m3` pulled, `SEARXNG_BASE_URL` empty → files-only run must complete; strict world refuses a hosted provider | RQ3; and whenever a local preset changes | J-RQ-7 steps 4–5; `air-gapped.md` chapter followed from zero |
| P-EU | `--arm=preset:eu.*` smoke with Mistral + TrustedTokens keys and a SearXNG; disclosure line present with Brave | RQ3 | J-RQ-7 steps 1–3 |
| P-MIN | `docker compose -f docker-compose.yml -f docker-compose.minimal.yml` + one cloud key: composer shows both sources disabled with reasons; after binding a cloud embedding, a files-only run with `.md` files completes; a web PDF is reported unreadable | RS6 exit, repeated at RQ3 | — |
| P-K8S | `make check` in `synaplan-charts` renders with `worker.transports: "async_ai_high async_extract async_index"`; a helm-unittest asserts the default includes `async_ai_high` | `synaplan-charts` PR (docs + test only) | One cluster walk recorded in that repo |
| P-PROD3 | — (no code here) | — | First deploy: a Standard run started on node A, SSE reattached through node B, report found; entered in STATUS with the date (details in `synaplan-platform`) |

Use-case ids UC-11…UC-16 (`06` §7) run in P-DEV by default; `--profile local`
selects the local preset and expects the local degradations.

---

## 5. Upgrade, downgrade, rollback

| Pair | Behaviour |
| ---- | --------- |
| New server, old Desktop | Desktop never calls `/v1/research`; nothing changes for it. RS3's gateway tools are gateway-owned, so an old Desktop sees no unpaired calls (`03` §12) |
| Old server, new Desktop | `GET /v1/research/capabilities` → 404 → no chip, no tile, no team section (U11). Desktop shows the existing "update your Synaplan" hint only where it already does |
| Flag turned off while runs exist | Running units finish their current unit and stop with `stopped_by_admin` after writing what was found; `capabilities`, start, `followups` and `input` answer 404; the owner's `GET`, events and `DELETE` keep working until the TTL (`03` §7.1); web reports already delivered stay in their chats; Desktop reports stay local |
| Server rollback to a release without research | Redis keys expire by TTL; Desktop reports stay local; no schema to roll back in v1 (§0 #7) |
| Seeded presets after a model retirement | `ModelRetirementSeeder` and the successor chain in `ModelConfigService` apply to `READ` / `VERIFY` like to every slot; `RetiredModelReferenceTest` fails a build that keeps a dead key in a preset constant |

---

## 6. Documentation matrix

In-repo (`synaplan/docs/`), user docs (`synaplan-docs/docs/`), Desktop repo
(`synaplan-desktop/docs/`). "Step" is where the row is written; RX1–RX3 are the
`synaplan-docs` pull requests (`07`).

| Document | What it gets | Step |
| -------- | ------------ | ---- |
| `docs/RESEARCH.md` (new) | The run, roles, budgets, world rule, API, events, outcomes, operations (reaper, status, Redis sizing), test doubles | RS6, updated by RS7–RS10 |
| `docs/FEATURE_FLAGS.md` | `RESEARCH.ENABLED`, `FEATURE_RESEARCH_ENABLED` pin | RS6 |
| `docs/CONFIGURATION.md` | Every `RESEARCH.*`, `MESSAGES_GATEWAY.WEB_SEARCH_MAX_ROUNDS`, `WEB_FETCH_MAX_PER_TURN`, `KNOWLEDGE_TOOLS`, `WEB_SEARCH.READ_DOCUMENTS`, `RATELIMITS_*.RESEARCH_*` | RS1–RS4, RS6, RQ3 |
| `docs/ANTHROPIC_COMPATIBLE_API.md` | Wrap-up behaviour, Synaplan-run `web_fetch` on non-Anthropic routes, `knowledge_search` / `read_document` | RS1–RS3 |
| `docs/OPENAI_COMPATIBLE_API.md` | Whether `web_fetch` is injected there (RS2 scope decision) | RS2 |
| `docs/DESKTOP.md` | `/v1/research*` routes, fixtures, `research_protocol: 1` | RS6 |
| `docs/RAG.md` | Hybrid search, document cards | RS9 |
| `docs/ARCHITECTURE.md` | The research run path next to the chat and stream paths | RS6 |
| `docs/HEALTH_MONITORING.md` | Module status row, reaper, log channel, Redis sizing | RS6 |
| `docs/E2E_TESTING.md` | The doubles and the `@ci` research journey | RQ2, RW1 |
| `docs/MIGRATIONS.md` | Nothing in v1; a note if §0 #7's table is chosen | — |
| `synaplan-docs/docs/desktop-research.md` (new) | Research in Desktop for people: start, depth, plan, sources, Word, team, what stays where | RX2 |
| `synaplan-docs/docs/desktop.md` | "What you can do" gains research; file tools without a skill | RX1 |
| `synaplan-docs/docs/claude-code.md` | Longer sourced answers, page reading on every model, knowledge tools, `/v1/research` from a full key | RX1, RX2 |
| `synaplan-docs/docs/ai-providers-and-models.md` | Reading sources and fact-checking slots; presets per world | RX2 |
| `synaplan-docs/docs/air-gapped.md` | A chapter "Research without the internet" (files only, local team, timings) | RX2 |
| `synaplan-docs/docs/kubernetes.md`, `hosting.md` | Worker transports must include `async_ai_high`; Redis sizing | RX2 |
| `synaplan-docs/docs/administration.md` | The research tab, budgets, plan limits | RX3 |
| `synaplan-docs/docs/using-synaplan.md` | Research mode in the web chat | RX3 |
| `synaplan-desktop/README.md`, `docs/DEVELOPMENT.md` | Research in the feature list; mock server routes | RD3 |
| `synaplan-charts/charts/synaplan/README.md.gotmpl` | The transport note (generated README) | with RX2 |
