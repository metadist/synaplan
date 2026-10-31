# Adding an AI provider

One provider is one class. The class carries a `ProviderDescriptor`
(`App\AI\Catalog\`), and every table the installation keeps reads that
descriptor: the platform key store and its environment map, the recommended
defaults, the Messages gateway's upstream URLs, the admin configuration
schema, the public catalog `GET /api/v1/ai/providers` that the frontend
draws names, icons and help from, and the outbound allowlist. Nothing in
`config/services.yaml` and nothing in the frontend names a provider. The
architecture of the catalog is in [`ARCHITECTURE.md`](ARCHITECTURE.md),
"Provider catalog"; the contract is
[`03_contracts.md`](../_devextras/planning/20261021-architecture-update/03_contracts.md)
§2.

What the class cannot carry are the model rows (`ModelCatalog`) and the
help sentence in the five locales. The scaffold prints both.

## An OpenAI-compatible provider in one command

```bash
docker compose exec backend php bin/console app:provider:scaffold acme \
  --openai-compatible \
  --display-name "Acme AI" \
  --base-url https://api.acme.ai/v1 \
  --console-url https://console.acme.ai/keys \
  --model acme-large
```

The command refuses a name the catalog already has, a name that is not
2–32 lowercase letters and digits, and a base URL without a host. `--dry-run`
prints everything and writes nothing; `--force` overwrites a class and test
you generated before. It writes:

| File | What it is |
| ---- | ---------- |
| `backend/src/AI/Provider/AcmeProvider.php` | The provider: `#[AsAiProvider]`, a `descriptor()` with the chat-completions endpoint, the `ACME_API_KEY` platform key validated with `GET /models`, the OpenAI JSON-schema dialect, the default bindings, and the branding (`helpKey: 'acme'`). It extends `AbstractChatCompletionsCloudProvider`, which already speaks chat completions with tools, vision and streaming. |
| `backend/tests/Unit/AI/Provider/AcmeProviderTest.php` | Pins the descriptor values a typo would silently change, and that discovery finds the class. |

And it prints, to paste:

- **One model row** for `ModelCatalog::MODELS`
  (`backend/src/Model/ModelCatalog.php`) with the next free id. The
  lowercase of `service` is the provider name (`'Acme'` → `acme`); the
  `providerId` is the model id the API expects. Edit price, context window,
  `features` and `jurisdiction` from the provider's model page, then
  `make -C backend seed` (or `php bin/console app:model:seed`) upserts it.
  One row per model you want to offer.
- **The help sentence** under `admin.providerHelp.acme` for
  `frontend/src/i18n/locales/{en,de,es,fr,tr}/admin.json`. The locale
  parity test fails while a locale lacks it.

Then:

```bash
make ci-local
```

and the provider is under Admin → AI infrastructure → Providers & keys. Paste
a key: it is tested live against `GET /models` and stored encrypted. "Use as
default" applies the descriptor's bindings. The models appear in the pickers
once a key is saved and the rows are seeded.

## What the descriptor decides

| Field | Read by |
| ----- | ------- |
| `name`, `displayName` | every list, the model service name (`strtolower(service) === name`), `ProviderException::displayName()` |
| `capabilities` | `ProviderCatalogPass` tags the adapter `app.ai.<capability>` for the registry |
| `endpoints` | `ChatCompletionsUpstreams` (the Messages gateway), `EgressPolicy` (the outbound allowlist, together with the key validation host) |
| `key` | `ProviderKeyStore` (env variable, `%env(default::X)%` built by the pass), `ProviderKeyCatalog`, the admin key test (`validation`), the model-list probe (`modelListing`), `AiTab` (`presentation`: section, order, texts), the public catalog (`consoleUrl`, `userCredential`, `servesChat`) |
| `dialects` | `StructuredOutputCapability`, `ToolCallingCapability`, `CatalogToolUse`, the translator's inline-image rule, `ReasoningLevelCatalog` |
| `defaults` | `ProviderDefaultsService` ("Use as default", `preferenceOrder()`) |
| `pricing` | `SyncModelPricesCommand` |
| `liveVoiceSessions` | `VoiceLiveConcurrency` |
| `branding` | `GET /api/v1/ai/providers` → the frontend's icons, flags, help hints |

The test that proves the chain for a new provider is
`backend/tests/Integration/AI/AddProviderJourneyTest.php`: it boots the
kernel with a fixture plugin that contains only a provider class
(`tests/Fixtures/plugins/provider/acme_ai/`) and asserts the provider is in
every table above, that `GET /api/v1/ai/providers` lists it, and that the
exported OpenAPI spec did not change.

## A provider with its own wire format

Without `--openai-compatible` the scaffold stops: there is no template for
a provider that is not chat completions. Start from the closest adapter in
`backend/src/AI/Provider/` (Anthropic for a messages API, Google for Gemini,
Ollama for a local engine), implement the capability interfaces the
provider offers, and keep the same shape: `#[AsAiProvider]`,
`DescribesProviderInterface::descriptor()`, no entry in `services.yaml`. If
the adapter is frozen in the size ledger, the descriptor goes into a sibling
`App\AI\Provider\Descriptor\<Name>Descriptor` that names the adapter with
`#[AsAiProvider(adapter: <Name>Provider::class)]`.

## A provider as a plugin

A plugin's `backend/` directory is loaded with autowiring and
autoconfiguration, so a provider class there is discovered the same way
(`manifest.json` with `"provides": {"plugs": []}` — the fixture above is the
example). A plugin provider is only in the container's catalog: the three
static readers of `ProviderCatalog::builtIn()` (the gateway's vision
rewrite, `CatalogToolUse`, `ReasoningLevelCatalog`) do not see it until they
get an injection. Its help sentence still needs the five locale keys in the
application.

## The outbound allowlist

`EgressBuiltinDestinations::INTERNAL` names this installation's own
services, `INTEGRATIONS` the hosts that are not AI providers (Stripe,
Telegram, Meta Graph, Brave, Google sign-in and Play, GitHub, Apple,
reCAPTCHA, Cloudflare challenges). The provider hosts are
`ProviderCatalog::endpoints()`: every endpoint host and key-validation host
of every described provider. A provider you add is allowed in `enforce`
mode the moment its class exists; nothing is edited in the egress code. See
[`EGRESS.md`](EGRESS.md).
