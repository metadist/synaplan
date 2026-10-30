# 02 — Journeys, wireframes and copy

UX contract U1: the journey and the copy are reviewed here, before the
first `.vue` file of a step. English is the source text; the step that
first paints a string ships it in `en`, `de`, `es`, `fr` and `tr`
(`localeParity.spec.ts` gates it). Placeholders keep their names in every
locale.

---

## 1. Words

| Concept | en | de | es | fr | tr |
| ------- | -- | -- | -- | -- | -- |
| A machine at an address that runs models | **model server** | Modellserver | servidor de modelos | serveur de modèles | model sunucusu |
| Where the rows live (existing tab) | **Model catalog** | Modellkatalog | Catálogo de modelos | Catalogue de modèles | Model kataloğu |
| The admin page (existing) | **AI infrastructure** | KI-Infrastruktur | Infraestructura de IA | Infrastructure IA | AI altyapısı |
| Its first tab (existing) | **Providers & keys** | Anbieter & Schlüssel | Proveedores y claves | Fournisseurs et clés | Sağlayıcılar ve anahtarlar |
| A server on the machine Synaplan runs on (`localhost`, `host.docker.internal`) | **On the same machine as Synaplan** | Auf demselben Rechner wie Synaplan | En la misma máquina que Synaplan | Sur la même machine que Synaplan | Synaplan ile aynı makinede |
| The Ollama the `local-ai` profile starts | **Installed with Synaplan** | Mit Synaplan installiert | Instalado con Synaplan | Installé avec Synaplan | Synaplan ile kuruldu |
| A server on the operator's network | **On your network** | In deinem Netzwerk | En tu red | Sur votre réseau | Ağınızda |
| A server with a public address | **On the internet** | Im Internet | En internet | Sur Internet | İnternette |
| A model that runs elsewhere | **Runs on ollama.com** | Läuft auf ollama.com | Se ejecuta en ollama.com | S'exécute sur ollama.com | ollama.com'da çalışır |

"This computer" is never used: the person who reads the page may sit at
another machine than the one Synaplan runs on.

German follows the wizard's register today (*du*: "Wähle einen aus",
"Du kannst …"). The endpoint panel's subtitle is the one *Sie* sentence
in this area ("Binden Sie Ihr eigenes …"); it goes with HG6.

Purpose names are the existing AI models labels
(`config.aiModels.capabilities.*`): **Chat / General AI**, **Embedding /
Vectorization**, **Vision (Image → Text)**. The import list does not
invent shorter ones.

Never in primary copy: endpoint, OpenAICompatible, slug, BTAG, provider
id, SSRF, host-gateway, container (except in the one localhost sentence,
where it is the reason), and the AGENTS.md list. "BMODELS" and "BCONFIG"
appear in one line on the result card for administrators (D8), and in
the operator details.

---

## 2. Wireframes

### W1 — Setup wizard, step 2

```text
┌──────────────────────────────────────────────┐
│ Set up Synaplan                      EN   ☾  │
│ ━━━━━━━━━━━━━━  ━━━━━━━━━━━━━━  ──────────── │
│ Step 2 of 3                                  │
├──────────────────────────────────────────────┤
│ Choose where the AI runs                     │
│ Your own model server keeps everything on    │
│ your network. A cloud provider needs an API  │
│ key. You can add more later.                 │
│                                              │
│ ┌──────────────────────────────────────────┐ │
│ │ ✓ Found a model server                   │ │
│ │   Ollama 0.40 · 2 models                 │ │
│ │   On the same machine as Synaplan        │ │
│ │   [ Use this server ]                    │ │
│ └──────────────────────────────────────────┘ │
│                                              │
│  [ Own model server ]  [ Cloud provider ]    │
│                                              │
│  Own model server → W2, then W3, then W4     │
│  Cloud provider   → today's tiles + key      │
│                                              │
│ ┌ Synaplan Demo — today's card, only while ┐ │
│ │ nothing is connected and it is offered   │ │
│ └──────────────────────────────────────────┘ │
│                                              │
│ [ Continue ]                                 │
└──────────────────────────────────────────────┘
```

- The suggestion card appears only when D5 found something. With no
  suggestion, the segmented choice opens on **Own model server** when a
  bundled Ollama is pinned by the environment, otherwise on **Cloud
  provider** (today's default).
- After a successful import with **Use for chat**, the step shows
  today's ready line ("A provider is connected. The AI can answer now.")
  and the Demo card is not offered.

### W2 — The connect card

```text
Connect your own model server
Ollama, vLLM, LM Studio, llama.cpp, LocalAI — any server that speaks the OpenAI API.

Address
[ localhost:11434                                        ]
API key — only if your server asks for one
[                                                        ]
[ Check ]

─ success ─────────────────────────────────────────────────
✓ Ollama 0.40 answers at host.docker.internal:11434 with 4 models.
  Synaplan runs in a container, where "localhost" is the container itself.
  Your server answered at host.docker.internal:11434 instead, and that is
  the address Synaplan will use.
[ Connect and show models ]

─ failure ─────────────────────────────────────────────────
! Nothing answered at host.docker.internal:11434. If Ollama runs on the
  same machine as Synaplan, it may accept connections only from that
  machine itself: start it with OLLAMA_HOST=0.0.0.0, then check again.
[ Check again ]
▸ What Synaplan tried
    localhost:11434            refused (inside Synaplan's container)
    host.docker.internal:11434 refused
    ollama:11434               name not known
```

- The second success line appears only when the address was changed
  (D4). The address in the field is replaced by the one that answered, so
  the screen shows what will be saved.
- The key field is always there but optional; a 401 / 403 focuses it.

### W3 — The import list

```text
4 models on Ollama · host.docker.internal:11434          [ Filter models… ]

[x] llama3.2:3b     Chat / General AI · tools                    2.0 GB
    Cost  input [ 0     ]  output [ 0     ]  USD per 1M tokens
[x] bge-m3          Embedding / Vectorization                    1.2 GB
    Cost  input [ 0     ]                    USD per 1M tokens
[x] glm-ocr         Chat / General AI · Vision (Image → Text)    2.2 GB
    Cost  input [ 0     ]  output [ 0     ]
[ ] glm-5.2:cloud   Chat / General AI · tools · reasoning
    ⚠ Runs on ollama.com, not on your server. Messages sent to it leave
      your network.
    Cost  input [       ]  output [       ]  unknown

Cost for all selected   input [ 0 ]  output [ 0 ]   [ Apply ]
0 means free. Set a price if statistics and budgets should count what
your hardware costs.

Use for chat                        [ llama3.2:3b  ▾ ]
  Answers chats, sorts messages, writes summaries and memories.
Use for Embedding / Vectorization   [ bge-m3       ▾ ]
  Searches your files and memories.
Use for Vision (Image → Text)       [ glm-ocr      ▾ ]

▸ Edit purposes        ▸ Check what each model can do (uses a little credit)

[ Cancel ]                                        [ Import 3 models ]
```

- In the wizard the list is inline; elsewhere it is today's dialog
  (`modal-overlay` / `modal-panel`, the list scrolls, the buttons do not —
  row 27 sprint 01).
- A model already in the catalog shows **In the catalog** and is not
  selected; it is never repriced.
- At 320 px each row stacks: checkbox and name, chips, cost line.
- **Use for Vision** appears only when a selected model reads images on
  this route. **Check what each model can do** appears only for
  OpenAI-compatible servers that do not list capabilities.
- When files are already indexed, the embedding select is replaced by
  the sentence in §4 and a link to AI infrastructure → Search.

### W4 — The result card

```text
✓ 3 models imported from Ollama · host.docker.internal:11434
  llama3.2:3b answers chat now. bge-m3 searches your files and memories.
  Nothing else changed: glm-5.2:cloud was not imported, and no model
  that was already in the catalog was touched.
  They are in your model catalog. Change their names, costs or purposes
  any time under AI infrastructure › Model catalog.
  Stored as 5 rows in the BMODELS table. The server's address is stored
  encrypted in BCONFIG.

  [ Send a test message ]   [ Open model catalog ]
  ▸ For operators

─ after the test ─────────────────────────────────────────
  llama3.2:3b answered in 3.2 s: "Hello! I am running on your own server."
```

"5 rows": `llama3.2:3b` chat and its memory row, `bge-m3`, `glm-ocr`
chat and vision. The number comes from the response, never from the
client.

### W5 — The server rows on Providers & keys (U7)

```text
Your own model servers                                   [ Connect a server ]

┌───────────────────────────────────────────────────────────────────────┐
│ Ollama · host.docker.internal:11434    On the same machine as Synaplan │
│ Added by mia@example.org in the setup wizard on 31 Oct 2026.          │
│ 3 models in the catalog · 1 new on the server                         │
│ Answers chat for everyone on this installation (llama3.2:3b).         │
│ [ Show models ]  [ Open in model catalog ]  [ Disconnect ]            │
└───────────────────────────────────────────────────────────────────────┘
┌───────────────────────────────────────────────────────────────────────┐
│ Ollama · ollama:11434                           Installed with Synaplan │
│ Set by the server environment (OLLAMA_BASE_URL). Change it there and  │
│ restart.                                                              │
│ 2 models in the catalog                                               │
│ [ Show models ]  [ Open in model catalog ]                            │
└───────────────────────────────────────────────────────────────────────┘
```

The five questions, on the row: **who owns it** (this installation,
added by …), **who else** (everyone here, through chat, the API and
Synaplan Desktop — HG6 verifies the last two before the sentence ships),
**what it touches** (where the server is — the location labels of §1 —
and *Runs on ollama.com* per model), **how to stop it**
(**Disconnect**), **where it came from** (wizard, AI infrastructure,
command line, environment).

Disconnect uses `useDialog().confirm({ danger: true })`:

```text
Disconnect Ollama · host.docker.internal:11434?
Its 3 models are switched off and stay in the model catalog. Chat stops
working until you connect another server or provider.
                                        [ Cancel ]  [ Disconnect ]
```

The second sentence is computed: "Chat moves to {model} on {server}."
when another usable chat model exists.

### W6 — For operators

```text
▸ For operators
  php bin/console app:model-server:add ollama-local http://host.docker.internal:11434
  php bin/console app:model:import ollama-local --model=llama3.2:3b --model=bge-m3:latest \
      --cost-in=0 --cost-out=0 --use-for-chat=llama3.2:3b --use-for-search=bge-m3:latest
  Rows: BMODELS, service OpenAICompatible, BJSON.endpoint = "ollama-local"
  Address: BCONFIG, group openai_compatible, setting endpoint.ollama-local (encrypted)
```

The commands are the exact equivalent of what was just clicked, so an
operator can copy them into a provisioning script.

---

## 3. Journeys

Every UI step names its journey and the five exit bullets of the UX
contract §6. A walk is a dated STATUS entry.

### J-HG-1 — First run on your own Ollama (Mia)

1. A fresh install with no administrator (`make fresh`, or the quickstart
   image). Open `/setup`. Step 1 creates the administrator, as today.
2. Step 2 shows **Found a model server — Ollama 0.40 · 2 models · On
   the same machine as Synaplan**. Mia clicks **Use this server**.
3. The list shows `llama3.2:3b` (Chat / General AI · tools) and `bge-m3`
   (Embedding / Vectorization), both selected, cost 0 / 0. **Use for
   chat** is `llama3.2:3b`, **Use for Embedding / Vectorization** is
   `bge-m3`.
4. **Import 2 models.** The result card says which model answers chat,
   what did not change, where to edit, and the BMODELS line.
5. **Send a test message**: an answer, or the slow-start sentence before
   the deadline.
6. **Continue** → step 3 → **Enter Synaplan** → chat. "Hello" is answered
   by `llama3.2:3b`; the model chip says so. No "no AI provider" screen at
   any point.
7. Later self: Providers & keys shows the server row; the Model catalog
   filtered to the server shows the rows.

Exit (UX contract §6):

1. Walked in the browser on `make fresh` against the stub, and on the
   quickstart image with a real Ollama on Docker Desktop and on Linux.
   Timed: at most five minutes from opening `/setup` to the first answer.
2. The rows are found in ten seconds from the result card (button) and
   from the rail (AI infrastructure → Model catalog), the server from
   Providers & keys, the models in the chat picker.
3. The result card's sentences in five locales, including what did not
   change and the BMODELS line.
4. Empty server, every J-HG-4 failure, and a public-only installation
   (no suggestions, private addresses refused with a sentence).
5. Light, dark, glass, 320 px; the suggestion card, chips, warning and
   cost inputs meet WCAG AA.

### J-HG-2 — A remote server with a key (Jonas)

1. Admin → AI infrastructure → Providers & keys → **Connect a server**.
2. `https://gpu01.internal/v1` → **Check** → "The server wants an API
   key." → key → **Check** → "vLLM answers at gpu01.internal with 1
   model." (`found`, the flavour taken from the listing; a server without
   a recognisable flavour gets `foundGeneric`.)
3. **Connect and show models**: `Qwen/Qwen3-32B`, Chat / General AI from
   the name, the probe offered; cost prefilled 0. Jonas types 0.20 / 0.80
   as the internal GPU price.
4. **Use for chat** → **Import 1 model** → result card.
5. Chat answers from Qwen3 32B; Statistics counts it at 0.20 / 0.80.

Exit: as J-HG-1 bullets 2–5, walked against a vLLM (or LM Studio /
llama.cpp when no GPU is at hand — record which) and against the stub in
`vllm` mode; the key never appears in the response, the log or the page.

### J-HG-3 — Find it, change it, undo it (Mia, a week later)

1. After J-HG-1, Mia pulled `glm-ocr` on her Ollama. Providers & keys:
   "2 models in the catalog · 1 new on the server".
2. **Show models**: `glm-ocr` selected (Chat / General AI · Vision), the
   other two **In the catalog**; **Use for Vision** → `glm-ocr` →
   **Import 1 model** → result card.
3. **Open in model catalog** (filtered to the server) → output cost of
   `llama3.2:3b` to 0.05 → saved → the cost badge in the chat picker
   changes.
4. Switch `glm-ocr` off → it leaves the picker.
5. **Disconnect** → the dialog's consequence sentence → confirm. The rows
   are switched off, not deleted; chat moves on, or the **No AI provider
   configured** screen explains what to do; the catalog rows say "Server
   disconnected".
6. Connect the same address again → `llama3.2:3b` and `bge-m3`, which the
   disconnect switched off, come back on; `glm-ocr`, switched off by Mia,
   stays off.

Exit: walked; the disconnect sentence is kind-specific (a server, not a
model); undo is one click from the row (U3); light, dark, glass, 320 px;
five locales.

### J-HG-4 — When it does not work (Mia)

Each row is walked once, on the stub presets or the real condition, and
ends in the sentence in §4 with its recovery. No row ends in a spinner,
an HTTP code or "Request failed".

| Situation | How it is produced in the walk |
| --------- | ------------------------------ |
| `localhost` inside the container | type `localhost:11434` on `make fresh` |
| Ollama only on 127.0.0.1 (Linux) | real Ollama without `OLLAMA_HOST` |
| Nothing listening / wrong port | `localhost:11999` |
| A web page instead of a model server | Synaplan's own frontend address |
| Synaplan's own API | `localhost:8000` on the dev stack |
| A key is needed | stub preset `unauthorized` |
| No models yet | stub preset `empty` |
| A cloud model | stub preset `four-models` |
| Slow first answer | stub preset `slow` with the test message |
| Public-only installation | `OPENAI_COMPATIBLE_PUBLIC_UPSTREAMS_ONLY=1` on `make fresh` |

### J-HG-5 — No browser (Jonas)

```bash
docker compose exec backend php bin/console app:model-server:add gpu01 https://gpu01.internal/v1 --key-env=GPU01_KEY
docker compose exec backend php bin/console app:model:import gpu01 --all --cost-in=0.2 --cost-out=0.8 \
    --use-for-chat=Qwen/Qwen3-32B --format=json
```

The JSON names created and skipped rows, the bound purposes and
`chatReady`. A second run changes nothing and says so. The UI then shows
the row "Added from the command line on …". `--dry-run` writes nothing.

### J-HG-6 — A developer walks a first run (Dev)

1. `make fresh` prints the address and the walk checklist
   (`_devextras/fresh-install/README.md`).
2. J-HG-1 against the stub, which answers under the name `ollama`.
3. `make fresh-down`; `make fresh` again gives a new first run. The dev
   stack on `:5173` is untouched throughout.
4. CI runs the same journey as `@fresh` on every pull request.

---

## 4. Copy

Keys follow the namespace that owns their siblings
(`frontend/src/i18n/namespaces.ts`): `setup.*` and `adminSetup.*` in
`auth.json`, `aiInfra.*` in `admin.json`. Every `{count}` sentence uses
vue-i18n plural forms ("1 model" / "{count} models"); Turkish keeps one
form after a numeral, as the parity gate allows.

### Wizard (`auth.json`)

| Key | English |
| --- | ------- |
| `setup.provider.title` | Choose where the AI runs |
| `setup.provider.description` | Your own model server keeps everything on your network. A cloud provider needs an API key. You can add more later. |
| `setup.provider.choiceOwn` | Own model server |
| `setup.provider.choiceCloud` | Cloud provider |
| `setup.provider.found` | Found a model server |
| `setup.provider.foundDetail` | {kind} {version} · {count} models |
| `setup.provider.foundWhere` | one of the location labels below (`aiInfra.modelServer.location.*`) |
| `setup.provider.useFound` | Use this server |
| `setup.provider.skipHint` | You can skip this and connect a model server or provider later under AI infrastructure › Providers & keys. Chat will not work until you do. |

`setup.provider.skipHint` replaces today's text, which still names the
old page ("Admin → AI Provider Setup").

### Connect card (`admin.json`)

| Key | English |
| --- | ------- |
| `aiInfra.modelServer.title` | Connect your own model server |
| `aiInfra.modelServer.subtitle` | Ollama, vLLM, LM Studio, llama.cpp, LocalAI — any server that speaks the OpenAI API. |
| `aiInfra.modelServer.addressLabel` | Address |
| `aiInfra.modelServer.addressPlaceholder` | localhost:11434 or https://gpu.example.com/v1 |
| `aiInfra.modelServer.keyLabel` | API key — only if your server asks for one |
| `aiInfra.modelServer.check` | Check |
| `aiInfra.modelServer.checking` | Checking… |
| `aiInfra.modelServer.found` | {kind} {version} answers at {address} with {count} models. |
| `aiInfra.modelServer.foundGeneric` | An OpenAI-compatible server answers at {address} with {count} models. |
| `aiInfra.modelServer.addressChanged` | Synaplan runs in a container, where "localhost" is the container itself. Your server answered at {address} instead, and that is the address Synaplan will use. |
| `aiInfra.modelServer.connect` | Connect and show models |
| `aiInfra.modelServer.tried` | What Synaplan tried |
| `aiInfra.modelServer.listTitle` | Your own model servers |
| `aiInfra.modelServer.connectAnother` | Connect a server |
| `aiInfra.modelServer.location.sameMachine` | On the same machine as Synaplan |
| `aiInfra.modelServer.location.bundled` | Installed with Synaplan |
| `aiInfra.modelServer.location.network` | On your network |
| `aiInfra.modelServer.location.internet` | On the internet |
| `aiInfra.modelServer.addedWizard` | Added by {user} in the setup wizard on {date}. |
| `aiInfra.modelServer.addedAdmin` | Added by {user} on {date}. |
| `aiInfra.modelServer.addedCli` | Added from the command line on {date}. |
| `aiInfra.modelServer.pinnedEnv` | Set by the server environment (OLLAMA_BASE_URL). Change it there and restart. |
| `aiInfra.modelServer.counts` | {inCatalog} models in the catalog · {new} new on the server |
| `aiInfra.modelServer.answersChat` | Answers chat for everyone on this installation ({model}). |
| `aiInfra.modelServer.showModels` | Show models |
| `aiInfra.modelServer.openCatalog` | Open in model catalog |
| `aiInfra.modelServer.disconnect` | Disconnect |
| `aiInfra.modelServer.disconnectTitle` | Disconnect {server}? |
| `aiInfra.modelServer.disconnectBody` | Its {count} models are switched off and stay in the model catalog. |
| `aiInfra.modelServer.disconnectChatMoves` | Chat moves to {model} on {server}. |
| `aiInfra.modelServer.disconnectChatStops` | Chat stops working until you connect another server or provider. |
| `aiInfra.modelServer.disconnectedRow` | Server disconnected |

### Failures (`admin.json`, `aiInfra.modelServer.failure.*`)

One sentence, the recovery in it, no HTTP code (U8).

| Code | English |
| ---- | ------- |
| `refusedHost` | Nothing answered at {address}. If Ollama runs on the same machine as Synaplan, it may accept connections only from that machine itself: start it with OLLAMA_HOST=0.0.0.0, then check again. |
| `refused` | Nothing answered at {address}. Check that the server is running and that the port is right, then check again. |
| `timeout` | {address} did not answer within {seconds} seconds. A firewall may be in the way, or the address is wrong. |
| `unknownName` | The Synaplan server does not know the name {host}. Use the server's IP address, or a name the Synaplan server can resolve. |
| `certificate` | The server's certificate is not trusted. Use http:// on a private network, or give the server a valid certificate. |
| `needsKey` | The server wants an API key. Enter it above and check again. |
| `wrongKey` | The server did not accept this API key. Check the key and try again. |
| `notModelServer` | Something answered at {address}, but it is not a model server. Check the port: Ollama uses 11434, vLLM usually 8000. |
| `isSynaplan` | That is this Synaplan's own address. Enter the address of the server that runs your models. |
| `redirect` | {address} sends Synaplan to another address. Enter the final address instead. |
| `publicOnly` | This installation only connects to servers on the public internet, so {address} cannot be used. |
| `noModels` | Your server has no models yet. Download one on that machine, for example with "ollama pull llama3.2", then check again. |

`noModels` is the empty state (U5): one sentence, one primary button,
**Check again**.

### Import list and result (`admin.json`, `aiInfra.modelImport.*`)

| Key | English |
| --- | ------- |
| `aiInfra.modelImport.listTitle` | {count} models on {server} |
| `aiInfra.modelImport.inCatalog` | In the catalog |
| `aiInfra.modelImport.costLabel` | Cost |
| `aiInfra.modelImport.costInput` | input |
| `aiInfra.modelImport.costOutput` | output |
| `aiInfra.modelImport.costUnit` | USD per 1M tokens |
| `aiInfra.modelImport.costUnknown` | unknown |
| `aiInfra.modelImport.costAll` | Cost for all selected |
| `aiInfra.modelImport.costApply` | Apply |
| `aiInfra.modelImport.costHint` | 0 means free. Set a price if statistics and budgets should count what your hardware costs. |
| `aiInfra.modelImport.runsRemote` | Runs on {host}, not on your server. Messages sent to it leave your network. |
| `aiInfra.modelImport.useForChat` | Use for chat |
| `aiInfra.modelImport.useForChatHint` | Answers chats, sorts messages, writes summaries and memories. |
| `aiInfra.modelImport.useForSearch` | Use for Embedding / Vectorization |
| `aiInfra.modelImport.useForSearchHint` | Searches your files and memories. |
| `aiInfra.modelImport.useForVision` | Use for Vision (Image → Text) |
| `aiInfra.modelImport.useForNone` | Keep the current model |
| `aiInfra.modelImport.searchIndexed` | Your files are already indexed with {model}. Switching re-indexes them — do it under AI infrastructure › Search. |
| `aiInfra.modelImport.editPurposes` | Edit purposes |
| `aiInfra.modelImport.resultTitle` | {count} models imported from {server} |
| `aiInfra.modelImport.resultChat` | {model} answers chat now. |
| `aiInfra.modelImport.resultSearch` | {model} searches your files and memories. |
| `aiInfra.modelImport.resultUnchanged` | Nothing else changed: the models you did not select were not imported, and no model that was already in the catalog was touched. |
| `aiInfra.modelImport.resultWhere` | They are in your model catalog. Change their names, costs or purposes any time under AI infrastructure › Model catalog. |
| `aiInfra.modelImport.resultStorage` | Stored as {rows} rows in the BMODELS table. The server's address is stored encrypted in BCONFIG. |
| `aiInfra.modelImport.resultNoToolCalls` | {model} cannot call tools, so agents and connected tools answer without them. (Final wording after HG0 row 7.) |
| `aiInfra.modelImport.resultPersonal` | Your own choice for chat still uses {model}. |
| `aiInfra.modelImport.resultPersonalApply` | Use {model} for me too |
| `aiInfra.modelImport.operators` | For operators |
| `aiInfra.modelImport.test` | Send a test message |
| `aiInfra.modelImport.testRunning` | Waiting for {model}… |
| `aiInfra.modelImport.testOk` | {model} answered in {seconds} s: "{text}" |
| `aiInfra.modelImport.testSlow` | {model} is still loading. The first answer can take a minute on a computer without a graphics card. You can continue; chat works once it has loaded. |
| `aiInfra.modelImport.testFailed` | {model} did not answer: {reason} Your models are imported; check the server and try again. |

The existing `aiInfra.modelImport.applied` toast stays for the dialog's
re-import of a server that is already connected, extended by the
`resultWhere` sentence.
