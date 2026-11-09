# Coding backend — one model per account

Row 45. Plan written 2026-11-09, reviewed the same day
([`03_review.md`](./03_review.md)).

## 0. One page

A person picks **one model for coding**. Claude Code, Pi, OpenCode, and
every other editor that speaks the Anthropic or the OpenAI API then run
that model. The page shows the **context window**. The account page
shows **what coding cost**, by model and by editor.

What exists today:

- `POST /v1/messages` (Anthropic shape) and `POST /v1/chat/completions`
  (OpenAI shape) already skip the chat router. They bill the account.
- They run whatever model name the editor sends.
- They do not tell the editor how large the window is.
- Statistics count the calls. They do not total tokens by model.

What this track adds:

1. A per-account setting `DEFAULTMODEL.CODING`. Empty until chosen.
2. Both gateways run the pinned model when it is set.
3. `GET /v1/models` and `GET /v1/coding` publish the window.
4. The Apps page **Coding** shows the model, the window, and copy-paste
   setup for Claude Code, Pi, OpenCode, and other editors.
5. A coding statistics card: tokens, cache, cost, by model and editor.
6. A group can set and lock the same model.

What stays the same: no new table, no compose change, no new
dependency, the gateway ships off, and an empty pin changes nothing.

Steps are CB0–CB6 in [`02_steps.md`](./02_steps.md). Evidence is in
[`01_findings.md`](./01_findings.md).

## 1. Words

| Word | Meaning here |
| ---- | ------------ |
| **Editor** | A program on the developer's computer that sends chat requests. Claude Code, Pi, OpenCode, Cursor, Continue, Cline, Aider. |
| **Gateway** | Our two API routes for editors. `POST /v1/messages` and `POST /v1/chat/completions`. |
| **Pin** | The one model an account has chosen for coding. Stored as `DEFAULTMODEL.CODING`. |
| **Catalog row** | One entry in `BMODELS`, seeded from `ModelCatalog.php`. One model can have a `chat` row and a `pic2text` row with the same provider id. |
| **Catalog key** | `service:providerId:tag`, for example `anthropic:claude-sonnet-5-5:chat`. Names exactly one row. |
| **Window** | `meta.context_window` of the chat row. Tokens the model can read at once. |
| **Max output** | `meta.max_output` of the chat row. Tokens the model can write in one reply. |
| **Chain** | Where a default is read from, in order: the person's row, the group's row, the installation's row. `LayeredConfigResolver`. |
| **Configured default** | `ModelConfigService::getConfiguredDefaultModel()`. The stored chain value, or `null`. No fallback. |
| **Effective default** | `ModelConfigService::getDefaultModel()`. Same chain, then "best usable model" when nothing is stored. Never used for the pin. |
| **Usage row** | One line in `BUSELOG` per request: model, tokens, cache, cost, latency, and a JSON `BMETADATA` with `source`. |
| **Client** | Which editor sent the request. Read from the `User-Agent` header. |
| **Session** | A chat Synaplan creates per editor session so the history list shows what happened over the API. |

## 2. Why

- A coding agent resends the whole conversation on every turn. A
  128,000-token window fills on a medium repository.
- Claude Code picks its own model names (Opus, Sonnet, Haiku, a small
  fast one). Nothing stops the split.
- Pi assumes 128,000 tokens and 16,384 output when the config file is
  silent. OpenCode reads `limit` from its config.
- The account Chat default never applies to editors. It is used only
  when `model` is omitted. Editors always send a name.
- Admin aliases are one map for the whole installation.
- A full-access key titles every session "Claude Code", also for Pi and
  OpenCode.

## 3. How the model is set

### 3.1 Storage

- One `BCONFIG` row: group `DEFAULTMODEL`, setting `CODING`, value the
  model id (`BMODELS.BID`) of a **chat** row.
- Owner is the person (`BOWNERID = user id`), a group (group policy),
  or the installation (`BOWNERID = 0`).
- No row means no pin. No migration. No seeder row.
- Read with `getConfiguredDefaultModel('CODING', $userId)`. Never with
  `getDefaultModel()`.

### 3.2 Who sets it

| Who | Where | How |
| --- | ----- | --- |
| The person | Apps → **Coding** | Picks one chat row with `tool_use`. Saves through `ModelDefaultsService::save()`. **Clear** deletes their row. |
| A group admin | People → Policies | Sets `DEFAULTMODEL.CODING` for the group. May lock it. |
| The install admin | Not offered | The installation default stays empty. An empty pin is what keeps today's editors working. |

### 3.3 What the gateways do

Pin **empty**: exactly today. Exact `providerId`, then name, then the
admin alias map. A paired Desktop key that omits `model` still gets the
Chat default.

Pin **set**, and the key is a normal API key:

1. Load the pinned chat row.
2. Ignore the `model` string the editor sent. Ignore the alias map.
3. Call the pinned row's provider through the existing translators.
4. Put the editor's string back as `model` in the response. In the
   Anthropic stream that is `message_start`. In the OpenAI stream that
   is every chunk.
5. Meter the pinned row (`model_id`). Store the editor's string as
   `requested_model` in the usage row.
6. Do not swap to the picture-to-text model for images. Images stay on
   the pin.
7. Search, MCP, and `analyze_image` stay on their switches. A tool may
   use its own model. The answer model is the pin.
8. Session titles still use the sorting model. The page says so.
9. Pinned row inactive, or no provider key: one sentence, HTTP 403 or
   404 as today. No fallback to Chat.

Pin **set**, but the key is a paired Desktop key or a `freeapi` key:
not pinned. Today's behaviour.

Pin **set**, and the request names an assistant alias
(`assistant:slug` on Chat Completions): the alias keeps its own model.

## 4. Telling the editor the window

- The page shows: "1,000,000 tokens of context. 128,000 tokens per
  reply." Numbers come from `Model::getContextWindowTokens()` and
  `Model::getMaxOutputTokens()`.
- Under 500,000: one more sentence. "A coding session resends the whole
  conversation. This window fills up on a medium project."
- When `ModelCatalog::contextPricing($providerId)` returns a tier: one
  sentence with the threshold and the two prices.
- `GET /v1/models` adds `context_window`, `max_output`, and
  `synaplan:vision`.
- `GET /v1/coding` returns the pin with both numbers.
- The page writes the numbers into the copy blocks:

| Editor | Block | Where the window lives |
| ------ | ----- | ---------------------- |
| Claude Code | `ANTHROPIC_BASE_URL`, one key variable, five model variables all equal to the pin id | Claude Code does not read our window. A Claude id matches its table. Any other id: the block says so and points at Pi or OpenCode. |
| Pi | `~/.pi/agent/models.json` with `contextWindow` and `maxTokens` filled | The file. |
| OpenCode | `opencode.jsonc` with `limit.context` and `limit.output` | `limit`. |
| Other editors | OpenAI base URL `https://<host>/v1`, the key, the pin id, the two numbers as comments | Their own settings. |

Changing the pin changes the window. The page says: paste the block
again.

## 5. Useful for everyone who codes with Synaplan

- One pin, every editor on the two APIs. No plugin per editor.
- A team shares one model and one window through the group.
- A new teammate opens Coding and sees the group's model and who chose it.
- The account sees requests, tokens, cache, cost, and average latency
  for coding, by model and by editor, for today, this week, this month.
- A bring-your-own provider key shows tokens and a Synaplan cost of zero.
- An installation with no pin keeps working. Choosing a pin is the opt-in.

Not in this track: a local command that rewrites `models.json` from
`GET /v1/coding`; IDE plugins; the Desktop model picker; row 10's
Connect catalog.

## 6. What we do not touch

- The chat router. Coding stays off `MessageClassifier`.
- `MODEL_ALIASES`. They still apply when the pin is empty.
- The gateway master switch, operator-key switch, upstream URL, and the
  vision / search / fetch modes.
- `DesktopOmittedModel` and `GET /v1/models/catalog`.
- `AIModelsConfiguration.vue` (frozen at 1,932 lines).
- `UsageStatistics.vue` beyond one link (793 lines, limit 800).

## 7. Decisions

Ticked by the owner before the step that needs it. The recommendation
is the row text.

- [ ] **D1 (CB1).** `DEFAULTMODEL.CODING` is a new capability on the existing chain. No row means no pin. No migration, no seeder row. Reads use `getConfiguredDefaultModel()` only.
- [ ] **D2 (CB2).** A set pin is the only model on `/v1/messages` and `/v1/chat/completions` for that account's normal API keys. Paired Desktop keys and `freeapi` keys are not pinned. An empty pin leaves today's resolution in place.
- [ ] **D3 (CB2).** The response echoes the string the editor sent. The usage row stores the pin as `model` and the string as `requested_model`.
- [ ] **D4 (CB2).** A pinned turn is never rewritten onto the picture-to-text model. Tools may use their own model. Session titles keep the sorting model.
- [ ] **D5 (CB1).** `GET /v1/models` adds `context_window`, `max_output`, and `synaplan:vision`. `GET /v1/coding` returns the pin. `/api/v1/coding/model` (GET, PUT, DELETE) serves the page. All additive.
- [ ] **D6 (CB3).** The Apps card label becomes **Coding**. The catalog id stays `claude-code`. Labels never say "coding clients" (wording guard).
- [ ] **D7 (CB3).** For a non-Anthropic pin, the Claude Code block says Claude Code will not honor this window and points at the Pi and OpenCode blocks.
- [ ] **D8 (CB4).** The client is read from the `User-Agent` for full-access keys. Known: Claude Code, Pi, OpenCode, Synaplan Desktop. Unknown: "API client". Session titles follow.
- [ ] **D9 (CB5).** People → Policies can set and lock `CODING` like Chat.
- [ ] **D10 (CB6).** The two API guides and the docs site change with CB3. The site page is its own pull request in `synaplan-docs`.
- [ ] **D11 (CB2).** An assistant alias on Chat Completions keeps the assistant's model. The pin applies to plain requests only.
- [ ] **D12 (release).** No new dependency, no compose change, no new navigation item. The gateway stays off until an administrator turns it on. web.synaplan.com is confirmed by the admin checklist, not by a commit.

## 8. Journeys

| Id | Journey | Walked in | Step |
| -- | ------- | --------- | ---- |
| J-CB-1 | Open Apps → Coding with no pin. Read one sentence: coding tools keep choosing their own model until you pick one. Pick Claude Sonnet 5.5. Read "1,000,000 tokens of context. 128,000 tokens per reply." Copy the Claude Code block. Click **Clear**. The sentence is back and the blocks are gone. | browser, dev stack | CB3 |
| J-CB-2 | Send one `/v1/messages` call and one `/v1/chat/completions` call on the pin. Open Coding. See today's tokens and cost for that model, split by client. Open Statistics. Follow the link to the same card. | browser + curl | CB4 |
| J-CB-3 | As admin, set the coding model on a group and lock it. As a member, open Coding. See that model and the window, and "Your group chose this model." The picker is disabled. Unlock. The member picks another. **Clear** returns to the group model. | browser, two accounts | CB5 |
| J-CB-4 | No pin: a request for `claude-sonnet-5-5` reaches that model. Pin GPT-6 Sol: a request whose body says `claude-haiku-4-5-20251001` is metered as GPT-6 Sol and the response `model` is still the Haiku id. | PHPUnit, then one curl | CB2 |

J-CB-1, J-CB-2, J-CB-3 carry the five exit bullets of
`docs/UX_CONTRACT.md` §6 in [`02_steps.md`](./02_steps.md). J-CB-4 is
the lock that routing stayed off.

## 9. Step table

| Step | Subject | Release class | Needs |
| ---- | ------- | ------------- | ----- |
| CB0 | `docs(planning): one coding model per account` | no-app-impact | — |
| CB1 | `feat(api): store one coding model per account` | backend-only | D1, D5 |
| CB2 | `feat(api): coding tools run the pinned model` | backend-only | D2, D3, D4, D11, CB1 |
| CB3 | `feat(apps): Coding shows the model, the window, and the editor setup` | ota-candidate | D6, D7, CB1, CB2 |
| CB4 | `feat(usage): coding statistics by model and by editor` | backend-only + ota-candidate | D8, CB2 |
| CB5 | `feat(iam): a group can set the coding model` | backend-only + ota-candidate | D9, CB3 |
| CB6 | `docs: coding setup for Claude Code, Pi, and OpenCode` | no-app-impact; docs site in `synaplan-docs` | D10, CB3 |

Order: CB0 → CB1 → CB2 → CB3. CB4 after CB2. CB5 after CB3. CB6 with
CB3. One step is one pull request. The owner merges.

## 10. Other rows

| Row | Relationship |
| --- | ------------ |
| 10 Wave 6 integrations | The editor catalog. Not built here. CB6 is the setup page those editors need. |
| 23 Per-user budget | Coding spend already hits the cost budget when the operator key pays. This track shows it. |
| 25 Agent handling | Hermes and omp use these APIs. They benefit from the pin. |
| 33 Halloween GUI | A local model imported at cost 0 can be the pin if it has `tool_use`. |
| 37 Audit and usage reporting | Does not replace the rollup. Can export the same rows later. |
| 15 Config pyramid | `DEFAULTMODEL.CODING` is one more setting on the existing chain. |
