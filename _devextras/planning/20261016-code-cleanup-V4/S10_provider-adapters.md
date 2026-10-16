# S10 — Google and OpenAI providers split per capability

| | |
| - | - |
| **V3 §7** | #10 |
| **Branch** | `refactor/cleanup-v4-s10-providers`, from S9's branch |
| **Pull request** | `refactor(ai): Google and OpenAI providers split per capability`; S10a (Google) and S10b (OpenAI) if the size budget is exceeded |
| **Class** | backend-only |
| **Owner decision** | — |
| **Chore** | C10 |

## 1. Goal

The two largest provider adapters stop carrying every capability in one
file. Chat, images, video and speech for a vendor each get their own
class, sharing one API client. Every request the provider sends stays
byte for byte the same.

## 2. Measured (2026-10-16)

| Provider | Lines | Ctor | Capabilities (tags in `config/services.yaml`) | Longest methods |
| -------- | ----- | ---- | --------------------------------------------- | --------------- |
| `AI/Provider/GoogleProvider.php` | 2,764 | 9 | chat (+ tool calling), vision, image generation, video generation (+ async), speech to text, text to speech | `chatStream()` 202, `synthesize()` 156, `convertMessagesToGeminiFormat()` 117, `transcribe()` 100 |
| `AI/Provider/OpenAIProvider.php` | 2,079 | 7 | chat (+ tool calling), embedding, vision, image generation, speech to text, text to speech | `chatStream()` 119, `analyzeImage()` 89, `generateImageWithGptImage1()` 88 |

`ProviderRegistry` resolves a provider per capability through the
`app.ai.<capability>` tags, keyed by vendor. `AiFacade`, `ModelCatalog`,
`GoogleAiModule`, `CostCalculationService` and `XaiProvider` name the
provider classes directly. Tests: 6 Google and 5 OpenAI test classes
under `tests/AI/Provider/` and `tests/Unit/AI/Provider/`.

## 3. Target shape

```
AI/Provider/Google/
  GoogleApiClient        endpoints (Gemini API / Vertex), key store, request, error and blocked-content mapping
  GoogleChat             chat, chatStream, tool calling, message conversion, vision
  GoogleImageGeneration  Imagen and Gemini image models
  GoogleVideoGeneration  Veo, async operations
  GoogleSpeech           transcribe, synthesize (WAV / MP3)
AI/Provider/GoogleProvider   the registered façade: the same class, interfaces and tags; each method delegates
```

OpenAI follows the same shape (`OpenAIApiClient`, `OpenAIChat` with the
Responses API, `OpenAIEmbedding`, `OpenAIImageGeneration`,
`OpenAISpeech`). `OpenAIApiClient` holds all three of OpenAI's transports:
the injected Symfony client, the SDK client built through the factory
seam of S10.1, and the native cURL call behind its own S10.1 seam.

**Why a façade:** the class name, its interfaces and its tags are what the
registry, five other classes and the module system know. Keeping them
makes the split invisible to everything outside the folder: no wiring
change and no `services.yaml` diff. Registering the capability classes
directly is a later step, when no class names the façade any more (V5).

## 4. Steps

| Step | Commit | What |
| ---- | ------ | ---- |
| S10.1 | `refactor(ai): an injectable OpenAI SDK factory, recorded offline in tests` | Google's requests go through the injected Symfony client. OpenAI's Responses, embedding and image requests do not: `OpenAIProvider::client()` builds its own SDK client with `\OpenAI::client($key)` (line 88). The provider gets a seam: an optional constructor parameter for the SDK factory, whose default is that same call, byte for byte. `services.yaml` stays untouched. Tests build the SDK with `\OpenAI::factory()->withApiKey(…)->withHttpClient($guzzle)`, where `$guzzle` is a Guzzle client (already installed) on a `MockHandler` with history middleware. There is a third transport: `generateImage()` (line 1,095) calls native `curl_init()` itself (line 1,188), which neither double sees. That call moves verbatim behind the same kind of seam, an injectable transport with the native cURL code as its default, as in S5.1. The commit carries a test that fails if any request leaves the process. |
| S10.2 | `test(ai): request snapshots for the Google and OpenAI providers` | `ProviderRequestCharacterizationTest`. For each capability method, from fixed inputs, streamed and buffered: the request sent (method, URL, headers without the key, body) and the parsed result. Captured from `MockHttpClient` for the Symfony transport, from the Guzzle history for the SDK transport and from the recording double of the cURL seam, so every OpenAI capability is in the snapshot whichever transport it uses. Snapshots `provider_requests_google.json` and `provider_requests_openai.json`, recorded before any split (S10.1 changes no request). |
| S10.3 | `refactor(ai): one API client per vendor` | `GoogleApiClient`; `OpenAIApiClient` owns the three OpenAI transports (Symfony client, SDK factory, cURL seam). |
| S10.4 | `refactor(ai): Google capabilities in their own classes` | Chat, images, video, speech; the façade delegates. |
| S10.5 | `refactor(ai): OpenAI capabilities in their own classes` | The same for OpenAI. |
| C10 | `chore(planning): close V4 S10 — sprint test, status, ledgers` | Loop §7 |

## 5. Sprint test

- **Proof:** both request snapshots, recorded after the S10.1 seam and
  before any split, unchanged after every later step. The no-network test from
  S10.1. A unit test per capability class against its client double.
  Mutation check: change one header in a client and see the snapshot
  fail; route one OpenAI capability back to the default SDK client and see
  the no-network test fail.
- **Regression:** the 11 existing provider test classes green with
  unchanged assertions; `lint:container`; no `services.yaml` diff;
  unfiltered gates.
- **Journey:** CI has no vendor keys, so `make test-e2e` runs on the test
  provider. With a Google and an OpenAI key on the dev stack, run one chat,
  one image and one speech request per vendor in the browser and record
  them in STATUS. This is not a gate: say so in the pull request when no
  key was available.

## 6. Reviewer focus

Streaming: chunk parsing, reasoning chunks and the final usage chunk move
with `chatStream()`. Error mapping: a provider error must still reach the
user as the same `ProviderException` with the same context.

## 7. Exit

- `GoogleProvider` and `OpenAIProvider` < 800 lines and leave the file
  ledger; no method in the new classes > 100 lines, so the four ledger
  entries `GoogleProvider::chatStream` (202), `::synthesize` (156),
  `::convertMessagesToGeminiFormat` (117) and `OpenAIProvider::chatStream`
  (119) are gone.
- `docs/ARCHITECTURE.md` gains "Provider adapters": the façade + capability
  recipe that `XaiProvider` and `AnthropicProvider` follow in V5.

## 8. Not in S10

`XaiProvider`, `AnthropicProvider` and the smaller providers (V5). Model
catalog, pricing and the registry.
