# Meta Model API (Muse Spark) + per-model thinking levels

**Status:** Plan drafted 2026-09-19. **Research only until the decision
checklist in §0 is ticked. No product code in this change.**
**Goal:** offer Muse Spark (Meta Model API) as a first-class chat
provider, and let users set the **thinking effort** of a reasoning model
to the levels that model supports — configured dynamically per model on
the model management page.
**Binding contracts:** [`../20260907_ux_user_flows.md`](../20260907_ux_user_flows.md)
(U1–U12) and AGENTS.md "Perfect UX & Stability".
**Owner of the surfaces:** `App\AI\Provider\*` + `ModelCatalog`/`BMODELS`
seeders (backend); `AIModelsConfiguration.vue` + chat input (frontend).

Files in this folder:

| File | Content |
| ---- | ------- |
| `00_master_plan.md` (this) | Goal, verified baseline, §0 checklist, architecture, journeys, steps, gates |
| [`01_sprint_s1_meta_provider.md`](./01_sprint_s1_meta_provider.md) | S1: Meta provider, catalog rows, keys, health, tests |
| [`02_sprint_s2_thinking_levels.md`](./02_sprint_s2_thinking_levels.md) | S2: effort levels per model + dynamic per-user model config UI |
| [`STATUS.md`](./STATUS.md) | Step log (all planned until §0 is ticked) |

---

## 0. Decision checklist — tick every row before any code

| # | Decision | Recommendation | State |
| - | -------- | -------------- | ----- |
| 1 | Provider key env name | `META_API_KEY` (house `PROVIDER_API_KEY` pattern; Meta docs say `MODEL_API_KEY` — ours wins for consistency) | open |
| 2 | Seeded Muse models | `muse-spark-1.3` only for v1 (flagship; 1M context). 1.2/1.1 on explicit ask. Contributor tier never seeded (different billing). | open |
| 3 | v1 capabilities | `chat` first; `vision` only if the S1 spike confirms image understanding on the chat endpoint with our payload shape; structured output + tool calling ride the shared translator when the spike confirms | open |
| 4 | Canonical effort vocabulary | `off / low / medium / high` as the user-facing levels; each provider maps them to its native parameter (xAI/OpenAI `reasoning_effort`, Anthropic thinking effort/budget, Meta TBD by spike) | open |
| 5 | Meta effort parameter (spike) | Read the live [Reasoning](https://dev.meta.ai/docs/reasoning) doc during S1: exact param name, accepted levels, per-model support, streaming shape. Nothing here hardcodes a guess | open |
| 6 | Effort storage | Per-user default per model key in the user AI config (same store as default models), seeded from the catalog `reasoning_effort_default`; chat request may override per turn | open |
| 7 | UI surface v1 | Model page “Default models” tab gains a dynamic per-model section (see §4); chat keeps the Thinking toggle, which now means “use my configured level” instead of a fixed deep default | open |
| 8 | Chat per-turn override | Not in v1 (toggle + configured default is enough); revisit after S2 walks | open |
| 9 | Metering display | No cost prediction in v1; usage rows keep recording input/output (and reasoning tokens where the provider reports them) | open |
| 10 | Existing `reasoning` bool contract | Unchanged: providers keep accepting it; explicit effort (string) wins where a provider supports it — the xAI resolution order becomes the shared rule | open |

---

## 1. Verified baseline (2026-09-19, `main`)

What already exists — the plan builds on it, it does not re-prove it:

| Fact | Where |
| ---- | ----- |
| OpenAI-compatible providers subclass `AbstractChatCompletionsCloudProvider` (~60 lines: name, display, base URI, env var, defaults, capabilities) | `backend/src/AI/Provider/TrustedTokensProvider.php` (template), `A2AgentProvider.php` |
| Provider wiring = services.yaml env + key map + `app.ai.chat` / `app.ai.vision` tags | `backend/config/services.yaml` (≈L79, L339, L424) |
| Keys resolve from admin UI / env via `ProviderKeyStore` | `backend/src/AI/Credential/ProviderKeyStore.php` |
| Chat options already carry `reasoning` (bool toggle) end to end | `MessageController.php:961` → `ChatHandler.php:1627` → providers |
| xAI resolves `reasoning_effort` with model-family gating + explicit-string override + catalog `reasoning_effort_default` | `XaiProvider.php` `resolveReasoningEffort()` (≈L1241) |
| Model rows advertise `features: ['reasoning', …]` + `reasoning_effort_default` in JSON | `ModelCatalog.php:1543–1596`, `Entity/Model.php` `getFeatures()` |
| Anthropic maps the toggle to thinking blocks (adaptive format where required) | `AnthropicProvider.php:253–282` |
| Reasoning streams render as thinking parts in chat | `ChatView.vue:2389–2403` (`reasoning` SSE chunks) |
| Model management page with Default-models choice tab | `frontend/src/components/config/AIModelsConfiguration.vue`, API via `adminModelsApi.ts` / `AdminModelsController.php` |
| Per-user default provider/model get/set | `ModelConfigService.php` (`get/setDefaultProvider/Model`) |

What does **not** exist (this plan's work):

- No Meta provider, no Meta catalog rows, no `META_*` env wiring.
- No user-settable effort **level**: the toggle is bool-only; `reasoning_effort`
  strings are accepted by xAI but nothing ever sends them.
- No per-model parameter UI on the model page (defaults only).
- Meta specifics below are Docs-Excerpt level and **must** be re-verified by
  spike (docs move fast): base URL, exact effort param/levels, vision payload,
  per-model support matrix.

## 2. Meta Model API — what we know (spike to confirm)

Source: [Meta Model API overview](https://dev.meta.ai/docs/overview)
(excerpt 2026-09-19; S1 spike re-reads the live docs):

| Item | Excerpt value |
| ---- | ------------- |
| Base URL | `https://api.meta.ai/v1` |
| Protocol | Drop-in OpenAI SDK / Anthropic SDK compatible (Chat Completions + Messages + Responses APIs) |
| Chat models | `muse-spark-1.3` / `1.2` / `1.1` (+ `-contributor` billing tier) |
| Context | 1,048,576 tokens |
| Auth | Bearer token (`MODEL_API_KEY` in their docs) |
| Reasoning | Dedicated [Reasoning](https://dev.meta.ai/docs/reasoning) doc: “dial reasoning effort up or down per request” — exact levels TBD by spike |
| Adjacent | Image understanding, structured output, tool calling, prompt caching, token counting |

Not in v1: Muse Image, Muse Voice Transcribe, SAM, Muse Code —
catalog rows stay text-chat until a later vertical asks for them.

## 3. Architecture

### 3.1 S1 — Meta provider (backend-only + catalog)

`MetaProvider extends AbstractChatCompletionsCloudProvider`
(`chat`, +`vision` if the spike confirms). services.yaml: `META_API_KEY`
env, key map entry, `app.ai.chat` (+`vision`) tags. Catalog rows via
`ModelSeeder`/`ModelCatalog::upsert` keyed `meta:muse-spark-1.3[:tag]`;
health + pricing follow the existing provider pattern. No new
abstraction — Meta is OpenAI-compatible by design.

### 3.2 S2 — effort levels + dynamic model config

1. **Catalog:** model JSON gains `reasoning_efforts: ['low','medium','high']`
   (subset per model; absent = toggle-only/unsupported) next to the existing
   `reasoning` feature + `reasoning_effort_default`.
2. **Resolution (shared rule, xAI order generalized):** explicit per-turn or
   per-user effort string (validated against the model's list) wins; else the
   `reasoning` bool maps to default(off→`none`/`off`); else nothing is sent.
   Each provider keeps its native mapping in one resolver method.
3. **Storage:** per-user effort default per model key, beside the default-model
   choices (`ModelConfigService` + the same admin/user config API family).
4. **UI:** the model page renders **only the controls the selected model
   supports** (effort picker appears iff `reasoning_efforts` is non-empty —
   U11: flag off ⇒ absent). Chat toggle keeps working and now resolves through
   the configured level.

## 4. UX (journeys + contract)

| Id | Journey |
| -- | ------- |
| J-MM-1 | **Pick Muse.** User opens Models, picks Muse Spark as default chat model, sends a chat; the answer streams with thinking parts where the model reasons. No key pasted (admin/env key), no provider id typed. |
| J-MM-2 | **Set effort once.** User opens the Muse row, sees an effort picker (Low/Medium/High or the model's subset), picks Medium; every later chat uses it. A model without the list shows no picker. |
| J-MM-3 | **Toggle still works.** In chat, the Thinking toggle on/off behaves as before; “on” now means the configured level, announced once in plain words where the toggle lives. |

U1–U12 apply; five exit bullets per sprint file (§6 of the UX contract)
before the first `.vue`. Banned in primary copy: `reasoning_effort`,
`budget_tokens`, `base_url`, provider ids — plain words only (“How hard
the AI thinks”, Low/Medium/High).

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| S1.0 | `docs(meta): spike — effort param, levels, vision payload, support matrix` (no product code; records doc URLs + dates) | backend-only (docs) | §0 ticked |
| S1.1 | `feat(ai): Meta Model API provider (Muse Spark) + catalog rows` | backend-only | S1.0 |
| S1.2 | `feat(ai): Meta keys, health, pricing + provider tests` | backend-only | S1.1 |
| S2.1 | `feat(ai): per-model reasoning effort levels + resolution rule` | backend-only | S1.1 |
| S2.2 | `feat(models): dynamic per-model config (effort picker) on the model page` | ota-candidate | S2.1 |
| S2.3 | `test(models): effort-matrix + journey specs J-MM-1…3` | ota-candidate | S2.2 |

One PR per step. Branch names `feat/meta-s1-…`, `feat/meta-s2-…`. Never on `main`.

## 6. Gates (every step, no exceptions)

```bash
make ci-local                       # lint, phpstan, phpunit, eslint, vue-tsc, vitest
make test-e2e                       # @ci Playwright (S2.2/S2.3; backend-only steps run it when an OpenAPI/UI contract moves)
node scripts/mobile-impact.mjs --base main --head HEAD
```

- New user-facing strings land in all five locales in the same PR
  (`de`, `en`, `es`, `fr`, `tr`); `localeParity.spec.ts` stays green.
- OpenAPI touched ⇒ `make -C frontend generate-schemas` + `vue-tsc`.
- A step is **done** when its journey (§4) is walked in the browser in
  light, dark and V2 at 1280 px and 320 px.

## 7. Non-goals (v1)

- Muse Image / Voice / SAM endpoints; contributor-tier billing models.
- Per-turn effort picker in chat (toggle + configured default only).
- Cost prediction per effort level; effort analytics.
- Changing the `reasoning` bool contract (additive only).
- Touching the chat streaming renderer (thinking parts already render).
