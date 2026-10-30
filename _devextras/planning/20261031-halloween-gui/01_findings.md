# 01 — What a fresh administrator meets today

Read against `main` at `466b96818` and measured on the dev stack on
2026-10-30 (`COMPOSE_PROFILES=compute,local-ai`, bundled Ollama 0.40.12,
a second Ollama 0.32.1 on the Docker host). Every finding names the code
that causes it. The steps in [`03_steps.md`](./03_steps.md) close them;
[`00_master_plan.md`](./00_master_plan.md) §2 maps finding → step.

No private address, key or account appears below. "A LAN Ollama" stands
for the address that was configured on the measuring machine.

---

## F1 — The wizard cannot connect a model server

Step 2 of `/setup` (`SetupProviderStep.vue`) lists the providers returned
by `GET /api/v1/admin/provider-keys`, filtered to `chat`. That list is
`ProviderKeyCatalog`, which by design has no Ollama, no OpenAI-compatible
endpoint and no Piper (its docblock: "providers without a platform key …
deliberately have no entry"). An administrator who only has Ollama can
only press **Continue** ("Chat will not work until you do"), finishes the
wizard, and lands on the full-page **No AI provider configured** screen
(`ProviderSetupBanner.vue`). Its text promises "or connect a local
Ollama, which needs no key at all"; its button opens `/admin/setup`,
where the Ollama address field is the one of F3.

## F2 — Two places, two vocabularies, for one job

- **Ollama:** AI infrastructure → Providers & keys → accordion *Local AI
  (Ollama) — no key needed* (`ModelsAndKeysTab.vue`): an *Ollama server
  URL* field, **Import pulled models**, a "not running" line.
- **vLLM, LM Studio, LiteLLM, a second Ollama:** AI infrastructure →
  **Model catalog** tab → accordion *OpenAI-Compatible Endpoints*
  (`OpenAiCompatibleEndpointsPanel.vue`, inside the 1,932-line
  `AIModelsConfiguration.vue`). A stacked form asks for a slug ("name"),
  a label, a base URL, an auth type and raw capability tags (`chat`,
  `vectorize`, `pic2text`, `text2pic`, `rerank`), then a table row offers
  **Import models**. Its subtitle tells the admin to "add models on it
  with service "OpenAICompatible"".
- Providers & keys links to the second place as *Add your own service*.

The buttons in that panel use hand-rolled classes and `text-green-500` /
`text-red-500`, which AGENTS.md forbids.

## F3 — The Ollama address field cannot take effect on any shipped compose file

`OLLAMA_BASE_URL` is an `.env` field (`AiTab::ollama()`); saving it calls
`EnvSettings::write()`, which edits `backend/.env` and answers
`requiresRestart: true`. After the restart the old address is still used,
because every compose file hands the app process its own value:

| File | What the app process gets |
| ---- | ------------------------- |
| `docker-compose.yml` | `environment: OLLAMA_BASE_URL: ${OLLAMA_BASE_URL:-}` (root `.env` or shell, empty by default; `environment` wins over `env_file: backend/.env`); the `local-ai` wrapper then exports `http://ollama:11434` |
| `deploy/compose.yaml` | the start wrapper exports `http://ollama:11434` with `local-ai`, and an **empty** value without it |
| `deploy/quickstart/compose.yaml` (and `compose.lite.yaml`, CasaOS, Umbrel) | `OLLAMA_BASE_URL: ""` |

Symfony Runtime boots with `dotenv_overload: false` (`backend/composer.json`
has no `extra.runtime`), so a real variable — even an empty one — wins
over `.env`.

Measured on the dev stack: `backend/.env` line 24 names a LAN Ollama; the
app process (PID 1, `frankenphp`) runs with `OLLAMA_BASE_URL=http://ollama:11434`
from the profile; a `docker compose exec backend` shell sees the variable
set and empty. Three answers to one question, and the field in the UI
shows whichever the process has.

The public docs give the same advice that cannot work: docs.synaplan.com
*Local AI → An Ollama you already run*, the FAQ and the quickstart say
"`OLLAMA_BASE_URL=http://host.docker.internal:11434` … restart backend +
worker".

## F4 — Importing does not make chat work

- An import creates rows with `BISDEFAULT=0` and no default binding
  (`ModelImportApplier::newModel()`; docs/ai-infrastructure.md: "nothing
  routes to them until you pick them").
- Chat readiness follows the provider of the **default chat model**
  (`ChatReadinessService::isChatReady()`). A fresh install seeds CHAT,
  TOOLS and ANALYZE to Anthropic, SORT, PLAN, SUMMARIZE and MEM to Groq,
  VECTORIZE to the bundled Ollama `bge-m3` (`DefaultModelConfigSeeder`).
- The start-time repair (`app:provider:apply-defaults --auto`, run by
  `_docker/backend/docker-entrypoint.sh` on every start) moves chat to
  Ollama only when Ollama counts as available, and for a non-Ollama
  default that means the **recommended** `gpt-oss:20b` is pulled
  (`ChatReadinessService::recommendedOllamaChatModelPulled()`,
  `ProviderDefaultsService::PROVIDER_DEFAULTS['ollama']`).

So the beginner with `llama3.2:3b` and `bge-m3` imports both and still
sees **No AI provider configured** until they find the AI models page and
choose the chat model there — and sorting, memory extraction and text
analysis still point at providers without a key. TOOLS and PLAN, which
`ProviderDefaultsService` writes for a cloud provider, are not on that
page's purpose list at all.

## F5 — Tags are guessed from names although the server reports them

Both Ollama versions on the measuring machine send, per model, in one
`GET /api/tags`:

```json
{"name":"gpt-oss:20b","size":13793441244,
 "details":{"family":"gptoss","parameter_size":"20.9B","context_length":131072,"embedding_length":2880},
 "capabilities":["completion","tools","thinking"]}
{"name":"bge-m3:latest","size":1157672605,
 "details":{"family":"bert","parameter_size":"566.70M","context_length":8192,"embedding_length":1024},
 "capabilities":["embedding"]}
{"name":"glm-ocr:latest","size":2219299168,
 "details":{"family":"glmocr","parameter_size":"1.1B","context_length":131072,"embedding_length":1536},
 "capabilities":["vision","completion","tools"]}
```

`/api/show` returns the same `capabilities` on 0.32.1 and 0.40.12.
`OllamaProvider::listPulledModels()` keeps only name, size and family, and
`ModelTagGuesser` guesses from the id: `glm-ocr` becomes `chat` only, its
vision is lost; context length and embedding size are dropped.

## F6 — A model that runs on ollama.com looks like a free local model

The host Ollama lists a cloud model in the same `/api/tags`:

```json
{"name":"glm-5.2:cloud","remote_model":"glm-5.2","remote_host":"https://ollama.com:443","size":338,
 "details":{"family":"glm","parameter_size":"756b","context_length":1000000},
 "capabilities":["completion","tools","thinking"]}
```

The import preview would offer it preselected, priced 0 ("Free"), next to
the local models. Messages sent to it leave the network, and it can cost
money on the Ollama account.

## F7 — The same model twice: `bge-m3` and `bge-m3:latest`

The seeded embedding row is `providerId = 'bge-m3'` (`ModelCatalog`, BID
13 on the dev database). Ollama lists `bge-m3:latest`.
`ModelDiscoveryService::existingProviderIds()` and
`ModelImportApplier::apply()` compare the exact id. Measured with
`POST /api/v1/admin/models/import/endpoint/preview {"source":"ollama"}`:

```
gpt-oss:20b   | guessed: ['chat']      | exists: True
bge-m3:latest | guessed: ['vectorize'] | exists: False   ← same model as BID 13
```

Importing adds a second embedding row for the same weights.
`OllamaProvider::hasModel()` already treats `x` and `x:latest` as one model.

## F8 — There is nowhere to type a cost

The preview shows the price read-only (`ModelImportDialog::priceLabel()`:
"Free" or "Unknown"). Ollama rows are written without a price (entity
default 0/0, so "Free"); an OpenAI-compatible server that publishes no
price — every vLLM, LM Studio and llama.cpp server — is stored as
"price unknown" (`ModelImportService::attachListedPrices()`). A cost can
only be set afterwards, row by row, through the catalog
(`PATCH /api/v1/admin/models/{id}` accepts `priceIn` / `priceOut`).

A 0/0 row is hidden from every picker unless `BSHOWWHENFREE=1`
(`Model::isHiddenBecauseFree()`). The importer sets the flag; a cost of 0
must keep it set, or [#2110](https://github.com/metadist/synaplan/issues/2110)
(imported models not selectable) comes back.

The seeded catalog prices some Ollama rows (`gpt-oss:120b` 0.05 / 0.25,
`qwen3.5:35b` 0.15 / 0.40 on the dev database). A default of 0 applies to
rows an import creates, never to catalog rows (applier contract C6: an
import never reprices an existing row).

## F9 — After an import nobody says what happened

The toast reads "Imported {created} model row(s), skipped {skipped}
already present." and the dialog closes. Nothing says where the rows
went, that nothing answers with them yet, that they can be renamed,
repriced or switched off, or what the server's address is stored as.

## F10 — "localhost" means the container, and the failure is one sentence for everything

- Inside a container `localhost` is the container. `docker-compose.yml`
  maps `host.docker.internal` (`extra_hosts: host-gateway`) on the app
  roles; `deploy/compose.yaml` and `deploy/quickstart/compose.yaml` do
  not, so on Linux a server on the same host has no name.
- On Linux the Ollama service listens on `127.0.0.1` unless
  `OLLAMA_HOST=0.0.0.0` is set, so even the right name is refused.
- Measured: from the dev backend, `host.docker.internal:11434` resolves
  (Docker Desktop on WSL2) and the host Ollama answers.
- Every failure — wrong port, refused, timeout, DNS, TLS, 401, a web page
  instead of a model server — ends in "Ollama server is unreachable" or
  "This endpoint did not answer. Check it under Test, then try again."

## F11 — Developers never see a first run, and nothing tests it

- Fixtures seed accounts, so the wizard never opens on the dev stack
  (docs/CONFIGURATION.md "Dev and test installations never see the
  wizard"). `make -C backend setup-reset` lasts until the next restart;
  `SEED_DEMO_DATA=false` needs `docker compose down -v`, which drops the
  dev data.
- No Playwright spec references `page-setup`; unit specs cover the
  wizard's components one by one.
- The Ollama stub (`frontend/tests/e2e/stub-servers/ollama/`) serves
  `/api/tags`, `/api/chat` and `/api/embed`. It has no `/api/version`,
  `/api/show`, `/v1/models`, `/v1/chat/completions` or `/v1/embeddings`,
  and its `/api/tags` carries no `capabilities`.

## F12 — Operators can click or write SQL

`bin/console list app` has `app:model:seed`, `enable`, `disable`,
`set-default` and `app:provider:apply-defaults`, but nothing that adds a
model server or imports its models. A scripted install cannot reach the
state the import dialog creates.

## F13 — Two routes for Ollama with different powers

| | `OllamaProvider` (native) | `OpenAICompatibleProvider` (any `/v1`) |
| - | ------------------------- | -------------------------------------- |
| Address | once, `%env(OLLAMA_BASE_URL)%` in `services.yaml` | per endpoint, `BCONFIG` group `openai_compatible`, encrypted, at runtime |
| Servers | one | any number |
| Tool calls | no (`ChatProviderInterface`, `EmbeddingProviderInterface` only) | yes (`ToolCallingChatProviderInterface`) |
| Images in | no (no `app.ai.vision` tag) | yes (`VisionProviderInterface`, tagged `app.ai.vision`) |
| Reasoning | native `think` | reads `delta.reasoning`, the field Ollama's `/v1` uses |
| Listing | `/api/tags` (sizes, families, capabilities) | `/models` (ids, published prices) |

Ollama answers `/v1/models` (measured on 0.40.12:
`{"object":"list","data":[{"id":"gpt-oss:20b",…},{"id":"bge-m3:latest",…}]}`).
System status reads the native address only
(`FeatureStatusReporter`: `$_ENV['OLLAMA_BASE_URL']`), so an Ollama added
as an endpoint today is reported as not configured next to a working
local chat.

---

## How it was measured

Read-only calls from the dev backend container; nothing was imported.

```bash
docker compose exec -T backend sh -c 'curl -s http://ollama:11434/api/version'
docker compose exec -T backend sh -c 'curl -s http://ollama:11434/api/tags'
docker compose exec -T backend sh -c 'curl -s http://ollama:11434/v1/models'
docker compose exec -T backend sh -c 'curl -s http://ollama:11434/api/show -d "{\"model\":\"bge-m3:latest\"}"'
docker compose exec -T backend sh -c 'curl -s http://host.docker.internal:11434/api/tags'
# App process vs exec shell (variable names only, no secrets):
docker compose exec -T backend sh -c 'tr "\0" "\n" < /proc/1/environ | grep -E "^(OLLAMA_BASE_URL|COMPOSE_PROFILES)="'
# Import preview as the seeded dev administrator (no rows had an import
# source, so the listing reconciliation it runs touched nothing):
curl -s -c jar -H 'Content-Type: application/json' \
  -d '{"email":"admin@synaplan.com","password":"admin123"}' http://localhost:8000/api/v1/auth/login
curl -s -b jar -H 'Content-Type: application/json' \
  -d '{"source":"ollama","probe":false}' http://localhost:8000/api/v1/admin/models/import/endpoint/preview
```
