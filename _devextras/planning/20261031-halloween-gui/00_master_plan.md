# Halloween GUI — your own model server in five minutes

**Status:** Plan only, written 2026-10-30. §0 is unticked; no step starts
before the owner ticks it.
**Folder:** `20261031-halloween-gui`, the owner's name for the track.
**Base:** `main` at `466b96818`.
**Roadmap:** row 33 (not started).
**Binding:** [`docs/UX_CONTRACT.md`](../../../docs/UX_CONTRACT.md) U1–U12
and its §6, and the AGENTS.md "Perfect UX & Stability" bar.

| File | Content |
| ---- | ------- |
| this file | brief, decisions, people, journeys, steps, gates, risks |
| [`01_findings.md`](./01_findings.md) | what a fresh administrator meets today, read from the code and measured on the dev stack (F1–F14) |
| [`02_journeys_and_copy.md`](./02_journeys_and_copy.md) | journeys J-HG-1…6, wireframes, the copy for review before any Vue file |
| [`03_steps.md`](./03_steps.md) | one section per pull request: files, tests, gate, exit bullets |
| [`STATUS.md`](./STATUS.md) | step log and walks |

## Owner brief (2026-10-30)

> Setting up a fresh install with a local Ollama or vLLM (any
> OpenAI-compatible backend) is confusing in the UI. When I start
> Synaplan for the first time as an administrator and add my Ollama
> server (localhost or remote), it should list the models on that server
> and offer to import them with a cost of 0 (a default value in an input
> field) into BMODELS. It should tell the administrator that the models
> are in BMODELS and that they can edit them any time. The onboarding
> must become better — something nice for the testing developers and for
> fresh administrators. We cater to DevOps professionals, and to
> beginners who click into the app and just have an Ollama with two
> models.

## Goal

A fresh administrator who has an Ollama — or any server that speaks the
OpenAI API — chats with their own models **from the setup wizard, in
under five minutes, without editing a file or restarting a container**:
accept or type an address, see the server's models, import them at a cost
of 0, choose one for chat, read one real answer. A DevOps engineer reaches
the same state with two console commands, and afterwards both see the
same server, the same rows and where they came from on the same screen. A
developer walks that first run on a disposable fresh install with one
`make` target, and CI walks it on every pull request.

## Why — the short version of the findings

1. The wizard has no way to connect a model server, so an Ollama-only
   administrator skips the AI step and meets a full-page **No AI provider
   configured** that promises a local Ollama needs no key (F1).
2. Ollama and every other self-hosted server live on two different tabs,
   one of which asks for a slug and raw tags (F2).
3. The *Ollama server URL* field writes `backend/.env` and asks for a
   restart, but no shipped compose file lets that value through — the
   public docs give the same advice (F3).
4. An import stores no choice: the stored chat binding stays on a cloud
   provider without a key, and chat answers, if at all, on a model the
   resolver picks by quality (F4).
5. The server says what each model can do, and the importer guesses from
   names instead; a model that runs on ollama.com is offered as a free
   local one; `bge-m3:latest` is imported a second time (F5–F7).
6. There is no cost field, no sentence about where the rows went, one
   failure sentence for every cause, no console path, and no first-run
   test anywhere (F8–F12).
7. Two servers with the same model id collide: discovery, import and
   routing identify an OpenAI-compatible model by its id alone (F14).

---

## 0. Decisions (unticked)

| # | Decision | Proposed | Why |
| - | -------- | -------- | --- |
| D1 | **Where a server lives** | Every server an administrator adds — in the UI or with the new console command — is an endpoint of the existing `OpenAiCompatibleEndpointRegistry` (`BCONFIG` group `openai_compatible`, encrypted), an Ollama included (`…/v1`) and marked `kind: ollama`, so discovery reads Ollama's own listing (`/api/tags`). `OLLAMA_BASE_URL` stays what the environment pins: the bundled `local-ai` Ollama, or one an operator sets. | Applies at once, survives restarts, any number of servers, already SSRF-guarded and encrypted. `OpenAICompatibleProvider` calls tools, reads images and reads Ollama's `delta.reasoning` today; `OllamaProvider` does neither tools nor images and takes its address once from `services.yaml` (F13). Rejected: making `OLLAMA_BASE_URL` editable at runtime — one server, no tools, no images, and every reader of the variable changes. "Any number of servers" holds only after HG2 makes the server part of a model's identity: today the same model id on two servers collides in discovery, import and routing (F14). HG0 can overturn this row if its measured matrix fails. |
| D2 | **The UI never writes `OLLAMA_BASE_URL`** | When the app process has a value, Providers & keys shows it read-only: "Set by the server environment (OLLAMA_BASE_URL)". When it has none, the field is gone and the connect card takes its place. | The write cannot take effect on any shipped compose file (F3). A field that saves and does nothing is a dead control (U5, U11). |
| D3 | **One connect card** (U12) | The same card in wizard step 2, as the first section of AI infrastructure → Providers & keys (replacing the Local AI URL field and the *Add your own service* link), and behind **Connect a server** above the model catalog's endpoint list. The existing endpoint form stays as **Edit** on a row, for custom headers. | One job, one place, one vocabulary (F2). |
| D4 | **The address the administrator knows** | Accept `localhost:11434`, `192.168.1.20:11434`, `gpu01:8000`, `https://gpu.example.com/v1`, with or without scheme, `/v1`, `/api` or a trailing slash. Recognise Ollama (answers `/api/version` and `/api/tags`) or an OpenAI-compatible server (answers `/v1/models` or `/models`). When `localhost` fails inside a container, try `host.docker.internal`, then the bundled name `ollama`, and show the address that answered with one sentence why. Save only the address on screen, after the administrator connects. | "localhost or remote" from the brief; container networking is the most common beginner failure (F10). No silent rewrite. |
| D5 | **Suggestions** | On wizard step 2 and on an empty card, check a fixed list once: the bundled `ollama:11434`, `host.docker.internal:11434` (Ollama), `:1234/v1` (LM Studio) and `:8080/v1` (llama.cpp) on `host.docker.internal`. Administrator only, in parallel, at most 2 s in total, never a range or port scan, never Synaplan's own address, skipped entirely when `OPENAI_COMPATIBLE_PUBLIC_UPSTREAMS_ONLY` is set. Only an answer that looks like a model server becomes a suggestion; anything else on those ports (Keycloak uses 8080 on the dev stack) is ignored silently. | The beginner with Ollama on the laptop sees "Found a model server — Ollama · 2 models · On the same machine as Synaplan" and clicks once. A fixed list is not a scanner. |
| D6 | **Cost, with 0 as the default value** | Every row of the import list has an input cost and an output cost in USD per 1 million tokens, plus one **Cost for all selected** field. Prefilled 0 for a server on the operator's network that publishes no price; the published price when the server publishes one; empty and unselected for a model that runs elsewhere (D10). A 0/0 row keeps `BSHOWWHENFREE=1`. An import never reprices a row that already exists. | The brief. 0 is the honest value for own hardware, and the field lets an operator charge GPU time into statistics and budgets (row 23). `BSHOWWHENFREE` is the [#2110](https://github.com/metadist/synaplan/issues/2110) trap (F8). |
| D7 | **Choose a model for chat in the same step** | The import list has **Use for chat** and **Use for Embedding / Vectorization** (and **Use for Vision** when a model reads images on this route). Importing with a choice binds the **instance** defaults (`ownerId = 0`): the chat model to CHAT, TOOLS, ANALYZE, SORT, PLAN, SUMMARIZE and MEM — the seven purposes `ProviderDefaultsService` writes for a cloud provider — creating its system-only `mem` row; the embedding model to VECTORIZE only while no vectors exist, otherwise the existing embedding switch with its estimate; `ai.default_chat_provider` to the route, so the start-time repair leaves it alone. The Synaplan Demo is left first. Personal overrides stay; the result says when the acting administrator's own choice still differs. | Without this nothing records which model answers: chat runs on the resolver's last-resort pick, which the administrator never chose and which moves when another provider becomes usable (F4). SORT and PLAN go along because their seeded model needs a key the beginner does not have; HG0 measures what that costs in routing quality (R2). |
| D8 | **Where it went** (the brief) | The result card says, in this order: how many models were imported and from which server; which one is now the installation's default chat model; what did **not** change; that names, costs and purposes can be changed any time under AI infrastructure → Model catalog (with a button). Below that, visible, for administrators: "Stored as N rows in the BMODELS table. The server's address is stored encrypted in BCONFIG." | The owner asked for BMODELS by name. It is an admin-only surface, so the table name sits under the plain sentence instead of behind a disclosure. |
| D9 | **What a model can do comes from the server** | Tags from the server's own capability list (Ollama `/api/tags` `capabilities`, measured on 0.32.1 and 0.40.12), then `/api/show` for a server that omits them, then today's name guess. Context length and embedding size are kept. The opt-in probe stays for OpenAI-compatible servers. The tag text field moves behind **Edit purposes**. | F5. The beginner sees "Chat / General AI · tools" instead of a comma-separated tag field. |
| D10 | **Models that run elsewhere** | A model with `remote_host` (Ollama `:cloud`) is listed, unselected, with an empty cost and "Runs on ollama.com, not on your server. Messages sent to it leave your network." | F6. Never call a remote model local or free. |
| D11 | **A fresh install for developers** (AGENTS.md: ask first — Docker) | `make fresh` starts the test stack with an overlay: `SEED_DEMO_DATA=false`, empty `OLLAMA_BASE_URL`, the model stub reachable under the name `ollama`, `host.docker.internal` mapped. `make fresh-down` removes it, volumes included. The dev stack and its data are never touched. | F11. A developer walks a real first run against current code, as often as needed. |
| D12 | **CI walks the first run** (AGENTS.md: ask first — CI) | A separate Playwright config for `@fresh`, without the shared `globalSetup` / `globalTeardown` (they sign in as the seeded administrator and rewrite the instance defaults on whatever `BASE_URL` points at — the dev stack by default), always run with `BASE_URL=http://localhost:8001`; and one CI job, *E2E Tests (fresh install)*, on that overlay, required by **All Checks Passed**. | F11. The journey that matters most to a new user is the one with no test, and a fresh-install run must never touch the dev stack. |
| D13 | **A name for the host in shipped compose files** (AGENTS.md: ask first — Docker) | `extra_hosts: ["host.docker.internal:host-gateway"]` on the app roles of `deploy/compose.yaml`, `deploy/quickstart/compose.yaml`, `deploy/quickstart/compose.lite.yaml`, and the CasaOS and Umbrel files where the platform accepts it. | F10. Without it a Linux quickstart cannot reach an Ollama on the same machine by any name. |
| D14 | **A console path for operators** | `app:model-server:add`, `app:model-server:list`, `app:model-server:remove` and `app:model:import` (`--all`, `--model`, `--cost-in`, `--cost-out`, `--use-for-chat`, `--use-for-search`, `--dry-run`, `--format=json`), idempotent, on the same services as the API. No new environment variable in this track; an environment bootstrap for model servers belongs to the config pyramid (row 15). | F12. Scripts reach the same state the UI does, and the UI shows they did. |
| D15 | **One word** | **model server** — de *Modellserver*, es *servidor de modelos*, fr *serveur de modèles*, tr *model sunucusu*. "Endpoint", "OpenAICompatible" and slugs leave primary copy; "OpenAI API" appears only in the line that lists what is supported. Existing words stay: *AI infrastructure*, *Providers & keys*, *Model catalog*, and the purpose labels of the AI models page. | AGENTS.md: one canonical term per concept, no implementation jargon in primary copy. |
| D16 | **Release classes** | No step is `store-required`. Backend steps are `backend-only`, or `ota-candidate` when they regenerate `frontend/src/generated/api-schemas.ts`; UI steps `ota-candidate`; tooling, CI and docs `no-app-impact`. No migration: provenance and kind live in the encrypted endpoint payload, model details in `BJSON`. | `.github/mobile-impact-policy.json`; the mobile app gets the wizard over the air. |

---

## 1. People and journeys

Three people this track is for:

- **Mia, first administrator.** Docker Desktop on a laptop, the Ollama
  app with two models (`llama3.2:3b`, `bge-m3`), Synaplan from
  `deploy/quickstart/compose.yaml`. She knows `localhost:11434`, not
  container networking.
- **Jonas, operator.** A Linux host, vLLM on a GPU box
  (`https://gpu01.internal/v1`, with an API key) and a shared Ollama. He
  wants commands, idempotence and provenance, and wants the UI to show
  what he scripted.
- **Dev, developer or tester.** The dev stack with fixtures. Wants to
  walk a real first run against current code without losing the dev
  database, and to see the same walk in CI.

| Journey | Who | What (full text in [`02`](./02_journeys_and_copy.md)) |
| ------- | --- | ------------------------------------------------------ |
| **J-HG-1** | Mia | First run: wizard → "Found a model server: Ollama" → two models at cost 0 → use `llama3.2:3b` for chat → one real answer → Continue → chat answers. |
| **J-HG-2** | Jonas, or Mia later | A remote server with a key (vLLM) from AI infrastructure: connect → import with a cost → use for chat. |
| **J-HG-3** | Mia, a week later | Find, edit, undo: the server row → model catalog filtered to that server → change a cost → switch a model off → "1 new model on the server" → import it → disconnect the server, with the consequence in one sentence. |
| **J-HG-4** | Mia | Failures, each with one sentence and a named recovery: localhost inside the container, Ollama only on 127.0.0.1, nothing listening, a web page instead of a model server, a key needed, no models yet, a cloud model, a public-only installation, a slow first answer. |
| **J-HG-5** | Jonas | No browser: `app:model-server:add` + `app:model:import --use-for-chat … --format=json`; then the UI shows the same server, "Added from the command line". |
| **J-HG-6** | Dev | `make fresh` → J-HG-1 against the model stub → `make fresh-down`; CI runs the same as `@fresh`. |

**Ten-second findability (U2), named before any Vue file:** the imported
models are in AI infrastructure → **Model catalog**, filtered to the
server; the server is the first section of **Providers & keys**, with
"N models in the catalog · M new on the server"; in chat the models are
in the model picker under the server's name. The result card links the
catalog, and its sentence names the path.

---

## 2. Steps

One branch, one commit, one pull request per step
([`../AGENTS.md`](../AGENTS.md)). Details in [`03_steps.md`](./03_steps.md).

| Step | Topic | Class | Commit subject | Needs | Closes | Journey |
| ---- | ----- | ----- | -------------- | ----- | ------ | ------- |
| HG0 | Measure the `/v1` route; teach the model stub the listing and the OpenAI routes | no-app-impact | `test(e2e): teach the model stub Ollama's listing and the OpenAI routes` | §0 | (D1 evidence), F11 part | — |
| HG1 | A disposable fresh install for developers | no-app-impact | `chore(dev): start a disposable fresh install next to the dev stack` | HG0, D11 | F11 part | J-HG-6 part |
| HG2 | A model's identity: its server, and `x` = `x:latest` | backend-only | `fix(models): tell imported models apart by server and treat x:latest as x` | §0 | F7, F14 | — |
| HG3 | The server's own capability list; cloud models flagged | ota-candidate | `feat(models): read capabilities and cloud models from the server listing` | HG0 | F5, F6 | — |
| HG4 | Connect by address | ota-candidate | `feat(models): connect a model server by its address` | HG3, D1, D4, D5 | F10 part | — |
| HG5 | Import with a cost, choose for chat, console | ota-candidate | `feat(models): import a server's models with a cost and choose one for chat` | HG4, D6, D7, D14 | F4, F8, F12 | J-HG-5 |
| HG6 | The connect card, the import list, the result card in AI infrastructure | ota-candidate | `feat(admin): connect a model server and import its models in one card` | HG5, D3, D8, D9 | F2, F9 | J-HG-2, J-HG-3, J-HG-4 |
| HG7 | The wizard offers your own server | ota-candidate | `feat(setup): connect your own model server in the first-run wizard` | HG6 | F1 | J-HG-1 |
| HG8 | One honest Ollama address | ota-candidate | `fix(admin): stop offering an Ollama address the server cannot use` | HG6, D2 | F3, F13 status line | J-HG-4 part |
| HG9 | A host name for model servers on the same machine | no-app-impact | `fix(deploy): let the app reach a model server on the same host` | D13 | F10 part | J-HG-4 part |
| HG10 | CI walks the first run | no-app-impact | `test(e2e): walk the first run on a fresh install against the model stub` | HG1, HG7, D12 | F11 | J-HG-6 |
| HG11 | Docs, here and on docs.synaplan.com | no-app-impact | `docs: connect your own model server` | HG7, HG8 | F3 docs part | — |
| HG12 | Release walk, then archive | no-app-impact | `docs(planning): record the halloween-gui walks and archive the track` | all | — | J-HG-1…6 |

**Order.** HG2 and HG9 are bug fixes with no dependency and may go
first. Then HG0 → HG1 → HG3 → HG4 → HG5 → HG6 → HG7 → HG8 → HG10 → HG11
→ HG12. HG1 comes early on purpose: every UI step after it is walked on a
real fresh install, not on the fixtures.

**Commit types.** `feat` raises the minor version (AGENTS.md). HG3–HG7
are new behaviour and are `feat`; HG2, HG8 and HG9 correct wrong
behaviour and are `fix`.

---

## 3. Gates

| Step | Local gate |
| ---- | ---------- |
| this plan | none (markdown only) |
| HG0, HG1 | `make -C frontend lint` (the stub is TypeScript under `frontend/tests`), `make test-e2e` (`ollama-integration.spec.ts` still green), `make fresh` and `make fresh-down` run twice in a row |
| HG2 | `make ci-local` |
| HG3, HG4, HG5 | `make ci-local`, `make -C frontend generate-schemas` then `vue-tsc`, `make test-e2e` (the OpenAPI the UI reads changes) |
| HG6, HG7, HG8 | `make ci-local`, `make test-e2e`, `make -C frontend test-e2e-layout` (320 px), the step's journey walked on `make fresh` and recorded in STATUS |
| HG9 | a quickstart boot on Linux and on Docker Desktop with `extra_hosts`, `deploy/scripts/tests/` unchanged and green, the platform files validated where a validator exists |
| HG10 | `make test-e2e-full` plus the new `fresh-install` project locally, twice |
| HG11 | `markdownlint` where the docs repository runs it |
| every step | `node scripts/mobile-impact.mjs --base origin/main --head HEAD`; never `store-required` |

---

## 4. Risks

| # | Risk | Handling |
| - | ---- | -------- |
| R1 | Ollama's `/v1` differs from its native API (reasoning switch, structured output, keep-alive, embeddings, image input) | HG0 measures each on 0.32.1 and 0.40.12 and records the matrix in STATUS; D1 is revisited if a row fails that native Ollama passes. |
| R2 | Small local models sort and plan badly; the routing prompts are tuned for `gpt-oss-120b` (`DefaultModelConfigSeeder`) | HG0 runs `app:sort-eval` with a 3B model bound to SORT and records the score. If routing breaks, D7 is reopened before HG5. The result card never promises more than the server delivers. |
| R3 | A chat model that cannot call tools (its listing has no `tools`) | HG0 measures what a chat turn that needs a tool does with such a model; the result card says it in one sentence (HG5). TOOLS itself is the model for internal helper tasks (feedback, memories, contradiction checks — `ModelConfigService::getToolsModelConfig()`) and simply follows the chat model. |
| R4 | The first answer of a CPU-only server takes a minute (model load) | The test message has a deadline and ends in a sentence either way; nothing spins forever (U8, stability bar). |
| R5 | Detection takes an address an administrator typed | Everything goes through `SsrfGuard` like an endpoint save; suggestions use the fixed list of D5; public-only installations skip them and refuse private addresses with a sentence. |
| R6 | Switching the embedding model on a used installation re-indexes every file | D7 binds VECTORIZE directly only while no vectors exist; otherwise it hands over to the existing switch with its estimate and confirmation. |
| R7 | The Synaplan Demo restores its previous defaults over the new ones | Leave the Demo before binding (the existing `demoLeft` flow on endpoint save). |
| R8 | The acting administrator's personal defaults hide the instance change for that administrator | The result card says so and offers to apply the server's model to their own account too. |
| R9 | `host-gateway` needs Docker Engine 20.10 or newer, and some app platforms reject `extra_hosts` | HG9 states the minimum and leaves the line out of a platform file whose validator rejects it. |
| R10 | Two rows for the same weights (the seeded native `bge-m3` and an imported `bge-m3:latest` on a model server) | Different routes, so both are correct. The duplicate-name label from row 27 sprint 06 ("{name} · {provider} · {endpoint}") already tells them apart. |

---

## 5. Out of scope

- Pulling a model from Synaplan (`ollama pull` from the UI). The empty
  state names the command; a pull button with progress and a disk check
  is a follow-up.
- Several native Ollama servers, or a runtime-editable `OLLAMA_BASE_URL`
  (rejected in D1).
- Image input for native Ollama rows.
- An environment bootstrap for model servers (config pyramid, row 15).
- Helm values in `synaplan-charts` — a follow-up after HG11.
- Budgets for local models (row 23). The cost field feeds statistics and
  budgets; nothing more changes there.
- "Local only" as a job-wide switch (use-case research Q8, case 10).
  This track makes a local-only installation possible in five minutes;
  it does not add the switch that forbids a cloud fallback.
- Native app changes. The wizard reaches the app over the air.

## 6. Done when

1. J-HG-1…J-HG-6 are walked and entered in STATUS (U10), with the
   quickstart image on Docker Desktop and on Linux.
2. A fresh quickstart install plus an Ollama with two models answers its
   first chat message within five minutes of opening `/setup`, with no
   file edit and no restart. The walk is timed.
3. The `@fresh` journey is green in CI as part of **All Checks Passed**.
4. F1–F14 are each closed by a step, or by a STATUS line that says why
   not.
5. Neither this repository nor docs.synaplan.com still advises the
   `OLLAMA_BASE_URL` route that cannot work.
