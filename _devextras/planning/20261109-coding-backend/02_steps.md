# Steps

Plan: [`00_master_plan.md`](./00_master_plan.md).
Findings: [`01_findings.md`](./01_findings.md).
Review: [`03_review.md`](./03_review.md).
Log: [`STATUS.md`](./STATUS.md).

One step is one pull request. Branch off current `main`. The commit
subject is the step's first line. A walk is a dated entry in STATUS.

## How to work a step

Read this once. Every step ends with the same list.

1. Check the decisions the step needs are ticked in `00_master_plan.md` §8.
2. `git checkout main && git pull && git checkout -b feat/<step-slug>`.
3. Do the **Todo** list of the step, top to bottom.
4. Write the tests the step names. Run them while you work.
5. Run `make ci-local`. Unfiltered. Fix red.
6. If the step touches the frontend, a route, or OpenAPI the UI reads:
   run `make test-e2e` too.
7. If you changed an OpenAPI annotation: `make -C frontend generate-schemas`,
   then `docker compose exec -T frontend npm run check:types`.
8. Walk the journey in the browser. Write the walk into STATUS with the date.
9. `git commit`, `git push -u origin feat/<step-slug>`, `gh pr create`
   against `main`. Title = the step subject. Body = what changed, how to
   see it, the journey id.
10. Wait for the Copilot review. Answer every thread. Push fixes to the
    same branch.
11. Wait for **All Checks Passed**. Then say the PR is ready. The owner merges.

Rules that bite:

- Never add a line to `MessagesGateway.php`, `OpenAICompatibleController.php`,
  `OpenAiMessagesTranslator.php`, `UsageStatsService.php`, or
  `AIModelsConfiguration.vue`. They are at their size limit. Move code
  out, then call the new class.
- `MessagesGatewayConfiguration.vue` gains one link. The mode control
  does not go in it.
- A new UI string lands in all five locale files in the same commit:
  `frontend/src/i18n/locales/{en,de,es,fr,tr}/`.
- A new top-level key in `tools.json` is also added to the `tools` list
  in `frontend/src/i18n/namespaces.ts`.
- Never a raw `<button>`. Use `btn-primary px-4 py-2.5 text-sm font-medium`
  or `btn-secondary` with the same padding.
- Every `input` / `select` carries the house field classes
  (`docs/FRONTEND_CONVENTIONS.md`).
- API types come from `frontend/src/generated/api-schemas.ts`. Never
  write an interface for a response by hand.
- No `console.log`, no `any`, no `alert()`.

## CB0 — this plan

`docs(planning): developer backend keeps every model option`

Release class: no-app-impact.

**Todo**

- [x] Write this folder.
- [x] Register row 45 in `ROADMAP.md` and `_devextras/planning/README.md`.
- [x] Review the first draft against the code (`03_review.md` §2).
- [x] Revise the plan: two modes, the Developer backend page, and the
      Copilot findings (`03_review.md` §5 and §6).

**Exit:** the folder is on `main`. §8 is unticked.

## CB1 — store the choice

`feat(api): store the developer backend model choice`

Needs D1, D5, D15. Release class: backend-only.

**Goal.** An account can read and save the two modes. Nothing runs
differently yet. `GET /v1/models` publishes the window and the
long-context price.

**Create**

| File | What |
| ---- | ---- |
| `backend/src/DTO/CodingPin.php` | Readonly facts for one chat row: `modelId`, `catalogKey`, `providerId`, `name`, `service`, `contextWindow`, `maxOutput`, `vision`, `toolUse`, `contextPricing` (nullable array, the keys `contextPricing()` returns). |
| `backend/src/DTO/CodingChoice.php` | `mode` (`editor` or `account`), `source` (`user`, `group`, or null), `invalid`, `optedOut`, `pin` (`?CodingPin`), `groupModel` (`?CodingPin`). |
| `backend/src/Service/Coding/CodingModelFacts.php` | `from(Model): CodingPin`. The only place that reads the window accessors and `ModelCatalog::contextPricing()`. |
| `backend/src/Service/Coding/CodingModelPin.php` | `final readonly`. `forUser`, `saveAccountModel`, `chooseEditor`, `useGroupModel`, `isPinnable`. |
| `backend/src/Controller/DeveloperBackendController.php` | `GET` and `PUT /api/v1/developer-backend`. Session auth. Also returns `models`: active chat rows for this user, each a `CodingPin` plus nothing the picker does not need. |
| `backend/src/Controller/CodingApiController.php` | `GET /v1/coding`. API-key auth. |
| `backend/src/Service/Model/OpenAiModelRowBuilder.php` | The row loop moved out of `listModels()`, plus the new fields. Uses `CodingModelFacts` so the numbers cannot drift. |
| `backend/tests/Unit/Service/Coding/CodingModelPinTest.php` | Service tests. |
| `backend/tests/Unit/Controller/DeveloperBackendControllerTest.php` | Route tests. |

**Touch**

| File | Change |
| ---- | ------ |
| `backend/src/Service/Model/PickerCapabilities.php` | Add `'CODING'` to `ALL`. In `forTag()`: `'CHAT' => ['CHAT', 'SORT', 'ANALYZE', 'CODING']`. |
| `backend/src/Model/ModelCatalog.php` `CAPABILITY_TAGS` | Add `'CODING' => 'chat'`. |
| `backend/src/Service/ModelConfigService.php` `CAPABILITY_TAGS` | The same line. Do not add a method. |
| `backend/src/Service/Iam/Policy/PolicyAllowList.php` | Add `'CODING'` to `DEFAULT_MODEL_SETTINGS`. Without this, `groupValue()` cannot see a group row. |
| `backend/src/Service/Config/LayeredConfigResolver.php` | Public `groupValue(?int $userId, string $group, string $setting): ?string`. Null unless `usesGroupLayer()` is true, then the merged group value. It does not read owner 0. |
| `backend/src/Controller/OpenAICompatibleController.php` `listModels()` | Replace the row loop with one call to `OpenAiModelRowBuilder`. The controller shrinks. |

**Todo**

- [ ] Add `CODING` to the capability lists and to `DEFAULT_MODEL_SETTINGS`.
- [ ] `CodingModelPin::forUser()` follows master plan §3.4, steps 1–5.
      Invalid means: the id loads no row, the row is inactive, the tag
      is not `chat`, or `tool_use` is absent. `invalid: true` keeps
      mode `account`. A group id that fails the same test is invalid
      too, when it is the winning value.
- [ ] `saveAccountModel()`: the row must be active, tag `chat`, and
      `tool_use`, and the allow-list must accept it. Then
      `ModelDefaultsService::save($user, ['CODING' => $modelId], false)`.
      A rejected save is `DefaultModelRejectedException` → 422 with one
      sentence. Return `forUser()`.
- [ ] `chooseEditor()`: if `groupValue()` parses to a model id, `setValue`
      the user row to `editor`. Otherwise `deleteValue` the user row.
      Never send `editor` through `save()` or `putGroupConfig()`.
- [ ] `useGroupModel()`: `deleteValue` the user row. When the group has
      no model, 422. Return `forUser()`, which is then the group model.
- [ ] `isPinnable(?ApiKey $key)`: false for a paired Desktop key and for
      a `freeapi` key. True otherwise, including a null key (the page).
- [ ] `GET /api/v1/developer-backend` returns the choice, `models`, and
      nothing else. `PUT` body is one of:
      `{ "mode": "editor" }`,
      `{ "mode": "account", "model_id": 123 }`,
      `{ "mode": "group" }`.
      The response is the GET shape. 409 is not used: there is no lock.
      422 names the recovery (pick a listed tool-use model, or there is
      no group model to return to).
- [ ] `GET /v1/coding` returns `object`, `mode`, `invalid`, `id` (provider
      id or null), `catalog_key`, `context_window`, `max_output`,
      `vision`, `tool_use`, `context_pricing`, `source`. Editor mode:
      `mode: "editor"`, null model fields, `context_pricing: null`.
      401 without a user.
- [ ] `OpenAiModelRowBuilder` adds `context_window` and `max_output` when
      the row has them, `synaplan:vision` on `capabilities` when
      `hasFeature('vision')`, and `context_pricing` (the catalog array,
      or null). Keep `synaplan:tool_use`.
- [ ] OpenAPI on both routes, with examples for editor mode, a valid pin,
      an invalid pin, and the three PUT bodies. Regenerate Zod schemas.

**Tests**

- No user row and no group row → editor, source null.
- User model id → account, source user. Group catalog key only → account,
  source group. User id wins over a different group id.
- User value `editor` and a group model → editor, `optedOut` true, and
  `groupModel` is still filled so the page can offer the button.
- User value `editor` and no group model → editor, `optedOut` false.
  The leftover token is not deleted on read.
- User value `editor` does not fall through to the group inside
  `forUser()`. A test with a mock that has no `getConfiguredDefaultModel`
  still passes.
- Inactive user id, with a valid group id underneath → `invalid` true,
  mode account, the group id is not the pin.
- `saveAccountModel` of a `pic2text` row, and of a chat row without
  `tool_use` → exception. A chat tool-use row → the pin.
- `chooseEditor` with a group model writes `editor`. Without one, the
  user row is gone.
- `useGroupModel` with no group model → the 422 path.
- `GET /v1/models`: a row with a window has the integers and, for
  `gpt-6-sol`, a non-null `context_pricing`. A row the catalog does not
  price has `context_pricing: null`.

**Exit:** tests green. `make ci-local` green. No UI yet.

## CB2 — enforce the one-model mode only

`feat(api): one coding model runs only when the account chose it`

Needs D2, D3, D4, D11, and CB1. Release class: backend-only.

**Goal.** Editor chooses is bit-for-bit today's resolution. One model
runs the pin, echoes the editor's string, and records both names.

**Create**

| File | What |
| ---- | ---- |
| `backend/src/AI/Messages/MessagesModelSelection.php` | Holds the block that is now `prepare()` lines 185–212, plus the one-model branch. Returns the same array shape `prepare()` already uses, with `pinned` added. |
| `backend/src/AI/OpenAI/OpenAiModelSelection.php` | Holds `resolveModel()` and `resolvedFromEntity()`, plus the one-model branch. The unpinned function keeps the six steps in findings §1, in that order. |
| `backend/src/AI/Messages/CodingResponseModel.php` | Rewrites the editor-facing model. Non-stream JSON, a synthesized `message_start` event, and a raw Anthropic SSE chunk. Every other byte passes through. |
| `backend/src/AI/OpenAI/OpenAiCodingAttribution.php` | Builds the usage options for the facade (`usage_source`, client, requested model, `key_source`) and the allowance-row metadata. The controller calls this instead of assembling those arrays. |
| `backend/tests/Unit/AI/Messages/MessagesGatewayCodingPinTest.php` | Shape of `MessagesGatewayDesktopPinTest.php`. |
| `backend/tests/Unit/AI/Messages/CodingResponseModelTest.php` | The three payload shapes. |
| `backend/tests/Unit/AI/OpenAI/OpenAiModelSelectionTest.php` | Both modes, including the unknown-name fall-through. |

**Touch**

| File | Change |
| ---- | ------ |
| `MessagesGateway.php` `prepare()` | Replace lines 185–212 with one call. When `pinned`, skip `maybeRewriteForVision()` and wrap the outbound emit / JSON with `CodingResponseModel`. Net lines down. |
| `OpenAICompatibleController.php` | Delete `resolveModel()` and `resolvedFromEntity()`. Call `OpenAiModelSelection`. The assistant-alias branch stays in the controller and does not call the pin. Usage arrays go through `OpenAiCodingAttribution`. Net lines down. |
| `backend/src/Service/Usage/AiChatUsageMeter.php` | `claim()` also lifts `usage_client`, `usage_requested_model`, and `usage_key_source` into the context and unsets them so the provider never sees them. `record()` copies them onto the billed row as `client`, `requested_model`, and `key_source`. |
| `backend/src/AI/Messages/ApiSessionClient.php` | Add `OPENCODE`, `PI`, `OTHER`. Read the `User-Agent` for full-access keys too. |
| `backend/src/Service/MessagesGateway/ApiSessionSummaryService.php` | Use the new client label for the session title. |

**Todo**

- [ ] Capture the `User-Agent` of Pi and OpenCode once, on a scratch
      branch: log the header at the top of `prepare()`, point each
      editor at the dev stack, read `docker compose logs backend`,
      write the tokens into `ApiSessionClient` as constants, delete the
      log line. Do not commit the scratch branch. Claude Code's token
      is the substring `claude`.
- [ ] `ApiSessionClient::fromRequest()`: paired Desktop key → `DESKTOP`.
      Otherwise the User-Agent: `claude` → `CLAUDE_CODE`, the OpenCode
      token → `OPENCODE`, the Pi token → `PI`, `synaplan-desktop` →
      `DESKTOP`, else `OTHER`. Labels: "Claude Code", "OpenCode", "Pi",
      "Synaplan Desktop", "API client".
- [ ] Messages selection, editor chooses: the moved block, including
      Desktop omitted model, then `resolve()`. `pinned` false.
- [ ] Messages selection, one model, pinnable, valid pin: do not call
      `resolve()`. `resolveSelected($pin)`, then set `displayModel` and
      `requested` to the editor's string when it is non-empty.
      `providerModelId` stays the pin. Upstream body uses
      `providerModelId`.
- [ ] Messages selection, invalid pin: 404 whose sentence names the
      stored model. `resolve()` is not called.
- [ ] `CodingResponseModel` on the gateway's way out, only when pinned.
      Tests: synthesized `message_start` array, raw passthrough chunk
      that contains a `message_start` data line and a later
      `content_block_delta` that must be unchanged, non-stream JSON
      with top-level `model` and with `message.model`.
- [ ] OpenAI selection, editor chooses: the six steps in findings §1.
      A test with body `not-a-real-model` and a CHAT default returns
      that default. A test with body `claude-sonnet-5-5` matching a
      chat row returns that row and does not call `getDefaultModel()`.
- [ ] OpenAI selection, one model: the pin, `displayModel` and the
      recorded requested string are the editor's body. The alias branch
      in the controller still wins for `assistant:slug`.
- [ ] `OpenAiCodingAttribution`: options carry
      `usage_source = OPENAI_API`, `usage_client`, `usage_requested_model`,
      and `usage_key_source`. `key_source` comes from
      `UserProviderKeyResolver` for that provider; `isLocalHost` is
      `operator`, matching `MessagesGateway::resolveCredential()`. When
      the resolver returns null, omit `key_source`. Do not infer it
      from the cost.
- [ ] The allowance row stays what it is (the facade has the tokens).
      It may also carry `client` and `requested_model`. CB4 does not
      sum it.
- [ ] Messages `recordUsage()` adds `requested_model` and `client`.
      `key_source` is already there.
- [ ] Confirm the size-ratchet diff only lowers the frozen files.
      `UPDATE_SIZE_RATCHET=1` on that test, inside the backend container.

**Tests (J-CB-4)**

- No `CODING` row, Messages body `claude-sonnet-5-5` → that model.
- No `CODING` row, Chat Completions body of an unknown name → the Chat
  default, not 404.
- No `CODING` row, Messages aliases still rewrite before provider id.
- Pin GPT-6 Sol, body `claude-haiku-4-5-20251001` → upstream model
  `gpt-6-sol`; response `model` is the Haiku id, including a raw
  `message_start`; usage `model_id` is the pin; `requested_model` is
  the Haiku id (not the pin id).
- Pin set, inactive row → 404, and the Chat default was not loaded.
- Pin set, paired Desktop key, omitted model → Chat default.
- Pin set, `freeapi` key → not rewritten.
- Pin set, Messages alias that would map the body elsewhere → alias
  not applied.
- Pin set, images, pin has no vision → vision rewrite not called;
  `x-synaplan-vision: passthrough`.
- Chat Completions, pin set, body `assistant:slug` → the assistant's
  model.
- `ApiSessionClient`: each captured User-Agent → its label; unknown →
  "API client".
- `MessageClassifier` is not constructed on either path.

**Walk**

One curl after the local gate is green. Save GPT-6 Sol as the one
model for `demo@synaplan.com`. Send `/v1/messages` with
`"model": "claude-haiku-4-5-20251001"`. Check the response `model` and
the newest billed row:

```bash
docker compose exec -T db mariadb -usynaplan_user -psynaplan_password synaplan \
  -e "SELECT BPROVIDER, BMODEL, JSON_EXTRACT(BMETADATA,'$.requested_model'), JSON_EXTRACT(BMETADATA,'$.source') FROM BUSELOG ORDER BY BID DESC LIMIT 3"
```

Write the result into STATUS. The Haiku id is `requested_model`. The
row that has tokens is the pin's model.

**Exit:** tests green. Frozen files shorter. `make ci-local` green.
STATUS has the curl line.

## CB3 — the Developer backend page

`feat(apps): Developer backend page for coding tools`

Needs D6, D7, D13, and CB1–CB2 on `main`. Release class: ota-candidate.

**Goal.** The developer sees the two modes, the window, and the setup
block that matches the mode. J-CB-1.

**Copy first.** Locale strings before the component. Put the English
source in the PR description.

**Create**

| File | What |
| ---- | ---- |
| `frontend/src/services/api/developerBackendApi.ts` | `getDeveloperBackend()`, `saveDeveloperBackend(body)`. `httpClient` and the generated Zod schemas. |
| `frontend/src/components/config/developerBackend/DeveloperBackendPage.vue` | The three sections. Under 300 lines. Composes the children. |
| `frontend/src/components/config/developerBackend/CodingModeChoice.vue` | The fieldset and the model list or the picker. Under 300 lines. |
| `frontend/src/components/config/developerBackend/CodingSetupBlocks.vue` | One editor at a time, four blocks. Under 300 lines. |
| `frontend/src/utils/codingClientSetup.ts` | Add the block builders. Existing Claude snippet stays for the old page. |
| `frontend/tests/unit/components/config/developerBackend/CodingModeChoice.spec.ts` | Pinia and i18n. |
| `frontend/tests/unit/utils/codingClientSetup.spec.ts` | One test per block, both modes. |
| `frontend/tests/e2e/tests/developer-backend.spec.ts` | `@ci`. Walks J-CB-1. |

**Touch**

| File | Change |
| ---- | ------ |
| `frontend/src/apps/catalog.ts` | New entry `developer-backend`, category `developer`, icon `heroicons:command-line`. `available` matches `claude-code`: admin, or the gateway enabled. Panel is `DeveloperBackendPage.vue`. |
| `frontend/src/i18n/namespaces.ts` | Add `'developerBackend'` to the `tools` list. |
| `frontend/src/i18n/locales/{en,de,es,fr,tr}/tools.json` | `developerBackend.*` and `apps.items.developerBackend` (`name`, `tagline`, `about`, `stop`). |
| `frontend/src/components/config/MessagesGatewayConfiguration.vue` | One link to `/apps/developer-backend`: "Model and editor setup". No picker. |

**Copy (English source)**

| Key | Text |
| --- | ---- |
| `developerBackend.title` | Developer backend |
| `developerBackend.intro` | Point a coding tool at this account. |
| `mode.legend` | How models are chosen |
| `mode.editor` | Editor chooses |
| `mode.editorHint` | Each request runs the model your editor sends. |
| `mode.account` | One model |
| `mode.accountHint` | Every coding tool on this account runs the model you pick here. |
| `mode.accountWhy` | Coding tools call tools, so this list is the chat models that can. |
| `window` | {context} tokens of context. {output} tokens per reply. |
| `windowSmall` | A coding session resends the whole conversation. This window fills up on a medium project. |
| `longContext` | Above {threshold} input tokens the price is {priceIn} in and {priceOut} out per million. |
| `vision` | Reads screenshots. |
| `noVision` | Does not read screenshots. Images are sent anyway and may be refused. |
| `titles` | Chat titles for your sessions use the sorting model, not this one. |
| `groupChose` | Your group chose this model. You can pick another, or let your editor choose. |
| `optedOut` | You let your editor choose. Your group had chosen {model}. |
| `useGroup` | Use your group's model |
| `invalid` | This model is no longer available. Pick another, or let your editor choose. |
| `pasteAgain` | Paste the block again after you change the model. The old file keeps the old window. |
| `claudeSlots` | Set any of these to a model id from the list. They may differ. |
| `claudeNoWindow` | Claude Code will not use this window for {model}. Use the Pi or OpenCode block. |
| `saveError` | The model could not be saved. Pick one of the listed models and try again. |
| `apps.items.developerBackend.name` | Developer backend |
| `apps.items.developerBackend.tagline` | Choose how your coding tools use Synaplan. |

The catalog `name` is an `apps.` key. It must not contain "coding clients".

**Todo**

- [ ] The strings, in all five locales. Placeholders match English.
- [ ] Fieldset, two choices, keyboard reachable. The selected choice is
      a radio. Saving calls `PUT` with `mode: "editor"` or
      `mode: "account"` plus `model_id`. Highlighting a row in editor
      mode does not call `PUT`; it only fills the example.
- [ ] Editor list: every chat row in `models`. One-model picker: those
      with `tool_use`. Window sentence from the selected row.
      `windowSmall` when `context_window < 500000`. `longContext` when
      `context_pricing` is not null. Vision sentence. In one-model mode,
      the titles sentence.
- [ ] Group and opt-out and invalid copy, from `source`, `optedOut`,
      `invalid`, and `groupModel`. **Use your group's model** is
      `PUT { "mode": "group" }`, shown only when `optedOut` is true.
- [ ] Setup blocks. Editor mode: Claude variables are comments.
      One-model mode: all five equal the provider id. Non-Anthropic pin:
      `claudeNoWindow` above that block. Pi and OpenCode as in findings
      §4. Other editors: base URL + `/v1`, the key, and either "the id
      your editor sends" or the pin id.
- [ ] Gateway off, non-admin: the catalog entry is absent. Admin: the
      page opens, says the gateway is off, and still saves.
- [ ] `data-testid`: `coding-mode-editor`, `coding-mode-account`,
      `coding-model-select`, `coding-window`, `coding-use-group`,
      `coding-block-claude`, `coding-block-pi`, `coding-block-opencode`,
      `coding-block-openai`.
- [ ] The E2E spec signs in as `demo@synaplan.com`, walks J-CB-1, and
      asserts the Claude block text before and after the mode change.

**Exit bullets (UX contract §6)**

1. J-CB-1 walked. Light, dark, 320 px. STATUS entry.
2. The choice is on Developer backend. The Claude Code app has the
   link and not the picker. Statistics gets its link in CB4.
3. Switching to editor chooses names what the editors do next. When a
   group model remains, the sentence names that model. Five locales.
4. Editor mode (empty), invalid pin, save error, gateway off.
5. Dark, the glass theme (`.design-v2`), and 320 px. Blocks wrap. No
   sideways scroll.

**Exit:** J-CB-1 in STATUS. `make ci-local` and `make test-e2e` green.

## CB4 — coding statistics

`feat(usage): coding statistics by model, editor, and who paid`

Needs D8, D14, and CB2. Release class: backend-only + ota-candidate.

**Goal.** The account sees what coding cost, by the model that ran,
the editor, and who paid. J-CB-2.

**Create**

| File | What |
| ---- | ---- |
| `backend/src/Service/Usage/CodingUsageStats.php` | `forUser(int $userId): array`. One query per period. |
| `backend/tests/Unit/Service/Usage/CodingUsageStatsTest.php` | Which rows are summed, and the payment-source split. |
| `frontend/src/components/config/developerBackend/CodingUsageCard.vue` | The card. Under 300 lines. |
| `frontend/tests/unit/components/config/developerBackend/CodingUsageCard.spec.ts` | Empty, loaded, own-key, operator zero, error. |

**Touch**

| File | Change |
| ---- | ------ |
| `backend/src/Controller/UsageStatsController.php` (233 lines) | `GET /api/v1/usage/coding`. Full OpenAPI. Under 50 lines in the new method. |
| The usage API module that calls `/api/v1/usage/stats` | `getCodingUsage()`. |
| `DeveloperBackendPage.vue` | Mount the card under the setup blocks. |
| `UsageStatistics.vue` | One `router-link` to `/apps/developer-backend`. Nothing else. |
| `tools.json` | `developerBackend.usage.*` in five locales. |

**Which rows**

Master plan §6. The query filters `BUSERID` and the period, then keeps:

- action `MESSAGES` and `BMETADATA.source` = `MESSAGES_API`
- action `AI_CALL` and `BMETADATA.source` = `OPENAI_API`

and drops a row whose metadata has `facade_metered` true. Demo rows
(action `DEMO`) never match.

Group by the model that ran (`BMODEL`), `BMETADATA.client` (missing →
`api-client`), and `BMETADATA.key_source` (missing → `unknown`).
Return those three keys on every row, plus requests, prompt tokens,
completion tokens, cache read, cache write, cost, and average latency.
Apply the markup the way `getLiveTotals()` does.

Do not group by `requested_model`.

**Todo**

- [ ] A fixture with one facade `AI_CALL` row (tokens 100, source
      `OPENAI_API`, `key_source` `operator`) and one allowance row
      (action `MESSAGES`, source `OPENAI_API`, `facade_metered`, tokens
      0) sums to 100, not 0 and not 200.
- [ ] A `key_source` `user` row and an `operator` row for the same model
      and editor come back as two rows. The card shows the own-key
      sentence only on `user`. An `operator` row with cost 0 does not
      get that sentence.
- [ ] A Messages `MESSAGES_API` row is included. A `DEMO` / `OPENAI_API`
      row is not.
- [ ] Periods `today`, `this_week`, `this_month`, the same bounds as
      `getCostSummary()`.
- [ ] The card: period tabs, one line per group, a totals line. Empty:
      `usage.empty`. Error: `usage.loadError`, and the mode control
      above still works.
- [ ] One link on the Statistics page.

**Copy**

| Key | Text |
| --- | ---- |
| `usage.title` | Coding use |
| `usage.empty` | No coding use yet. |
| `usage.loadError` | The numbers could not be loaded. The choice above is still saved. |
| `usage.ownKey` | Your provider key was charged. Synaplan cost is zero. |
| `usage.open` | Open coding statistics |

**Exit bullets**

1. J-CB-2 walked. Both calls show up after the page reloads. The
   payment-source split is the PHPUnit above, recorded in STATUS.
2. The card is on Developer backend. Statistics has one link.
3. No destructive action. The own-key sentence in five locales, and
   only on `key_source` `user`.
4. Empty, error, gateway off (the page is absent for a non-admin).
5. Dark, glass, 320 px. Rows stack.

**Exit:** J-CB-2 in STATUS. Both gates green.

## CB5 — the group default

`feat(iam): a group can default the coding model`

Needs D9 and CB3. Release class: backend-only + ota-candidate.

**Goal.** A group admin can set the one-model default. A member can
leave it. J-CB-3. The allow-list constant is already in CB1.

**Touch**

| File | Change |
| ---- | ------ |
| `frontend/src/components/people/PoliciesTab.vue` | Add `'CODING'` to `defaultCapabilities` only. Do not add `DEFAULTMODEL.CODING` to `lockableKeys`. |
| `frontend/src/i18n/locales/{en,de,es,fr,tr}/admin.json` | `people.policies.capability.CODING`. English: "Coding". |
| The allow-list test | Already extended in CB1. This step asserts the Policies payload round-trips a catalog key. |

**Todo**

- [ ] The picker offers chat rows with `tool_use` only. Empty deletes
      the group row (`putGroupConfig` already deletes on an empty value).
- [ ] The value stored is a catalog key, which `modelIdFromStored`
      already accepts. It is a `BGROUPCONFIG` row. No code writes
      `BOWNERID` = the group id.
- [ ] There is no lock checkbox. A test renders the policies screen and
      asserts `CODING` has no lock control. Calling `setLocks` for
      `DEFAULTMODEL.CODING` is not part of the UI. Do not seed an
      owner-0 row to make a lock succeed.
- [ ] Member page: source `group` shows `groupChose` and an enabled
      picker. Saving another model writes the user row only.
      Editor chooses writes `editor` and shows `optedOut`.
      **Use your group's model** deletes the user row and the group
      model returns.
- [ ] A model the allow-list rejects still returns the existing 422
      sentence.

**Exit bullets**

1. J-CB-3 walked with two accounts.
2. The member finds the model on Developer backend. The admin finds it
   on the policy, without a lock.
3. The group sentence and the opt-out sentence name the model. Five
   locales.
4. An empty policy is editor chooses for a member with no row. A
   rejected save names the recovery.
5. Dark, glass, 320 px on the policy row and the member page.

**Exit:** J-CB-3 in STATUS. Both gates green.

## CB6 — docs

`docs: developer backend setup for coding tools`

Needs D10 and CB3. Release class: no-app-impact here. The site page is
a pull request in `synaplan-docs`.

**Todo**

- [ ] `docs/ANTHROPIC_COMPATIBLE_API.md`: a section "Developer backend"
      with both modes, `requested_model`, `GET /v1/coding`, and the
      statement that editor chooses keeps today's resolution.
- [ ] `docs/OPENAI_COMPATIBLE_API.md`: the same section, plus
      `context_window`, `max_output`, and `context_pricing` on
      `GET /v1/models`. State that an unknown model name still falls
      through to the Chat default when the account has not chosen one
      model.
- [ ] `synaplan-docs`: one page that walks J-CB-1. Both modes. The four
      blocks in the product's words. One paragraph for other editors.
- [ ] The two PRs name each other. The site page merges after CB3 is
      on `main`.

**Exit:** a reader can copy a block for either mode and it matches the
page.

## Administrative checklist

No commit. The operator does these after the matching step is on
`main` and deployed. Record each in STATUS with the date.

| # | Who | Action | When |
| - | --- | ------ | ---- |
| A1 | Install admin | **Admin → Coding clients**: turn the gateway on. Confirm who pays: the operator key, or each coder's own provider key. | Before anyone points an editor at the host. |
| A2 | Install admin | Do not create an installation `CODING` row. Do not copy the Chat default into `CODING`. Do not lock the setting. | With A1. |
| A3 | Each coder | **Apps → Developer backend**. Leave **Editor chooses**, or pick **One model** and paste the block. Paste again after every change. A non-Claude model in one-model mode: use Pi or OpenCode, not Claude Code, for the window. | After CB3 is deployed. |
| A4 | Group admin | **People → Policies**: set a coding model when the team should start from one. Leave it empty when members should keep choosing. Members can still change it. There is no lock. | After CB5. |
| A5 | Install admin | Open a coder's statistics after a real session. Confirm the model that ran, the editor, and whether the row is `user` or `operator`. | After CB4. |
| A6 | Host operator (web.synaplan.com) | Confirm A1 and A2 in the running config, in `synaplan-platform`'s change window. No secrets in that repo. A hosted team's default is A4, in the product. | When the hosted service offers this. |
| A7 | Docs | Publish the `synaplan-docs` page from CB6. | With the release that contains CB3. |
| A8 | Release owner | Cut the release from green `main`. CB1's `feat` is a minor bump. Release notes: editor chooses is the default and changes nothing; one model is opt-in and the response still carries the name the editor sent. | When CB1–CB3 are on `main`. |

## Not in a step

- A stored map of Claude Code's five slots.
- A model per API key.
- Applying `MODELS.ALLOWED` to editor-chooses requests. That would change current editors.
- A group lock, or an installation default, for `CODING`.
- A local helper that rewrites `models.json` from `GET /v1/coding`.
- A plugin for one IDE.
- The Desktop model picker.
- Turning the gateway on by default.
- Seeding `CODING` to any model.
- Renaming the Claude Code app.
