# Review of the first draft (2026-11-09)

The first draft was read against the code on the same day. Every line
below names a weak spot, what the code says, and what changed in the
plan. The rewritten plan is `00_master_plan.md`, `01_findings.md`, and
`02_steps.md`.

## 1. Method

1. Read the draft.
2. Open the file the draft names. Check the sentence against it.
3. Where the draft was vague, name the class, route, or test.
4. Where the draft was wrong, write the correction here and in the plan.

## 2. Weak spots and fixes

| # | Weak spot in the draft | What the code says | Fix in the plan |
| - | ---------------------- | ------------------ | --------------- |
| W1 | "A migration inserts an empty owner-0 row." | A missing `DEFAULTMODEL` row is already "no value". `ModelConfigService::getConfiguredDefaultModel()` returns `null` when the chain has no row. | No migration. No seeder row. No row means no pin. D1 rewritten. "Ask first: database schema" is not needed. |
| W2 | "Read the Chat-style default." It did not say which getter. | `getDefaultModel()` falls back to the best usable model by quality. `reportedDefault()` and `GET /api/v1/config/models/defaults` use that fallback too. For a pin, that would silently choose a model nobody picked. | Every read of the pin uses `getConfiguredDefaultModel('CODING', $userId)`. The page reads the pin from a new route, not from the defaults route. |
| W3 | "The two gateway files only receive a call." | The size ratchet freezes `MessagesGateway.php` at 1,025 lines (file is 1,022), `prepare()` at 284 lines, `OpenAICompatibleController.php` at 851 (file is 851), `UsageStatsService.php` at 960 (file is 960). One added line is red CI. | CB2 moves the model-resolution blocks out of both files into new classes. The files shrink. CB4 puts the rollup in a new service and a new route in `UsageStatsController` (233 lines). |
| W4 | "Saving writes `DEFAULTMODEL.CODING`." No path named. | `ModelDefaultsService::save()` drops any capability not in `PickerCapabilities::ALL`. The lock and allow-list checks live there. | CB1 adds `CODING` to `PickerCapabilities::ALL`, to `forTag('CHAT')`, and to both `CAPABILITY_TAGS` maps. Saves go through `ModelDefaultsService::save()`. |
| W5 | "**Clear** removes the user's row." No API. | `ConfigRepository::deleteValue($ownerId, 'DEFAULTMODEL', 'CODING')` exists. | `DELETE /api/v1/coding/model` calls it. |
| W6 | The page was to read `GET /v1/coding`. | `/v1/*` is the API-key firewall. The web app signs in with a session on `/api/*`. | Two doors, one service: `/api/v1/coding/model` for the page, `GET /v1/coding` for editors. |
| W7 | Assistant aliases (`assistant:slug`) on Chat Completions were not mentioned. | `OpenAICompatibleController::dispatchChatCompletion()` resolves an alias to the agent's own chat model. | D11: an assistant alias keeps its model. The pin applies to plain requests only. |
| W8 | "Echo the requested id." Streaming not covered. | The Anthropic stream puts `model` in `message_start`. The OpenAI stream puts `model` in every chunk. The gateway already carries `displayModel` separately from `providerModelId`. | Pinned: `displayModel` = the string the editor sent. Both stream shapes follow. |
| W9 | "Read the User-Agent for Pi and OpenCode." No strings. | Only `synaplan-desktop` is matched today. The Pi and OpenCode strings are not known in this repository. | CB2 has a capture todo with a method. Unknown stays "API client". |
| W10 | "Median latency." | `getOverallStats()` uses `AVG(BLATENCY)`. A median needs a window function. | Average latency. |
| W11 | "`make test-e2e` green." No spec named. | No E2E spec covers the Coding page today. | CB3 adds `frontend/tests/e2e/tests/coding-model.spec.ts`, tagged `@ci`. |
| W12 | "Statistics shows the card." | The frontend ratchet is 800 lines per file. `UsageStatistics.vue` is 793. `AIModelsConfiguration.vue` is 1,932 and frozen. | The card is a new component. `UsageStatistics.vue` gets one link, no card. `AIModelsConfiguration.vue` is not touched. |
| W13 | Locale namespace not named. | The page's strings live in `tools.json` under `messagesGateway`. The Apps label is `aiAgents` in `core.json`, twice. | Named in CB3. |
| W14 | The card name "Coding". | `wordingGuard.spec.ts` bans "coding client(s)" in `nav.`, `pageTitles.`, `apps.`, and `tours.` keys. | Labels use "Coding" or "coding tools". Never "coding clients" in a guarded key. |
| W15 | "The long-context price." No accessor. | `ModelCatalog::contextPricing(string $providerId)` returns threshold and prices, or null. | Named in CB1 and CB3. |
| W16 | "Every key except paired Desktop." | A key with the `freeapi` scope calls the same gateway for the free pool account. A pin on that account would pin every free user. | D2: `freeapi` keys are not pinned either. |
| W17 | Desktop keys and `GET /v1/coding`. | `ApiKeyScope::prefixScopes()` lets a `desktop:messages` key call anything under `/v1/`. | `GET /v1/coding` is readable by any key that can read `/v1/models`. Desktop reads it. The pin is still not applied to Desktop. |
| W18 | Group policy. | `PolicyAllowList::DEFAULT_MODEL_SETTINGS` lists six capabilities. `PoliciesTab.vue` line 234 has its own copy. | CB5 adds `CODING` to both. |
| W19 | Tools and "a second model". | The tool loop may call PIC2TEXT for `analyze_image` and the search model for search. | Rule written down: a tool may use its own model. The answer model is the pin. |
| W20 | Gate order was not spelled out per step. | `AGENTS.md` has the loop. | Every step ends with the same checklist. |

## 3. Open questions for the team

These are not blockers. Answer them before CB2.

- Q1. Does the shipped Claude Code still read `ANTHROPIC_DEFAULT_OPUS_MODEL`, `ANTHROPIC_DEFAULT_SONNET_MODEL`, `ANTHROPIC_DEFAULT_HAIKU_MODEL`, and `ANTHROPIC_SMALL_FAST_MODEL`? Check in the week CB3 is written. The names moved before.
- Q2. What do Pi and OpenCode send as `User-Agent`? Capture both (CB2 todo).
- Q3. Should a pinned turn with images on a model without vision be refused with one sentence, or forwarded and left to the provider's 400? The plan forwards. Say so on the page.
- Q4. Should the Apps card be renamed to **Coding** at once (CB3), or keep "Claude Code & coding tools" until Pi and OpenCode are in the docs (CB6)? The plan renames in CB3.

## 4. What did not change

- No new table. No compose change. No new dependency.
- The gateway ships off. An administrator turns it on.
- An empty pin changes nothing for anyone.
- The chat router stays out of the coding path.
