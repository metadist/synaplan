# Developer backend — how a coding tool picks its model

Row 45. Plan written 2026-11-09, revised the same day.
The first draft made **one model per account** the whole product.
That is one option. The revision is [`03_review.md`](./03_review.md) §5
and §6. Copilot's review of the first draft is answered there.

## 0. One page

A developer points a coding tool at Synaplan and chooses **how the
model is picked**. There are two modes. The default leaves the choice
with the editor, including a different model on every request. **One
model for this account** is the other mode: every plain request on a
normal API key runs the model the account chose.

The choice has its own page, **Apps → Developer backend**
(`/apps/developer-backend`). It is not a block on the Claude Code app,
and it is not the admin switch that turns the gateway on.

What exists today:

- `POST /v1/messages` (Anthropic shape) and `POST /v1/chat/completions`
  (OpenAI shape) already skip the chat router. They bill the account.
- They run the model the editor names, through the resolvers in §5.
  Editors send a name on every call, so the account's Chat default
  almost never applies.
- They do not tell the editor how large the window is.
- Statistics count the calls. They do not total tokens by model, and
  the OpenAI path writes the tokens on a different row from the one
  labelled `OPENAI_API` (findings §6).

What this track adds:

1. The Developer backend page, with the two modes and nothing else
   that picks a model.
2. Storage for the one-model mode. No row means the editor chooses.
3. Both gateways run that model only in the one-model mode.
4. The window, the max output, and a long-context price, on the page
   and on `GET /v1/models`, in both modes.
5. Copy-paste setup that follows the mode, for Claude Code, Pi,
   OpenCode, and any other editor on the two APIs.
6. Coding usage by the model that ran, the editor, and who paid.
7. A group may set one model as the default for members who have not
   chosen. A member may choose otherwise. There is no lock.

What stays the same: no new table, no compose change, no new
dependency, the gateway ships off, and editor-chooses changes nothing
about which model runs.

Steps are CB0–CB6 in [`02_steps.md`](./02_steps.md). Evidence is in
[`01_findings.md`](./01_findings.md).

## 1. Words

| Word | Meaning here |
| ---- | ------------ |
| **Developer backend** | The page where a person sets how coding tools use this account. Apps catalog id `developer-backend`. |
| **Editor** | A program on the developer's computer that sends chat requests. Claude Code, Pi, OpenCode, Cursor, Continue, Cline, Aider, or a client they wrote. |
| **Gateway** | The two API routes those editors call. `POST /v1/messages` and `POST /v1/chat/completions`. |
| **Mode** | One of two. **Editor chooses**, or **one model**. The page shows those words. It never says "pin". |
| **One model** | The account has chosen a single chat model. Both gateways run it for plain requests on a normal API key. |
| **Pin** | The stored model id of the one-model mode. Internal word, used in this plan and in code. Not a label. |
| **Opt-out** | The user row whose value is the token `editor`. It means this person keeps editor-chooses even when a group has a pin. |
| **Catalog row** | One entry in `BMODELS`, seeded from `ModelCatalog.php`. One model can have a `chat` row and a `pic2text` row with the same provider id. |
| **Catalog key** | `service:providerId:tag`, for example `anthropic:claude-sonnet-5-5:chat`. Names exactly one row. |
| **Window** | `meta.context_window` of the chat row. Tokens the model can read at once. |
| **Max output** | `meta.max_output` of the chat row. Tokens the model can write in one reply. |
| **Chain** | Where a default is read from, in order: the person's row, the group's row, the installation's row. `LayeredConfigResolver`. This track reads the person and the group only. It does not read the installation row. |
| **Configured default** | `ModelConfigService::getConfiguredDefaultModel()`. Skips an inactive row and continues. Never used for the pin. |
| **Effective default** | `ModelConfigService::getDefaultModel()`. Same chain, then "best usable model" when nothing is stored. Never used for the pin. |
| **Billed row** | The `BUSELOG` row that still has tokens, model, and cost. |
| **Allowance row** | The extra `BUSELOG` row `FacadeMeteredChat` writes after the facade has already billed the call. Tokens and model are cleared. Not summed. |
| **Payment source** | `key_source` on the billed row: `user` (the person's provider key) or `operator` (the installation's key, including a local model). |
| **Client** | Which editor sent the request. Read from the `User-Agent` header. |

## 2. Why

- A coding agent resends the whole conversation on every turn. The
  window is the fact the developer needs before pointing an editor at
  a model. Both modes show it.
- Claude Code has five model slots (Opus, Sonnet, Haiku, the default,
  a small fast one). Pi and OpenCode keep a list. A developer who
  wants that split must be able to keep it. That is the default mode.
- A developer who wants one window and one bill, for every editor on
  the account, picks the one-model mode. The gateways then ignore the
  name the editor sends.
- The account Chat default does not do either job. Editors send a name.
- Admin aliases are one map for the whole installation. They are not
  this page.
- A full-access key titles every session "Claude Code", also for Pi
  and OpenCode.

## 3. The two modes

### 3.1 Editor chooses

This is the default. It is what happens when the person has no row and
the group has no row.

- The editor sends `model`. Synaplan runs that model, through the
  resolvers in §5, unchanged.
- Two requests may name two models. Claude Code's five slots may name
  five models. Pi and OpenCode may list several, each with its own
  window.
- The page lists the active chat models this account may use, with the
  window, the max output, vision, tool use, and a long-context price
  when the catalog has one. Clicking a row fills the example in the
  setup block. The click is not saved.
- The copy blocks tell the editor the base URL and the key. They do
  not assign one model id. The Claude Code block lists the five
  variables as comments and says each may be a different id from the
  list.

### 3.2 One model for this account

The person, or their group, has stored one chat row that has
`tool_use`.

- Both gateways run that row for a plain request on a normal API key.
  The name in the body is not looked up. The alias map is not applied.
- The response still says the name the editor sent, so the editor does
  not treat the answer as a different model. §5 says which bytes change.
- The usage row stores the model that ran, and the editor's string as
  `requested_model`.
- Images stay on that model. There is no swap to the picture-to-text
  row. A tool Synaplan itself runs (search, MCP, `analyze_image`) may
  still use its own model. Session titles still use the sorting model.
  The page says so.
- The stored row is inactive, missing, not a chat row, or without
  `tool_use`: the gateway answers 404 and names the model. It does not
  run the editor's model, and it does not fall through to another
  layer. The page says the model is no longer available and offers the
  two recoveries: pick another, or switch to editor chooses.
- The copy blocks assign that one id. All five Claude Code variables
  are that id. Pi and OpenCode get one model object and that window.
  Changing the model changes the window. The page says to paste the
  block again.

### 3.3 What is not a third mode

These are real choices. They are not extra controls on the page,
because a third control next to the two modes is how the page becomes
unclear.

| Choice | Where it already lives |
| ------ | ---------------------- |
| A different model per Claude Code slot, or a list in Pi or OpenCode | Editor chooses. The developer sets the ids in the editor. The copy block shows the variables. Synaplan does not store five slots. |
| Which API key | Apps → API keys. The page links there. A key does not have its own model. |
| Whose provider key pays (yours or the installation's) | The key already saved for that provider. Anthropic's own key stays on Apps → Claude Code. The usage card says which one paid. |
| Which models a group allows at all | The existing model allow-list. This track does not add a coding-only list, and it does not start applying the chat allow-list to editor-chooses. That would change today's editors. Saving a one-model choice still goes through `ModelDefaultsService::save()`, which already rejects a model the allow-list excludes. |
| An assistant's own model | `assistant:slug` on Chat Completions. The alias keeps its model in both modes. |
| Synaplan Desktop, and the free-pool key | Not covered by the one-model mode. They keep today's resolution. |

### 3.4 Storage

One setting, `DEFAULTMODEL` / `CODING`. No new table. No migration.
No seeder row. No installation row (`BOWNERID = 0` is never written).

| Layer | Table | Who writes it | Value |
| ----- | ----- | ------------- | ----- |
| Person | `BCONFIG`, `BOWNERID` = the user id | `CodingModelPin` | A model id, through `ModelDefaultsService::save()`. Or the token `editor` (the opt-out), through `ConfigRepository::setValue()`. Or no row. |
| Group | `BGROUPCONFIG`, `BGROUPID` = the group id | `GroupPolicyService::putGroupConfig()` only | A catalog key or a model id, the same shapes the other default models already store. An empty value deletes the row. The token `editor` is not a group value. |
| Installation | not used | nobody in this track | Reading it would pin every account that had not chosen. `forUser()` does not read owner 0. |

`PolicyAllowList` is what lets the resolver see a group row at all
(`usesGroupLayer` returns false for a key that is not on the list).
CB1 adds `CODING` to `DEFAULT_MODEL_SETTINGS`. Until that line exists,
a group row is invisible.

The token `editor` is not a model id. `modelIdFromStored('editor')`
returns null, and `getConfiguredDefaultModel()` would skip it and then
apply the group model. That is why the pin is not read with that
method. `forUser()` reads the raw user value first.

Read order for `forUser()`:

1. User raw value, from `ConfigRepository::getValue($userId, 'DEFAULTMODEL', 'CODING')`.
2. If it is `editor`: mode is editor chooses. Do not let the group
   model win. When a group model exists, source is `user` and the
   choice is opted out, and `groupModel` is filled for the button.
   When no group model exists, the token is leftover: source null,
   not opted out. Do not delete it on read.
3. If it parses to a model id (`GroupPolicyService::modelIdFromStored`):
   that id wins, even when the row is inactive. Do not continue.
4. Otherwise the group value, from a new public
   `LayeredConfigResolver::groupValue($userId, 'DEFAULTMODEL', 'CODING')`.
   It returns null unless group policies are on for this user and
   `CODING` is on the allow-list (the same gate as `chain()`), then
   today's merged group value. It does not read owner 0. Several
   groups merge with the existing rule for default models: the first
   group in the member's list wins.
5. No group value: editor chooses, source null.

A parsed id that is inactive, missing, not tag `chat`, or without
`tool_use` is `invalid: true`. The mode stays one model.

`getDefaultModel()` is not called. `getConfiguredDefaultModel()` is
not called.

### 3.5 Who sets it

| Who | Screen | What they can do |
| --- | ------ | ---------------- |
| The developer | **Apps → Developer backend** | Pick editor chooses, or pick one chat model with `tool_use`. When they have opted out of a group model, **Use your group's model** deletes their row. |
| A group admin | **People → Policies** | Set `DEFAULTMODEL.CODING` for the group, or leave it empty. The picker is chat rows with `tool_use`. There is no lock. |
| The install admin | **Admin → Coding clients** | Turns the gateway on, and the switches that already live there (operator key, vision, search, fetch). Does not pick the coding model. Does not write an installation `CODING` row. |

The Claude Code app stays the connection page: gateway status, the
Anthropic key, the link to API keys. It gains one link to Developer
backend. It does not gain the mode control.

### 3.6 Group, without a lock

A group default applies to a member who has no row of their own. The
member may then pick another model, or switch to editor chooses.

Switching to editor chooses while a group model exists writes the user
token `editor`. Deleting the row instead would bring the group model
back, which is the opposite of what the member just chose. **Use your
group's model** is the action that deletes the row.

There is no lock. The lock that exists today is installation-wide:
`LayeredConfigResolver::isLocked()` reads owner 0, and
`GroupPolicyService::setLocks()` refuses to lock a setting that has no
non-empty installation value (`MissingInstanceDefaultException`).
Locking `CODING` would require an installation model, and that model
would become the only value for every account. That fights the default
mode. CB5 does not show a lock, and it does not call `setLocks()` for
this setting.

## 4. The page

One page, three sections, in this order. A person should be able to
say which mode is on without reading the setup blocks.

1. **How models are chosen.** A fieldset with two choices.
   - Editor chooses. One sentence: each request runs the model the
     editor sends. Then the chat-model list.
   - One model. One sentence: every coding tool on this account runs
     the model picked here. Then one picker, limited to chat rows with
     `tool_use`, and the sentence that says why the list is shorter.
2. **Setup for your editor.** One editor at a time: Claude Code, Pi,
   OpenCode, Other. The block follows the mode (§3.1, §3.2). **Copy**
   uses `btn-secondary`.
3. **Coding use.** The card from CB4. It is on this page in both modes.

The list and the pin both show:

- "{context} tokens of context. {output} tokens per reply."
- Under 500,000 tokens: "A coding session resends the whole
  conversation. This window fills up on a medium project."
- When `context_pricing` is present: the threshold and the two prices.
- Vision, or the sentence that images are sent and may be refused.
- In one-model mode: session titles use the sorting model.

Source `group`: "Your group chose this model. You can pick another, or
let your editor choose." The picker stays enabled.

Source `user` and the value is `editor`, and a group model exists:
"You let your editor choose. Your group had chosen {model}." Plus
**Use your group's model**.

Invalid pin: "This model is no longer available. Pick another, or let
your editor choose."

Gateway off, and the viewer is not an admin: the same rule as the
Claude Code app today. The catalog entry is absent. An admin can open
the page and save a choice before the gateway is on; the page says the
gateway is off and links to the admin screen.

Strings live in `developerBackend` in `tools.json` (a new key in the
`tools` namespace list). The catalog name is
`apps.items.developerBackend`. Both say **Developer backend**. The
route title stays `pageTitles.appDetail`. Guarded `apps.` keys never
contain "coding clients".

## 5. What the gateways do

### 5.1 Editor chooses — the resolvers, unchanged

The selection classes call the code that runs today. They do not
re-order it.

**Messages** (`MessagesModelResolver::resolve`), after the paired
Desktop exception for an omitted model:

1. A catalog key (`service:providerId` or with a tag) that hits an
   active row.
2. The installation alias map, exact then pattern.
3. An active row by provider id, then by name.
4. Strip a trailing `-YYYYMMDD` and retry step 3.
5. Otherwise 404. An empty model is 404, except the paired Desktop
   case, which still gets the Chat default.

**Chat Completions** (`OpenAICompatibleController::resolveModel`):

1. The configured CHAT default, when it is a chat row and its provider
   id or name equals the string.
2. The configured SORT default, on the same test.
3. An active chat row with that provider id or name.
4. Any active row by provider id, then by name.
5. If nothing matched, **including an unknown non-empty name**, the
   Chat default from `getDefaultModel()` (which may fall back to the
   best usable model).
6. Still nothing: 404.

Chat Completions does not read `MODEL_ALIASES`. An empty `model` takes
step 5 for every key, not only Desktop.

A regression test locks both orders with no `CODING` row.

### 5.2 One model — the branch in front of the resolvers

The branch runs only when `forUser()` is one model, the pin is valid,
and `isPinnable($key)` is true. `isPinnable` is false for a paired
Desktop key and for a `freeapi` key.

1. Load the pinned chat row. Do not call `resolve()` or `resolveModel()`.
2. Send the pin's provider id upstream.
3. Set `resolved['providerModelId']` to that id.
4. Set `resolved['displayModel']` and `resolved['requested']` to the
   editor's string when it is non-empty. `resolveSelected()` sets
   `requested` to the pin id; the selection class overwrites both
   fields after that call. An empty editor string keeps the pin id, so
   a body with no `model` still has a name in the response.
5. Rewrite the editor-facing `model` to that display string (§5.3).
6. Meter the pin (`model_id`). Store `requested_model` as the editor's
   string.
7. Skip `maybeRewriteForVision()`. Images are forwarded.
   `x-synaplan-vision: passthrough` when the body had images.
8. Search, MCP, and `analyze_image` stay on their switches.
9. Session titles still use the sorting model.

An assistant alias (`assistant:` on Chat Completions) is resolved
before this branch, in the controller, and does not enter it.

### 5.3 Echoing the editor's model

Setting `displayModel` is enough for Chat Completions. The controller
already passes that string into the non-stream payload and into every
stream chunk.

It is not enough for `/v1/messages`. The translators answer with the
model on the upstream request, which step 2 replaced with the pin id.
`OpenAiMessagesTranslator.php` is on the size ratchet (baseline 1,499)
and is not given a new parameter. Anthropic passthrough relays the
upstream event unchanged, so its `message_start` carries the upstream
id.

CB2 adds `CodingResponseModel`, called on the way out:

- A non-stream JSON body: set `model`, and `message.model` when that
  object exists, to the display string.
- A synthesized stream event: the same for `message_start`.
- A raw Anthropic chunk: rewrite `model` inside a `message_start` data
  line, and pass every other line through.

Tests cover a synthesized event and a raw passthrough chunk, and the
non-stream JSON of each. Chat Completions is tested too, so the
display string is the one the responder received.

## 6. Usage

The card totals coding for today, this week, and this month. A row is
the model that ran, the editor, and the payment source. Requests,
prompt tokens, completion tokens, cache read, cache write, cost, and
average latency.

Which rows count:

| Row | Count it? |
| --- | --------- |
| Messages gateway, action `MESSAGES`, source `MESSAGES_API`, tokens present | Yes. This is the billed row. |
| Chat Completions, action `AI_CALL`, source `OPENAI_API`, tokens present | Yes, after CB2 stamps that source on the facade row. Today the facade row is source `ai_facade`, and the controller's `OPENAI_API` row has had its tokens cleared. |
| The allowance row (`facade_metered`, empty model, empty usage), action `MESSAGES`, source `OPENAI_API` | No. |
| Demo outcomes, action `DEMO`, source `OPENAI_API` | No. |

The card groups by `key_source` as well as by model and editor. A
`user` group says "Your provider key was charged. Synaplan cost is
zero." An `operator` group with cost 0 (a local model, a free catalog
row) does not say that. Cost alone is not the signal.

`requested_model` is stored and is not a column on the card. In the
one-model mode the card shows the model that ran, not every name the
editor happened to send.

## 7. What we do not touch

- The chat router. Coding stays off `MessageClassifier`.
- The two resolvers' order in editor-chooses mode.
- `MODEL_ALIASES`, except that the one-model branch does not consult
  them.
- The gateway master switch, the operator-key switch, the upstream
  URL, and the vision / search / fetch modes. They stay on Admin →
  Coding clients.
- `DesktopOmittedModel` and `GET /v1/models/catalog`.
- `AIModelsConfiguration.vue` (frozen at 1,932 lines).
- `UsageStatistics.vue` beyond one link (793 lines, limit 800).
- `MessagesGateway.php`, `OpenAICompatibleController.php`,
  `OpenAiMessagesTranslator.php`, and `UsageStatsService.php`, except
  to move code out so the file gets shorter.
- The Claude Code app's name, its Anthropic key, and its API-key step.

## 8. Decisions

Ticked by the owner before the step that needs it. The recommendation
is the row text.

- [ ] **D1 (CB1).** No `CODING` row means editor chooses. A stored model id means one model. The user token `editor` is the opt-out. No migration, no seeder, no owner-0 row. `forUser()` reads the raw user value, then `groupValue()`. It does not call `getDefaultModel()` or `getConfiguredDefaultModel()`.
- [ ] **D2 (CB2).** The one-model branch runs only for a valid pin on a normal API key. Editor chooses calls today's resolvers in today's order. Paired Desktop keys and `freeapi` keys are never rewritten. An invalid pin is 404, with no fall-through.
- [ ] **D3 (CB2).** The response `model` is the editor's string, including `/v1/messages` non-stream JSON and `message_start` (passthrough included). `resolved['requested']` is that string. The billed row stores the pin as the model and the string as `requested_model`.
- [ ] **D4 (CB2).** A one-model turn is not moved onto the picture-to-text model. Tools may use their own model. Session titles keep the sorting model. Editor chooses keeps today's vision rewrite.
- [ ] **D5 (CB1).** `GET` and `PUT /api/v1/developer-backend` serve the page. `GET /v1/coding` serves editors. `GET /v1/models` gains `context_window`, `max_output`, `synaplan:vision`, and nullable `context_pricing`. All additive.
- [ ] **D6 (CB3).** New Apps entry `developer-backend`, category `developer`, label **Developer backend**. The Claude Code app keeps its name and links here.
- [ ] **D7 (CB3).** One-model mode on a non-Anthropic model: the Claude Code block says Claude Code will not use this window and points at Pi and OpenCode. Editor-chooses mode: the five Claude variables are comments, and they may differ.
- [ ] **D8 (CB4).** The client is read from the `User-Agent` for full-access keys. Known: Claude Code, Pi, OpenCode, Synaplan Desktop. Unknown: "API client".
- [ ] **D9 (CB5).** A group default is a `BGROUPCONFIG` row via `putGroupConfig()`. There is no lock control and no call to `setLocks()` for `CODING`. A member's row wins, including the opt-out.
- [ ] **D10 (CB6).** The two API guides and the docs site describe both modes. The site page is its own pull request in `synaplan-docs`.
- [ ] **D11 (CB2).** An assistant alias on Chat Completions keeps the assistant's model in both modes.
- [ ] **D12 (release).** No new dependency, no compose change, no new top-level navigation item. One new Apps catalog entry. The gateway stays off until an administrator turns it on. web.synaplan.com is confirmed by the admin checklist.
- [ ] **D13 (CB3).** The page has two modes. It does not store per-slot maps, per-key pins, or a coding-only allow-list.
- [ ] **D14 (CB4).** Statistics sum billed rows only, grouped by model, editor, and `key_source`. The own-key sentence follows `key_source = user`, not a cost of zero.
- [ ] **D15 (CB1).** `context_pricing` is the nullable array from `ModelCatalog::contextPricing()` (threshold and prices, unchanged keys), on the pin, on `GET /v1/coding`, and on `GET /v1/models`.

## 9. Journeys

| Id | Journey | Walked in | Step |
| -- | ------- | --------- | ---- |
| J-CB-1 | Open Apps → Developer backend. The mode is Editor chooses. Read that each request runs the model the editor sends. See a model row with its window. The Claude Code block does not set `ANTHROPIC_MODEL`. Switch to One model, pick Claude Sonnet 5.5, read the window and the max output. The Claude Code block sets all five variables to that id. Switch back to Editor chooses. The assignments are gone. | browser, dev stack | CB3 |
| J-CB-2 | With a model actually running, send one `/v1/messages` call and one `/v1/chat/completions` call. Open Developer backend. See today's tokens and cost for the model that ran, split by editor. An own-key row says the provider key was charged. A zero-cost operator row does not. Open Statistics. Follow the link to the same card. | browser + curl; the payment-source split is PHPUnit | CB4 |
| J-CB-3 | As a group admin, set a coding model and confirm there is no lock. As a member with no row, open Developer backend. See that model, the window, and "Your group chose this model." The picker is enabled. Pick another model. Switch to Editor chooses and read that the group's model is set aside. Click **Use your group's model**. The group model is back. | browser, two accounts | CB5 |
| J-CB-4 | No `CODING` row: a Messages body `claude-sonnet-5-5` reaches that model, and a Chat Completions body of an unknown name still falls through to the Chat default. One model GPT-6 Sol: a body `claude-haiku-4-5-20251001` is metered as GPT-6 Sol, the response `model` is the Haiku id, and `requested_model` is the Haiku id. An inactive pin is 404. | PHPUnit, then one curl | CB2 |

J-CB-1, J-CB-2, and J-CB-3 carry the five exit bullets of
`docs/UX_CONTRACT.md` §6 in [`02_steps.md`](./02_steps.md). J-CB-4 is
the lock that routing stayed off, and that editor chooses did not
change the resolvers.

## 10. Step table

| Step | Subject | Release class | Needs |
| ---- | ------- | ------------- | ----- |
| CB0 | `docs(planning): developer backend keeps every model option` | no-app-impact | — |
| CB1 | `feat(api): store the developer backend model choice` | backend-only | D1, D5, D15 |
| CB2 | `feat(api): one coding model runs only when the account chose it` | backend-only | D2, D3, D4, D11, CB1 |
| CB3 | `feat(apps): Developer backend page for coding tools` | ota-candidate | D6, D7, D13, CB1, CB2 |
| CB4 | `feat(usage): coding statistics by model, editor, and who paid` | backend-only + ota-candidate | D8, D14, CB2 |
| CB5 | `feat(iam): a group can default the coding model` | backend-only + ota-candidate | D9, CB3 |
| CB6 | `docs: developer backend setup for coding tools` | no-app-impact; docs site in `synaplan-docs` | D10, CB3 |

Order: CB0 → CB1 → CB2 → CB3. CB4 after CB2. CB5 after CB3. CB6 with
CB3. One step is one pull request. The owner merges.

## 11. Other rows

| Row | Relationship |
| --- | ------------ |
| 10 Wave 6 integrations | The editor catalog. Not built here. CB6 is the setup those editors need. |
| 23 Per-user budget | Coding spend already hits the cost budget when the operator key pays. This track shows it, split from own-key use. |
| 25 Agent handling | Hermes and omp use these APIs. Editor chooses leaves them their own model. One model covers them when the account picks it. |
| 33 Halloween GUI | A local model imported at cost 0 can be the one model if it has `tool_use`. In the usage card its payment source is `operator`. |
| 37 Audit and usage reporting | Does not replace the rollup. Can export the same rows later. |
| 15 Config pyramid | `DEFAULTMODEL.CODING` is one more setting. The installation layer is deliberately unused. |
