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

## 5. Copilot on the first draft (2026-11-09)

The first draft was reviewed on pull request 3038. Each note was
checked against the code and written into the plan. Two lines in §2
are replaced by this section. W5's `DELETE /api/v1/coding/model` is not
the API: the page PUTs a mode, because a delete cannot both opt out of
a group model and restore it. W8's `displayModel` does not change
`/v1/messages`.

| Note | What the code does | Where the plan says it now |
| ---- | ------------------ | -------------------------- |
| Group defaults are not `BCONFIG` rows with a group id as owner. | `GroupConfig` is `BGROUPCONFIG`, keyed by `BGROUPID`. `putGroupConfig()` is the write. `usesGroupLayer()` ignores a key that is not on `PolicyAllowList`. | Master plan §3.4. CB1 adds `CODING` to the allow-list. CB5 writes only through `putGroupConfig()`. |
| A group lock cannot be the installation lock. | `isLocked()` reads owner 0. `setLocks()` throws `MissingInstanceDefaultException` without a non-empty installation value. An installation value would pin every account. | The lock is dropped. §3.6. CB5 shows no lock and does not call `setLocks()`. |
| Unpinned resolution was described in the wrong order. | Messages: catalog key, then alias, then provider id / name, then a dated suffix. Chat Completions: configured CHAT, configured SORT, chat identity, any active row, then `getDefaultModel()` even for an unknown name. Aliases are Messages-only. | Findings §1. §5.1. CB2's editor-chooses path calls those functions unchanged, with a regression test for the unknown-name fall-through. |
| The pin payload had no long-context price. | `ModelCatalog::contextPricing()` has it. `/api/v1/config/models` does not. | D15. `CodingModelFacts` copies the array. It is on the pin, on `GET /v1/coding`, and on `GET /v1/models`. |
| An inactive pin was treated as unset. | `getConfiguredDefaultModel()` skips an inactive row and continues, so a dead user pin becomes the group pin or editor-chooses. | `forUser()` does not call that getter. An inactive winning id is `invalid`, and the gateway returns 404. CB1 test. |
| Clearing the user row always returned `pinned: false`. | A group value is still in the chain. The old confirm text promised that editors would choose again. | There is no Clear that means both things. Editor-chooses while a group model exists writes the user token `editor`. **Use your group's model** deletes the row and the GET is resolved again. The opt-out sentence names the group model. |
| `displayModel` does not echo on `/v1/messages`. | Translators copy the upstream request model. Passthrough emits the upstream `message_start` unchanged. `OpenAiMessagesTranslator.php` is on the size ratchet. | `CodingResponseModel` rewrites non-stream JSON, a synthesized `message_start`, and a raw passthrough chunk. Tests for all three. Chat Completions already prints `displayModel`. |
| `requested` stayed the pin id. | `resolveSelected()` sets `requested` from the row. | The selection class overwrites `requested` and `displayModel` with the editor's string after that call. The Haiku / GPT-6 test asserts `requested_model` is the Haiku id. |
| The usage query summed the wrong OpenAI row. | `AiChatUsageMeter` writes action `AI_CALL` with the tokens. `FacadeMeteredChat::logMetadata()` then clears the controller's `OPENAI_API` allowance row. The controller never sets `usage_source`, so the billed row is source `ai_facade`. | CB2 stamps `usage_source = OPENAI_API` plus client, requested model, and `key_source` via `claim()`. CB4 sums `AI_CALL` / `OPENAI_API` and `MESSAGES` / `MESSAGES_API`, and drops `facade_metered`. A fixture asserts the tokens are counted once. |
| Own-key and a free model both cost 0. | `zero_cost` is set for a user key. A local model is `operator` and can also cost 0 (`isLocalHost`). The first query grouped only by model and client. | Group by `key_source`. The own-key sentence is `user` only. `key_source` comes from `UserProviderKeyResolver`, not from the cost. |

## 6. Flexibility (owner, 2026-11-09)

The first draft's title was the product: one coding model per account,
and an empty pin as the only way to keep today's editors. That is the
wrong default to put on a page. Developers who use Synaplan as a
backend already rely on the editor sending the model, including
Claude Code's five slots and a list in Pi or OpenCode.

The revised plan:

- Two modes, one fieldset, on a new page **Apps → Developer backend**.
  Editor chooses is the default and does not change resolution. One
  model is the opt-in.
- The Claude Code app stays the connection page (gateway status,
  Anthropic key, API key) and links to the new page. Admin → Coding
  clients stays the install switch. Three jobs, three screens.
- A group may set a one-model default. A member may pick another model
  or opt out. The opt-out is a user row, because deleting the row
  would reveal the group model again.
- Slots, per-key pins, and a coding-only allow-list are not controls.
  Slots are the editor's config in editor-chooses mode. The copy block
  shows the five variables and says they may differ.

Open questions from §3:

- Q1 and Q2 stay open. Q1 is re-checked in the week CB3 is written. Q2
  is the CB2 capture todo.
- Q3 is decided with D4: a one-model turn forwards images, and the page
  says they may be refused. Editor chooses keeps today's vision rewrite.
- Q4 is decided with D6: the Claude Code app is not renamed. The new
  page is Developer backend.
