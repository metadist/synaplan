# S3 — Providers as self-contained units

Master plan §5 rows AU19–AU22; architect direction 2. Contract:
[`03_contracts.md`](./03_contracts.md) §2. Loop:
[`01_vibe_coding_loop.md`](./01_vibe_coding_loop.md).

**Shape of the sprint:** each provider describes itself and a parity test
proves the descriptions equal today's tables (AU19); the tables then ask the
catalog one by one (AU20), the frontend follows (AU21), and adding a provider
becomes one file (AU22). Measured today: a Cerebras-class provider touches
15–21 spine files; the Cerebras pull request touched 41 (STATUS code map).

---

## AU19 — Every provider declares its capabilities, endpoints and keys (backend-only)

### Goal

- Do: `App\AI\Catalog\` with `ProviderDescriptor` and its parts (`03` §2),
  `ProviderCatalog` (all descriptors, by name, by capability, `withKey()`,
  `endpoints()`), and `descriptor()` on every provider class in
  `App\AI\Provider\`, filled from today's tables.
- Do: `ProviderCatalogParityTest` (`03` §2.2): every table of `03` §2.1,
  rebuilt from the descriptors, equals today's constant, entry by entry.
- Do not: change a consumer. Nothing reads the catalog yet except the test.

### Vibe brief

> Read `03` §2. Implement AU19 as a `refactor(ai)`. Write the parity test
> first against a catalog of empty descriptors and watch it fail table by
> table; fill each provider's descriptor until it passes. Where two tables
> disagree today (e.g. a provider listed in one capability list but not in
> the key catalog), the descriptor follows the behaviour and STATUS names the
> disagreement — do not fix it here. Mutation: drop one capability from one
> descriptor, see the parity test fail. `make ci-local`.

### Exit criteria

1. Parity test green for every table of `03` §2.1.
2. Every disagreement between today's tables listed in STATUS.
3. `make ci-local` green; no consumer changed.

---

## AU20 — Provider tables ask the provider catalog (backend-only)

### Goal

- Do: the consumers of `03` §2.1 read `ProviderCatalog`, one table per
  commit, and their constants go. `services.yaml` tags and `$envKeys` come
  from `#[AsAiProvider]` with `registerAttributeForAutoconfiguration`.
- Do: the parity test turns into a consumer test per table (same data, read
  through the consumer's public method).
- Do: split into AU20a (credential, defaults, upstreams, dialects) and AU20b
  (DI tags, admin fields, pricing, voice caps) if the size budget is passed.
- Do not: change a value. Every snapshot, every provider request recording,
  `ProviderRegistryKeyTest` and the admin AI-keys E2E spec stay green.

### Vibe brief

> Implement AU20 as a `refactor(ai)`, one table per commit, each commit
> green. `lint:container` after the DI change: the same tags, the same
> locator keys (compare `debug:container --tag=app.ai.chat` before and after
> in STATUS). `make ci-local`, `make test-e2e`.

### Exit criteria

1. No name-keyed provider table left in the §2.1 list (grep in STATUS).
2. `debug:container` tag output identical before and after.
3. Every snapshot unchanged; `make ci-local`, `make test-e2e` green.

---

## AU21 — Provider names, icons and help come from the provider catalog (ota-candidate)

### User-flow

No new screen. Admin → AI keys, the model pickers and the provider help
hints look exactly as before; the journey is "nothing changed" checked on
those three places. The help text stays in the locale files
(`admin.providerHelp.<helpKey>`), so all five languages keep working.

### Goal

- Do: `GET /api/v1/ai/providers` (name, display name, capabilities, icon id,
  help key, key requirements; public subset for signed-in users), OpenAPI →
  Zod.
- Do: `providerIcons.ts`, `providerHelp.ts`, `ServiceIcon.vue` read it via a
  small store; a bundled fallback map only for offline first paint.
- Do not: touch `stores/config.ts` or any `*RuntimeConfig*` file (§0 #7).

### Vibe brief

> Implement AU21. Screenshots of Admin → AI keys, the model picker and one
> help hint, light and dark, before the change — after the change they must
> match (attach both in the pull request). Unit tests for the store and the
> fallback. `make -C frontend generate-schemas`, `vue-tsc`, `make ci-local`,
> `make test-e2e`, layout suite. `node scripts/mobile-impact.mjs` must say
> `ota-candidate`, never `store-required`.

### Exit criteria (with the UX contract §6 bullets)

1. The three places walked, before / after identical (U10).
2. Findability unchanged (U2).
3. Copy unchanged in five locales (U3).
4. API down ⇒ the fallback map renders the names (U8).
5. Dark + V2 + 320 px (U9).
6. Gates green; class `ota-candidate`.

---

## AU22 — Add an OpenAI-compatible provider in one file (backend-only)

### User-flow

Journey **J-AU-4**: a developer adds "Acme AI" with
`docker compose exec backend php bin/console app:provider:scaffold acme --openai-compatible`,
fills the descriptor and model rows, runs `make ci-local`, and finds Acme
under Admin → AI keys, in the model list after a key is saved, and in the
egress allowlist.

### Goal

- Do: `app:provider:scaffold` (provider class with descriptor on
  `AbstractChatCompletionsCloudProvider`, a test, a `ModelCatalog` snippet to
  paste, the five locale help keys); `docs/PROVIDERS.md`.
- Do: `AddProviderJourneyTest`: a fixture provider registered only through
  its class appears in the key store, defaults, upstreams, the admin AI tab
  schema, `GET /api/v1/ai/providers` and `EgressPolicy`'s allow sources.
- Do: `EgressPolicy` takes provider endpoints from the catalog (replaces
  AU15's static list).
- Do not: generate migrations; touch `ModelCatalog` structure.

### Vibe brief

> Implement AU22 as a `feat(ai)`. The journey test first, with a fixture
> provider in `tests/`; it must fail on the parent at the first table it
> does not reach. Then the command and the docs. Walk J-AU-4 on the dev stack
> with a fake OpenAI-compatible stub (the spike relay's `upstream` mode
> answers chat-completions SSE) and enter the file count in STATUS:
> one provider class + its test + model rows + five locale keys.
> `make ci-local`, `make test-e2e`.

### Exit criteria

1. `AddProviderJourneyTest` green; red on the parent.
2. J-AU-4 walked; file count in STATUS.
3. The egress allowlist follows the enabled providers (test).

---

## S3 exit

- One provider = one class (+ model rows and help text); every other table
  asks the catalog.
- The architect's second direction answered; "providers as a plugin type"
  is master plan §9 #4, on top of row 18's manifest.
