# Steps

Plan: [`00_master_plan.md`](./00_master_plan.md).
Findings: [`01_findings.md`](./01_findings.md).
Review: [`03_review.md`](./03_review.md).
Log: [`STATUS.md`](./STATUS.md).

One step is one pull request. Branch off current `main`. The commit
subject is the step's first line. A walk is a dated entry in STATUS.

## How to work a step

Read this once. Every step ends with the same list.

1. Check the decisions the step needs are ticked in `00_master_plan.md` §7.
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
  `UsageStatsService.php`, or `AIModelsConfiguration.vue`. They are at
  their size limit. Move code out, then call the new class.
- A new UI string lands in all five locale files in the same commit:
  `frontend/src/i18n/locales/{en,de,es,fr,tr}/`.
- Never a raw `<button>`. Use `btn-primary px-4 py-2.5 text-sm font-medium`
  or `btn-secondary` with the same padding.
- Every `input` / `select` carries the house field classes
  (`docs/FRONTEND_CONVENTIONS.md`).
- API types come from `frontend/src/generated/api-schemas.ts`. Never
  write an interface for a response by hand.
- No `console.log`, no `any`, no `alert()`.

## CB0 — this plan

`docs(planning): one coding model per account`

Release class: no-app-impact.

**Todo**

- [x] Write this folder.
- [x] Register row 45 in `ROADMAP.md` and `_devextras/planning/README.md`.
- [x] Review the draft against the code (`03_review.md`).

**Exit:** the folder is on `main`. §7 is unticked.

## CB1 — store one coding model per account

`feat(api): store one coding model per account`

Needs D1, D5. Release class: backend-only.

**Goal.** An account can save, read, and clear one coding model. Two
routes serve it. `GET /v1/models` publishes the window. Nothing runs
differently yet.

**Create**

| File | What |
| ---- | ---- |
| `backend/src/DTO/CodingPin.php` | Readonly DTO: `modelId`, `catalogKey`, `providerId`, `name`, `service`, `contextWindow`, `maxOutput`, `vision`, `toolUse`, `source` (`user`/`group`/`admin`/`null`), `locked`. |
| `backend/src/Service/Coding/CodingModelPin.php` | `final readonly`. `forUser(User): ?CodingPin`. `save(User, int $modelId): CodingPin`. `clear(User): void`. `isPinnable(?ApiKey): bool`. |
| `backend/src/Controller/CodingModelController.php` | `/api/v1/coding/model`: `GET`, `PUT`, `DELETE`. Session auth. |
| `backend/src/Controller/CodingApiController.php` | `GET /v1/coding`. API-key auth. |
| `backend/tests/Unit/Service/Coding/CodingModelPinTest.php` | Service tests. |
| `backend/tests/Unit/Controller/CodingModelControllerTest.php` | Route tests. |

**Touch**

| File | Change |
| ---- | ------ |
| `backend/src/Service/Model/PickerCapabilities.php` | Add `'CODING'` to `ALL`. In `forTag()`: `'CHAT' => ['CHAT', 'SORT', 'ANALYZE', 'CODING']`. |
| `backend/src/Model/ModelCatalog.php` `CAPABILITY_TAGS` | Add `'CODING' => 'chat'`. |
| `backend/src/Service/ModelConfigService.php` `CAPABILITY_TAGS` | Add `'CODING' => 'chat'`. (Add the line to the constant. Do not add a method.) |
| `backend/src/Controller/OpenAICompatibleController.php` `listModels()` | **Do not add lines.** Move the row-building loop into a new `App\Service\Model\OpenAiModelRowBuilder` and call it. The builder adds `context_window`, `max_output`, and `synaplan:vision`. The controller shrinks. |

**Todo**

- [ ] Add `CODING` to the three capability lists above. Run
      `make -C backend test` once. Expect green: the lists are data.
- [ ] Write `CodingPin` DTO with OpenAPI `#[OA\Schema]` properties.
- [ ] Write `CodingModelPin::forUser()`:
      1. `$id = $this->modelConfig->getConfiguredDefaultModel('CODING', $userId)`.
      2. `null` → return `null`.
      3. Load the `Model`. Inactive → `null`.
      4. `source` from `GroupPolicyService::sourceOfStoredModel($userId, 'CODING', $id)`.
      5. `locked` from `LayeredConfigResolver::isLocked('DEFAULTMODEL', 'CODING', $userId)`.
      6. `contextWindow` = `$model->getContextWindowTokens()`, `maxOutput` = `$model->getMaxOutputTokens()`.
      7. `vision` = `$model->hasFeature('vision')`, `toolUse` = `$model->hasFeature('tool_use')`.
      8. `catalogKey` = `GroupPolicyService::catalogKey($service, $providerId, $tag)`.
- [ ] Write `CodingModelPin::save()`:
      1. Load the model. Must be active, tag `chat`, `hasFeature('tool_use')`. Else throw a specific exception → 422 with one sentence.
      2. Call `ModelDefaultsService::save($user, ['CODING' => $modelId], false)`. It checks the lock and the allow-list and throws `DefaultModelRejectedException`.
      3. Return `forUser($user)`.
- [ ] Write `CodingModelPin::clear()`: `ConfigRepository::deleteValue($userId, 'DEFAULTMODEL', 'CODING')`. Then `LayeredConfigResolver::reset()` if the resolver is present.
- [ ] Write `CodingModelPin::isPinnable(?ApiKey $key)`: `false` when `ApiKeyScope::isPairedDesktop($key->getScopes())` or `ApiKeyScope::isFreeApiKey($key->getScopes())`. `true` otherwise. (CB2 uses it.)
- [ ] `CodingModelController`:
      - `GET` → `{ pinned: bool, pin: CodingPin|null }`.
      - `PUT` body `{ model_id: int }` → 200 with the pin. 409 locked (`iam.settingLocked`). 422 not allowed or not a tool-use chat model.
      - `DELETE` → 200 `{ pinned: false }`.
      - Each method under 50 lines. Full OpenAPI annotations with examples.
- [ ] `CodingApiController` `GET /v1/coding`:
      ```json
      {
        "object": "coding",
        "pinned": true,
        "id": "gpt-6-sol",
        "catalog_key": "openai:gpt-6-sol:chat",
        "context_window": 1050000,
        "max_output": 128000,
        "vision": true,
        "tool_use": true,
        "source": "user"
      }
      ```
      `pinned: false` and null numbers when empty. 401 without a user.
- [ ] `OpenAiModelRowBuilder`: move the `foreach ($models …)` body out of `listModels()`. Add `context_window` and `max_output` as integers when the row has them. Add `synaplan:vision` to `capabilities` when `hasFeature('vision')`. Keep `synaplan:tool_use` as is.
- [ ] Update the OpenAPI annotation of `GET /v1/models` for the new fields.
- [ ] `make -C frontend generate-schemas`, then `vue-tsc`.

**Tests**

- `CodingModelPinTest`: empty chain → `null`; stored user row → `source user`; stored group row → `source group`; locked → `locked true`; inactive model → `null`; `save()` of a `pic2text` row → exception; `save()` of a chat row without `tool_use` → exception; `clear()` removes the row.
- `CodingModelControllerTest`: GET empty; PUT then GET; PUT locked → 409; DELETE → empty.
- `/v1/models`: a row with `meta.context_window` has the two integers; a row without them has neither.
- A test proves `getDefaultModel()` is never called by `CodingModelPin` (mock without that method, or assert on the configured getter only).

**Exit:** all tests green. `make ci-local` green. PR open. No UI yet.

## CB2 — coding tools run the pinned model

`feat(api): coding tools run the pinned model`

Needs D2, D3, D4, D11, CB1. Release class: backend-only.

**Goal.** With a pin, both gateways run the pinned model. The response
still names the model the editor asked for. The usage row names both.
Without a pin, nothing changes.

**Create**

| File | What |
| ---- | ---- |
| `backend/src/AI/Messages/MessagesModelSelection.php` | Takes `Request`, `?string $modelString`, `User`. Returns `['resolved' => …, 'pinned' => bool, 'filledOmittedModel' => bool]` or an error. Holds the block that is now in `prepare()` lines 185–212, plus the pin. |
| `backend/src/AI/OpenAI/OpenAiModelSelection.php` | Same for Chat Completions. Holds `resolveModel()` and `resolvedFromEntity()` from the controller, plus the pin. |
| `backend/tests/Unit/AI/Messages/MessagesGatewayCodingPinTest.php` | Copy the shape of `MessagesGatewayDesktopPinTest.php`. |
| `backend/tests/Unit/AI/OpenAI/OpenAiModelSelectionTest.php` | Selection tests. |

**Touch**

| File | Change |
| ---- | ------ |
| `MessagesGateway.php` `prepare()` | Replace lines 185–212 with one call to `MessagesModelSelection`. Skip `maybeRewriteForVision()` when `pinned`. In `recordUsage()`, add `requested_model` and `client` to `$metadata`. Net lines must go down. |
| `OpenAICompatibleController.php` | Delete `resolveModel()` and `resolvedFromEntity()`. Call `OpenAiModelSelection`. Net lines must go down. |
| `backend/src/AI/Messages/ApiSessionClient.php` | Add constants `OPENCODE`, `PI`, `OTHER`. Read the `User-Agent` for full-access keys too. |
| `backend/src/Service/MessagesGateway/ApiSessionSummaryService.php` | Use the new client label for the session title. |

**Todo**

- [ ] Capture the `User-Agent` of each editor once. Method: on a scratch
      branch, add `$this->logger->info('ua', ['ua' => $request->headers->get('User-Agent')])`
      at the top of `MessagesGateway::prepare()`. Point Claude Code, Pi,
      and OpenCode at the dev stack. Read `docker compose logs backend`.
      Write the three strings into `ApiSessionClient` as constants with a
      comment. Delete the log line. Do not commit the scratch branch.
- [ ] `ApiSessionClient::fromRequest()`:
      1. Paired Desktop key → `DESKTOP` (unchanged).
      2. Otherwise match the `User-Agent`: contains `claude` → `CLAUDE_CODE`; contains the OpenCode token → `OPENCODE`; contains the Pi token → `PI`; contains `synaplan-desktop` → `DESKTOP`; else `OTHER`.
      3. `label()`: "Claude Code", "OpenCode", "Pi", "Synaplan Desktop", "API client".
- [ ] `MessagesModelSelection::select()`:
      1. `$pin = $codingModelPin->isPinnable($key) ? $codingModelPin->forUser($user) : null`.
      2. Pin set → `resolved = modelResolver->resolveSelected($pinModel)`, then set `resolved['displayModel']` to the editor's string when it is non-empty. `pinned = true`.
      3. Pin empty → today's block, unchanged (Desktop omitted model, then `resolve()`).
- [ ] In `prepare()`: when `pinned`, do not call `maybeRewriteForVision()`. Set the `x-synaplan-vision` header to `passthrough` when images were forwarded.
- [ ] Alias rewrite: the existing "rewrite `model` to `providerModelId`" block already sends the real id upstream. Confirm it runs for the pin.
- [ ] `recordUsage()`: `'requested_model' => $prepared['resolved']['requested']`, `'client' => $prepared['session_client']`. Two array lines inside a block you are already shortening.
- [ ] `OpenAiModelSelection::select(User, ?ApiKey, ?string $modelString)`:
      1. Assistant alias (`assistant:` prefix) → not here. The controller keeps its alias branch and does not consult the pin (D11).
      2. Pin set and pinnable → the pinned row. `displayModel` = the editor's string when non-empty.
      3. Pin empty → `providerId`, then name, then Chat default when omitted. Same as today.
- [ ] The controller's usage metadata (two places, `source: OPENAI_API`) gains `requested_model` and `client`. Find them with `rg -n "OPENAI_API" backend/src/Controller/OpenAICompatibleController.php`.
- [ ] Check `backend/tests/Architecture/sizeRatchetBaseline.json`: both files shrank. Run `UPDATE_SIZE_RATCHET=1 php bin/phpunit tests/Architecture/SizeRatchetTest.php` inside the backend container and confirm the diff only lowers numbers.

**Tests (J-CB-4 lives here)**

- No pin, body `claude-sonnet-5-5` → that model, `pinned false`.
- Pin GPT-6 Sol, body `claude-haiku-4-5-20251001` → upstream body `model = gpt-6-sol`; response `model = claude-haiku-4-5-20251001`; usage `model_id` = the pin, `requested_model` = the Haiku id.
- Pin set, paired Desktop key, body omitted → Chat default (unchanged).
- Pin set, `freeapi` key → not pinned.
- Pin set, `MODEL_ALIASES` maps the body name elsewhere → alias ignored.
- Pin set, inactive pin row → 404 with the model name in the sentence.
- Pin set, images, pin has no vision → `maybeRewriteForVision` not called; `x-synaplan-vision: passthrough`.
- Chat Completions: pin set, body `assistant:slug` → the assistant's model, not the pin.
- Chat Completions: pin set, body `gpt-4o-mini` → the pin; response `model = gpt-4o-mini`.
- `ApiSessionClient`: each captured `User-Agent` → its label; unknown → "API client"; paired Desktop key with any agent → "Synaplan Desktop".
- `MessageClassifier` is not constructed on either path (assert the service is not injected).

**Walk**

One curl on the dev stack after the PR is green locally. Pin GPT-6 Sol
for `demo@synaplan.com`. Send a `/v1/messages` body with
`"model": "claude-haiku-4-5-20251001"`. Check the response `model` and
the newest `BUSELOG` row:

```bash
docker compose exec -T db mariadb -usynaplan_user -psynaplan_password synaplan \
  -e "SELECT BMODEL, JSON_EXTRACT(BMETADATA,'$.requested_model') FROM BUSELOG ORDER BY BID DESC LIMIT 1"
```

Write the result into STATUS.

**Exit:** tests green. Both frozen files shorter. `make ci-local` green.
STATUS has the curl line.

## CB3 — the Coding page

`feat(apps): Coding shows the model, the window, and the editor setup`

Needs D6, D7, CB1, CB2 on `main`. Release class: ota-candidate.

**Goal.** A person picks the model, reads the window, copies a block.

**User-flow:** J-CB-1 on Apps → Coding (catalog id `claude-code`).

**Copy first.** Write the locale strings before the component. Review
them in the PR description. Then build.

**Create**

| File | What |
| ---- | ---- |
| `frontend/src/services/api/codingApi.ts` | `getCodingModel()`, `saveCodingModel(modelId)`, `clearCodingModel()`. Uses `httpClient` and the generated Zod schemas. |
| `frontend/src/components/config/coding/CodingModelCard.vue` | Picker, window sentence, warnings, **Clear**. Under 300 lines. |
| `frontend/src/components/config/coding/CodingSetupBlocks.vue` | Four copy blocks. Under 300 lines. |
| `frontend/tests/unit/components/config/coding/CodingModelCard.spec.ts` | Component test with Pinia and i18n. |
| `frontend/tests/e2e/tests/coding-model.spec.ts` | Tagged `@ci`. Walks J-CB-1. |

**Touch**

| File | Change |
| ---- | ------ |
| `frontend/src/components/config/MessagesGatewayConfiguration.vue` | Mount the two new components. Stay under 300 lines. |
| `frontend/src/utils/codingClientSetup.ts` | Add `claudeCodeBlock(base, pinId)`, `piModelsJson(base, pin)`, `openCodeConfig(base, pin)`, `openAiCompatibleBlock(base, pin)`. Pure functions. |
| `frontend/tests/unit/utils/codingClientSetup.spec.ts` | One test per block. |
| `frontend/src/i18n/locales/{en,de,es,fr,tr}/tools.json` | New keys under `messagesGateway.coding.*`. |
| `frontend/src/i18n/locales/{en,de,es,fr,tr}/core.json` | `aiAgents` → "Coding" (two places). Never "coding clients". |

**Copy (English source)**

| Key | Text |
| --- | ---- |
| `coding.empty` | Coding tools keep choosing their own model until you pick one here. |
| `coding.pick` | Choose the model for coding |
| `coding.window` | {context} tokens of context. {output} tokens per reply. |
| `coding.windowSmall` | A coding session resends the whole conversation. This window fills up on a medium project. |
| `coding.longContext` | Above {threshold} input tokens the price is {priceIn} in and {priceOut} out per million. |
| `coding.vision` | Reads screenshots. |
| `coding.noVision` | Does not read screenshots. Images are sent anyway and may be refused. |
| `coding.titles` | Chat titles for your sessions use the sorting model, not this one. |
| `coding.groupChose` | Your group chose this model. |
| `coding.locked` | Your group locked this model. |
| `coding.clear` | Clear |
| `coding.clearConfirm` | Remove {model}? Coding tools go back to choosing their own model. |
| `coding.pasteAgain` | Paste the block again after you change the model. The old file keeps the old window. |
| `coding.claudeNoWindow` | Claude Code will not use this window for {model}. Use the Pi or OpenCode block. |
| `coding.saveError` | The model could not be saved. Pick one of the listed models and try again. |

**Todo**

- [ ] Write the fifteen keys in all five locales. Placeholders match English.
- [ ] `codingApi.ts` with the three calls.
- [ ] `CodingModelCard.vue`:
      1. Load `getCodingModel()` on mount.
      2. Load the picker list from `/api/v1/config/models` (existing). Filter rows: `tag === 'CHAT'` and `features` includes `tool_use`.
      3. Empty: `coding.empty` + the picker.
      4. Chosen: model name, `coding.window`, then `coding.windowSmall` when `context_window < 500000`, then `coding.longContext` when the pin returns a tier, then `coding.vision` or `coding.noVision`, then `coding.titles`.
      5. Source `group`: `coding.groupChose`. Locked: picker disabled, `coding.locked`.
      6. **Clear**: `useDialog().confirm({ title, message: coding.clearConfirm, danger: true })`, then `clearCodingModel()`, then reload.
      7. Save error: `useNotification().error(t('…coding.saveError'))`.
- [ ] `CodingSetupBlocks.vue`: show only when a pin is in force. Four blocks, each with a **Copy** button (`btn-secondary px-4 py-2.5 text-sm font-medium`). Non-Anthropic pin: `coding.claudeNoWindow` above the Claude Code block.
- [ ] `codingClientSetup.ts` blocks:
      - Claude Code: base URL, one key variable, five model variables = pin id.
      - Pi: JSON with `api` by provider (`anthropic-messages` for Anthropic, else `openai-completions`), `baseUrl` (host, or host + `/v1`), `contextWindow`, `maxTokens`, `cost` from the catalog prices.
      - OpenCode: current schema. `providers.synaplan`, `package` by provider, `settings.baseURL`, `models.account.modelID`, `limit.context`, `limit.output`, `model: "synaplan/account"`.
      - Other editors: base URL + `/v1`, key, pin id, two numbers as comments.
- [ ] Gateway off, non-admin: the Apps card is absent (existing `available()`). Admin: the page opens with the existing "gateway is off" sentence; the picker still saves.
- [ ] `coding-model.spec.ts`: sign in as `demo@synaplan.com`, open the card, pick a model, assert the window text, copy a block, clear, assert the empty sentence. Use `data-testid`s: `coding-model-select`, `coding-window`, `coding-clear`, `coding-block-claude`, `coding-block-pi`, `coding-block-opencode`, `coding-block-openai`.

**Exit bullets (UX contract §6)**

1. J-CB-1 walked in the browser. Light, dark, 320 px. STATUS entry.
2. The pin is on this page. Statistics gets its link in CB4; this page does not promise a chart yet.
3. **Clear** names the model and says what the editors do next. Five locales.
4. Empty state, save error, gateway off (card absent for non-admins).
5. Dark, the glass theme (`.design-v2`), and 320 px. Blocks wrap. No sideways scroll.

**Exit:** J-CB-1 in STATUS. `make ci-local` and `make test-e2e` green.

## CB4 — coding statistics

`feat(usage): coding statistics by model and by editor`

Needs D8, CB2. Release class: backend-only + ota-candidate.

**Goal.** The account sees what coding cost, by model and by editor.

**User-flow:** J-CB-2.

**Create**

| File | What |
| ---- | ---- |
| `backend/src/Service/Usage/CodingUsageStats.php` | `forUser(int $userId): array`. One query per period. |
| `backend/tests/Unit/Service/Usage/CodingUsageStatsTest.php` | Query shape and grouping. |
| `frontend/src/components/config/coding/CodingUsageCard.vue` | The card. Under 300 lines. |
| `frontend/tests/unit/components/config/coding/CodingUsageCard.spec.ts` | Empty, loaded, error. |

**Touch**

| File | Change |
| ---- | ------ |
| `backend/src/Controller/UsageStatsController.php` (233 lines) | Add `GET /api/v1/usage/coding`. Full OpenAPI. |
| `frontend/src/services/api/usageApi.ts` (or the file that calls `/api/v1/usage/stats`) | Add `getCodingUsage()`. |
| `frontend/src/components/config/MessagesGatewayConfiguration.vue` | Mount `CodingUsageCard` under the pin. |
| `frontend/src/components/config/UsageStatistics.vue` (793 lines) | **One** `router-link` to the Coding page. Nothing else. |
| Locales `tools.json` | `coding.usage.*` keys. |

**Query**

Template: `UsageStatsService::getUsageByChannel()`. Always filter by
`BUSERID` and a time bound.

```sql
SELECT
  BMODEL AS model,
  COALESCE(JSON_UNQUOTE(JSON_EXTRACT(BMETADATA, '$.client')), 'api-client') AS client,
  COUNT(*) AS requests,
  SUM(BPROMPT_TOKENS) AS prompt_tokens,
  SUM(BCOMPLETION_TOKENS) AS completion_tokens,
  SUM(BCACHED_TOKENS) AS cache_read,
  SUM(BCACHE_CREATION_TOKENS) AS cache_write,
  SUM(BCOST) AS cost,
  AVG(BLATENCY) AS avg_latency
FROM BUSELOG
WHERE BUSERID = :user_id
  AND BUNIXTIMES >= :since
  AND JSON_UNQUOTE(JSON_EXTRACT(BMETADATA, '$.source')) IN ('MESSAGES_API', 'OPENAI_API')
GROUP BY BMODEL, client
ORDER BY cost DESC
```

Periods: `today`, `this_week`, `this_month`, same start times as
`getCostSummary()`. Apply the markup the way `getLiveTotals()` does, so
the cost matches the budget card.

**Todo**

- [ ] `CodingUsageStats::forUser()` returns `{ today: rows[], this_week: rows[], this_month: rows[] }`. Rows use the column names above. A row with `BMETADATA.zero_cost` shows `cost 0`; the card says "Your provider key was charged."
- [ ] Route `GET /api/v1/usage/coding` in `UsageStatsController`. Under 50 lines. 401 without a user.
- [ ] Regenerate schemas. `getCodingUsage()` in the usage API file.
- [ ] `CodingUsageCard.vue`: period tabs, a row per model × client, totals line. Empty: `coding.usage.empty`. Error: `coding.usage.loadError`, the rest of the page keeps the pin.
- [ ] One link in `UsageStatistics.vue` to `/apps/claude-code` (check the route the Apps page uses) with `coding.usage.openCoding`.
- [ ] Locales in five files.

**Copy**

| Key | Text |
| --- | ---- |
| `coding.usage.title` | Coding use |
| `coding.usage.empty` | No coding use yet. |
| `coding.usage.loadError` | The numbers could not be loaded. The model above is still set. |
| `coding.usage.byo` | Your provider key was charged. Synaplan cost is zero. |
| `coding.usage.openCoding` | Open coding statistics |

**Exit bullets**

1. J-CB-2 walked. One `/v1/messages` call and one `/v1/chat/completions` call appear after the page's own reload.
2. From Coding: the card is under the pin. From Statistics: one link.
3. No destructive action on the card. The zero-cost sentence in five locales.
4. Empty, error, gateway off (card absent with the page).
5. Dark, glass, 320 px. Rows stack; no sideways scroll.

**Exit:** J-CB-2 in STATUS. Both gates green.

## CB5 — the group

`feat(iam): a group can set the coding model`

Needs D9, CB3. Release class: backend-only + ota-candidate.

**User-flow:** J-CB-3.

**Touch**

| File | Change |
| ---- | ------ |
| `backend/src/Service/Iam/Policy/PolicyAllowList.php` | Add `'CODING'` to `DEFAULT_MODEL_SETTINGS`. |
| `frontend/src/components/people/PoliciesTab.vue` line 234 | Add `'CODING'` to `defaultCapabilities`. Add its label in the policies locale file. |
| Tests for the policy list | Extend the existing allow-list test with `CODING`. |

**Todo**

- [ ] Add `CODING` in both lists.
- [ ] The Policies picker for `CODING` offers chat rows with `tool_use` only. Reuse the filter from CB3.
- [ ] Member view: `GET /api/v1/coding/model` already returns `source` and `locked` (CB1). Confirm the card shows `coding.groupChose` and `coding.locked`.
- [ ] **Clear** deletes only the member's row. Confirm the group model returns.
- [ ] A rejected save shows the existing 422 sentence.

**Exit bullets**

1. J-CB-3 walked with two accounts.
2. The member finds the model on Coding. The admin finds it on the policy.
3. The lock and clear sentences name the model. Five locales.
4. Empty policy = CB3 empty state. Rejected save names the recovery.
5. Dark, glass, 320 px on the policy row and the member page.

**Exit:** J-CB-3 in STATUS. Both gates green.

## CB6 — docs

`docs: coding setup for Claude Code, Pi, and OpenCode`

Needs D10, CB3. Release class: no-app-impact here. The site page is a
pull request in `synaplan-docs`.

**Todo**

- [ ] `docs/ANTHROPIC_COMPATIBLE_API.md`: a section "One model per account" with the pin, `requested_model`, and `GET /v1/coding`. State that an empty pin keeps today's resolution.
- [ ] `docs/OPENAI_COMPATIBLE_API.md`: the same section, plus `context_window` and `max_output` on `GET /v1/models`.
- [ ] `synaplan-docs`: one page that walks J-CB-1 with the four blocks in the product's words. Link the three editors. One paragraph for other editors.
- [ ] The two PRs name each other. The site page merges after CB3 is on `main`.

**Exit:** a reader copies a block from the docs and it matches the page.

## Administrative checklist

No commit. The operator does these after the matching step is on
`main` and deployed. Record each in STATUS with the date.

| # | Who | Action | When |
| - | --- | ------ | ---- |
| A1 | Install admin | **Manage → Developer & devices → Coding clients**: turn the gateway on. Confirm a provider key will pay: allow the operator key, or each coder saves their own (Pro). | Before anyone points an editor at the host. |
| A2 | Install admin | Leave the installation's `CODING` empty. Do not copy the Chat default into it. | With A1. |
| A3 | Each coder | **Apps → Coding**: pick the model. Paste the block for your editor. Paste again after every change. Non-Claude pin: use Pi or OpenCode, not Claude Code. | After CB3 is deployed. |
| A4 | Group admin | **People → Policies**: set the coding model for the team. Lock it when everyone must share one window. | After CB5. |
| A5 | Install admin | Open a coder's Statistics after a real session. Confirm model, client, tokens, and whether the cost hit the budget (operator key) or stayed zero (own key). | After CB4. |
| A6 | Host operator (web.synaplan.com) | Confirm A1 and A2 in the running config, in `synaplan-platform`'s change window. No secrets in that repo. A hosted team's pin is A4, in the product. | When the hosted service offers this. |
| A7 | Docs | Publish the `synaplan-docs` page from CB6. | With the release that contains CB3. |
| A8 | Release owner | Cut the release from green `main`. CB1's `feat` is a minor bump. Release notes: an empty coding model changes nothing; a set model overrides the name the editor sends. | When CB1–CB3 are on `main`. |

## Not in a step

- A local helper that rewrites `models.json` from `GET /v1/coding`.
- A plugin for one IDE.
- The Desktop model picker.
- Turning the gateway on by default.
- Seeding `CODING` to any model.
