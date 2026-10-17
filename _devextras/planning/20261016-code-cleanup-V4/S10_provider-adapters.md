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
| S10.1 | `refactor(ai): an injectable OpenAI SDK factory, recorded offline in tests` | Google's requests go through the injected Symfony client. OpenAI's Responses, embedding and image requests do not: `OpenAIProvider::client()` builds its own SDK client with `\OpenAI::client($key)` (line 88). The provider gets a seam: an optional constructor parameter for the SDK factory, whose default is that same call, byte for byte. `services.yaml` stays untouched. Tests build the SDK with `\OpenAI::factory()->withApiKey(…)->withHttpClient($guzzle)`, where `$guzzle` is a Guzzle client (already installed) on a `MockHandler` with history middleware. There is a third transport: `generateImage()` (line 1,095) calls native `curl_init()` itself (line 1,188), which neither double sees. That call moves verbatim behind the same kind of seam, an injectable transport with the native cURL code as its default, as in S5.1. Two methods also read their inputs with `file_get_contents()`: `createVariations()` the image (line 1,511), `editImage()` the image and the mask (lines 1,550–1,551). Their cases use local fixture files in a temporary directory, the mask included, so the snapshot records the SDK request and never fetches from an external host. The commit carries a test that fails if any request leaves the process. |
| S10.2 | `test(ai): request snapshots for the Google and OpenAI providers` | `ProviderRequestCharacterizationTest`. For each capability method, from fixed inputs, streamed and buffered: the request sent (method, URL, headers without the key, body) and the parsed result. Captured from `MockHttpClient` for the Symfony transport, from the Guzzle history for the SDK transport and from the recording double of the cURL seam, so every OpenAI capability is in the snapshot whichever transport it uses. Every case that reads an input file (vision images, image edits and variations, audio for transcription; both providers read them with `file_get_contents()` / `fopen()`) runs on fixture files in a temporary directory passed as `$uploadDir`. Snapshots `provider_requests_google.json` and `provider_requests_openai.json`, recorded before any split (S10.1 changes no request). |
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

## 9. As delivered — S10a, Google (2026-10-17)

The size budget (master plan #6) splits the sprint: S10a is Google, S10b
is OpenAI (the SDK and cURL seams of S10.1, its snapshot, its client and
its classes). S10a starts from S9's head (`59b0c2882`). Where it differs
from the plan:

- **More classes than the target shape.** The planned `GoogleChat` also
  held the message conversion and vision, and `GoogleVideoGeneration` the
  Omni clips; each would have passed 800 lines. `GoogleMessageFormat`
  (pure static translations, like `OpenAiToolShapes`), `GoogleVision` and
  `GoogleOmniVideo` are classes of their own. The largest new class is
  `GoogleVideoGeneration` (546 lines), the longest new method
  `GoogleChat::readStream()` (91).
- **Three methods were cut, not only moved:** `chatStream()` into the
  payload, the reading of the stream and the failure mapping;
  `synthesize()` into finding the audio part and saving it; the message
  conversion into the turns, the tool result and the content parts. The
  snapshot pins all three.
- **No seam step for Google.** Google already sent every request through
  the injected Symfony client, so S10.1 is OpenAI's alone (S10b).
- **One cleanup on the way.** The TTS decode check loses its dead
  `false ===` half (`base64_decode()` in non-strict mode never fails), and
  with it a PHPStan baseline entry.
- **The weak typing stays.** The new classes keep `GoogleProvider`'s weak
  typing, as the media stages kept their handler's.
- **Found, pinned, not fixed.** A streamed Gemini chat that Google answers
  with an HTTP error other than a transient 503 ends with no text, no
  finish event and zero usage instead of an error: Symfony's stream does
  not throw on a status code (checked against the cURL client), so
  `streamFailure()` and its "overloaded" message are unreachable. The fix
  (check the status after the retry, map it through `streamFailure()`)
  changes behaviour, so it is a pull request of its own for the owner.
- **Journey.** With the dev stack's Google key, one chat, one stream, one
  image (the default model) and one speech request ran through the
  refactored provider on Symfony's real HTTP client, from a script in the
  backend container rather than the browser; all four answered. Not a
  gate.

## 10. As delivered — S10b, OpenAI (2026-10-17)

S10b starts from S10a's head (`77cf162ff`, after the S4b merge came
down the chain). It follows the plan's steps: the seams (S10.1), the
snapshot (S10.2), the client (S10.3) and the capabilities (S10.5).
Where it differs:

- **The no-network test catches a bypass precisely.** `OfflineHttpGuard`
  makes PSR-18 discovery, which the SDK uses when no client is injected,
  hand out a client that refuses to send and records the attempt. The
  plan's mutation (embeddings on `\OpenAI::client()`) fails with "A
  request tried to leave the process: POST …/v1/embeddings".
- **Chat is three classes.** Chat and its helpers were about 800 lines.
  `OpenAIResponses` holds the Responses API call that chat and vision
  share (the reasoning tiers, the two retries, the model lookup and their
  caches), `OpenAIResponsesFormat` the pure translations under their old
  names, and `OpenAIChat` the chat. `chatStream()` (119) is cut at its
  stream loop. The largest new class is `OpenAIImageGeneration` (488
  lines), the longest new method `OpenAIChat::readStream()` (95).
- **Five existing test classes retargeted.** They reached private methods
  by reflection; they now reach them on their new classes, with their
  assertions unchanged. `OpenAIFixtures` builds the API answers from the
  SDK's own fixtures for the snapshot and the unit tests.
- **Found, not fixed.** `curl_close()` is deprecated on PHP 8.5 (a no-op
  since PHP 8.0). The gpt-image call, moved verbatim, logs the deprecation
  on every request, and so do three other classes. One cleanup pull
  request for all four.
- **Journey.** With the dev stack's OpenAI key, one chat, one stream, one
  embedding, one gpt-image request (low quality, over the cURL path) and
  one speech request ran through the refactored provider on its
  production defaults, from a script in the backend container rather than
  the browser; all five answered. Not a gate.
