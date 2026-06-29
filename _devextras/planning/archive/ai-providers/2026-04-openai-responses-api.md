# OpenAI Responses API Integration Plan

**Branch:** `feat/openai-responses`
**Status:** Planning
**Date:** 2026-03-10

## Problem

OpenAI's newer models (`gpt-5.4-pro`, and likely future releases) are **Responses API only** — they do not support `v1/chat/completions`. When a Synaplan user selects one of these models, the request fails:

```
OpenAI streaming error: This is not a chat model and thus not supported
in the v1/chat/completions endpoint. Did you mean to use v1/completions?
```

Our entire OpenAI integration currently goes through `$this->client->chat()->create()` / `createStreamed()` from the `openai-php/client` SDK, which maps to `POST /v1/chat/completions`.

## Goal

Add Responses API (`POST /v1/responses`) support alongside the existing Chat Completions path, so both API styles coexist. The model's `BJSON` column in `BMODELS` determines which API to use — no hardcoded model-name lists.

---

## Part 1 — Library Upgrade

### Current state

```
"openai-php/client": "^0.17.1"
```

Version 0.17.x does **not** have a `responses()` resource.

### Target

```
"openai-php/client": "^0.19.0"
```

**v0.19.0** (released 2026-02-10) ships full Responses API support:
- `$client->responses()->create([...])`
- `$client->responses()->createStreamed([...])`
- `$client->responses()->retrieve($id)`
- `$client->responses()->delete($id)`
- `$client->responses()->listInputItems($id)`

The streaming implementation handles the Responses API's unique SSE event types
(`response.output_text.delta`, `response.completed`, etc.) internally.

### Upgrade steps

```bash
cd backend
composer require "openai-php/client:^0.19.0"
composer update openai-php/client
```

Then verify nothing broke by running the full test suite:

```bash
make -C backend test
make -C backend phpstan
```

### Risk assessment

The jump from 0.17 → 0.19 is two minor versions. Check the changelog for:
- Breaking changes in existing `chat()`, `embeddings()`, `audio()`, `images()` resources
- Any renamed methods or changed return types
- New PHP version requirements

If 0.19 introduces breaking changes to `chat()->create()` signatures, fix those
first before adding any Responses API code.

---

## Part 2 — Model Registration: BJSON `api` field

### Design: `"api": "responses"` in BJSON

The `BMODELS.BJSON` column already stores model metadata (features, params, meta).
Add a new top-level key **`api`** to signal which OpenAI endpoint to use.

| `api` value | Meaning | Endpoint |
|-------------|---------|----------|
| *(absent)* | Default: Chat Completions | `POST /v1/chat/completions` |
| `"chat_completions"` | Explicit: Chat Completions | `POST /v1/chat/completions` |
| `"responses"` | Responses API | `POST /v1/responses` |

This is **provider-agnostic by design** — only the OpenAI provider reads this field.
Other providers (Anthropic, Groq, Google, Ollama) ignore it.

### ModelCatalog entries

Add `gpt-5.4-pro` to `ModelCatalog::MODELS`:

```php
[
    'id' => 190,
    'service' => 'OpenAI',
    'name' => 'GPT-5.4 Pro',
    'tag' => 'chat',
    'selectable' => 1,
    'active' => 1,
    'providerId' => 'gpt-5.4-pro',
    'priceIn' => 30,
    'inUnit' => 'per1M',
    'priceOut' => 180,
    'outUnit' => 'per1M',
    'quality' => 10,
    'rating' => 1,
    'json' => [
        'description' => 'OpenAI GPT-5.4 Pro — smartest model, Responses API only. '
                       . 'Best for complex reasoning. Slow, expensive.',
        'api' => 'responses',
        'params' => ['model' => 'gpt-5.4-pro'],
        'features' => ['reasoning'],
        'meta' => [
            'context_window' => '1050000',
            'max_output' => '128000',
            'responses_only' => true,
        ],
    ],
],
```

Also update the existing `gpt-5.4` entry (id=180) to explicitly mark it:

```php
'json' => [
    // ... existing fields ...
    'api' => 'chat_completions',  // explicit, but same as default
],
```

### Model Entity: helper method

Add a convenience method to `App\Entity\Model`:

```php
public function getApiType(): string
{
    return $this->json['api'] ?? 'chat_completions';
}

public function requiresResponsesApi(): bool
{
    return 'responses' === $this->getApiType();
}
```

---

## Part 3 — OpenAIProvider Changes

### Architecture: single provider, two code paths

`OpenAIProvider` already handles multiple capabilities (chat, embeddings, vision,
TTS, STT, image gen). We add the Responses API as an internal routing decision
inside `chat()` and `chatStream()`, not as a separate provider class.

### Flow

```
ChatHandler → AiFacade → OpenAIProvider
                              │
                    ┌─────────┴──────────┐
                    │  options['api']     │
                    │  == 'responses' ?   │
                    ├─────────┬──────────┤
                    │  YES    │    NO    │
                    ▼         ▼          │
          responsesChat()  chat()       │
          responsesStream() chatStream()│
                    │         │          │
                    ▼         ▼          │
             POST /v1/     POST /v1/    │
             responses     chat/        │
                           completions  │
```

### Passing `api` type through the call chain

The `api` type needs to flow from the model's BJSON to the provider:

1. **ChatHandler** (already reads model JSON for features) — add:
   ```php
   $apiType = $model?->getApiType() ?? 'chat_completions';
   // ...
   $aiOptions = array_merge([
       'provider' => $provider,
       'model' => $modelName,
       'api' => $apiType,          // <-- NEW
       'modelFeatures' => $modelFeatures,
   ], $options);
   ```

2. **AiFacade** — passes `$options` through unchanged (no changes needed).

3. **OpenAIProvider** — reads `$options['api']` to decide the code path.

### Non-streaming: `chat()`

```php
public function chat(array $messages, array $options = []): string
{
    // ... existing validation ...

    $apiType = $options['api'] ?? 'chat_completions';

    if ('responses' === $apiType) {
        return $this->responsesChat($messages, $options);
    }

    // ... existing chat completions code ...
}
```

New private method:

```php
private function responsesChat(array $messages, array $options): string
{
    $model = $options['model'];

    // Separate system message as 'instructions' (Responses API pattern)
    $instructions = null;
    $input = [];
    foreach ($messages as $msg) {
        if ('system' === $msg['role']) {
            $instructions = ($instructions ? $instructions . "\n" : '') . $msg['content'];
        } else {
            $input[] = $msg;  // user/assistant messages pass through as-is
        }
    }

    $requestOptions = [
        'model' => $model,
        'input' => $input,
        'store' => false,   // don't persist on OpenAI's side
    ];

    if ($instructions) {
        $requestOptions['instructions'] = $instructions;
    }

    // Token limits
    if (isset($options['max_tokens'])) {
        $requestOptions['max_output_tokens'] = $options['max_tokens'];
    }

    // Reasoning effort (responses API supports: medium, high, xhigh)
    if (isset($options['reasoning_effort'])) {
        $requestOptions['reasoning'] = [
            'effort' => $options['reasoning_effort'],
        ];
    }

    $response = $this->client->responses()->create($requestOptions);

    // Extract text from output items
    $text = '';
    foreach ($response->output as $item) {
        if ('message' === $item->type) {
            foreach ($item->content as $contentBlock) {
                if ('output_text' === $contentBlock->type) {
                    $text .= $contentBlock->text;
                }
            }
        }
    }

    $this->logger->info('OpenAI Responses API: Chat completed', [
        'model' => $model,
        'usage' => [
            'input_tokens' => $response->usage->inputTokens ?? 0,
            'output_tokens' => $response->usage->outputTokens ?? 0,
        ],
    ]);

    return $text;
}
```

> **Note:** The exact response object structure depends on `openai-php/client` v0.19's
> implementation. The above is based on the PR description and OpenAI's API shape.
> Adjust property access (`->output`, `->content`, `->text`) after inspecting the
> actual SDK classes post-upgrade.

### Streaming: `chatStream()`

```php
public function chatStream(array $messages, callable $callback, array $options = []): void
{
    // ... existing validation ...

    $apiType = $options['api'] ?? 'chat_completions';

    if ('responses' === $apiType) {
        $this->responsesChatStream($messages, $callback, $options);
        return;
    }

    // ... existing chat completions streaming code ...
}
```

New private method:

```php
private function responsesChatStream(array $messages, callable $callback, array $options): void
{
    $model = $options['model'];

    // Same system→instructions extraction as non-streaming
    $instructions = null;
    $input = [];
    foreach ($messages as $msg) {
        if ('system' === $msg['role']) {
            $instructions = ($instructions ? $instructions . "\n" : '') . $msg['content'];
        } else {
            $input[] = $msg;
        }
    }

    $requestOptions = [
        'model' => $model,
        'input' => $input,
        'store' => false,
        'stream' => true,
    ];

    if ($instructions) {
        $requestOptions['instructions'] = $instructions;
    }

    if (isset($options['max_tokens'])) {
        $requestOptions['max_output_tokens'] = $options['max_tokens'];
    }

    $stream = $this->client->responses()->createStreamed($requestOptions);

    foreach ($stream as $event) {
        // The SDK's streaming likely exposes typed events.
        // Map them to our existing callback format:
        //   ['type' => 'reasoning', 'content' => '...']
        //   ['type' => 'content',   'content' => '...']

        // Adapt based on actual SDK event types after upgrade.
        // Expected events from OpenAI:
        //   response.output_text.delta  → text chunk
        //   response.reasoning.delta    → reasoning chunk
        //   response.completed          → done

        $eventArray = $event->toArray();

        // Handle reasoning content
        if (isset($eventArray['type']) && 'response.reasoning_summary_text.delta' === $eventArray['type']) {
            $callback(['type' => 'reasoning', 'content' => $eventArray['delta'] ?? '']);
        }

        // Handle text content
        if (isset($eventArray['type']) && 'response.output_text.delta' === $eventArray['type']) {
            $callback(['type' => 'content', 'content' => $eventArray['delta'] ?? '']);
        }
    }
}
```

> **Important:** The exact SSE event type names (`response.output_text.delta`, etc.)
> and the SDK's streaming API (`createStreamed`, event object shape) MUST be verified
> against the actual `openai-php/client` v0.19 source code after the upgrade. The
> above is based on OpenAI's documented event types and the PR description. Consider
> writing a small test script that dumps raw streaming events to confirm the mapping.

---

## Part 4 — Message Format Translation

### System messages → `instructions`

The Responses API has a dedicated `instructions` field for system-level guidance.
This is cleaner than mixing system messages into `input`.

**Current Synaplan convention:**
Messages arrive as `[{role: 'system', content: '...'}, {role: 'user', content: '...'}, ...]`

**Translation rule:**
- All `role: 'system'` messages → concatenated into `instructions` (string)
- All `role: 'user'` and `role: 'assistant'` messages → stay in `input` array
- Multi-turn history works the same: `input` accepts `[{role, content}, ...]`

### Vision messages

The Responses API supports image content in `input`:

```json
{
  "input": [{
    "role": "user",
    "content": [
      { "type": "input_text", "text": "Describe this image" },
      { "type": "input_image", "image_url": "data:image/png;base64,..." }
    ]
  }]
}
```

Note the type names differ from Chat Completions (`input_text` vs `text`,
`input_image` vs `image_url`). The message translation in `responsesChat()` needs
to handle this if we want Responses API support for vision models too.

**For v1:** Vision support is NOT required for `gpt-5.4-pro` (it supports images
but we can handle that via Chat Completions with `gpt-5.4` which does support
`v1/chat/completions`). Defer vision translation to a follow-up.

---

## Part 5 — Changes by File

### Files to modify

| File | Change |
|------|--------|
| `backend/composer.json` | Bump `openai-php/client` from `^0.17.1` to `^0.19.0` |
| `backend/src/AI/Provider/OpenAIProvider.php` | Add `responsesChat()`, `responsesChatStream()`, routing logic in `chat()` and `chatStream()` |
| `backend/src/Entity/Model.php` | Add `getApiType()` and `requiresResponsesApi()` helpers |
| `backend/src/Model/ModelCatalog.php` | Add `gpt-5.4-pro` entry with `'api' => 'responses'` |
| `backend/src/Service/Message/Handler/ChatHandler.php` | Pass `api` type from model JSON into `$aiOptions` |

### Files that do NOT change

| File | Why |
|------|-----|
| `AiFacade.php` | Already passes `$options` transparently — no changes needed |
| `ProviderRegistry.php` | Provider selection is by service name, unaffected |
| `ModelConfigService.php` | Resolves model ID to provider+name, unaffected |
| `ChatProviderInterface.php` | Interface stays the same — `$options` is already `array` |
| `StreamController.php` | SSE format is handled by ChatHandler callbacks, unaffected |
| Frontend code | No changes — SSE events (`data`, `complete`, `error`) are the same |

---

## Part 6 — Implementation Order

### Phase 1: Library upgrade + smoke test

1. Bump `openai-php/client` to `^0.19.0`
2. Run full backend test suite — ensure no regressions in existing Chat Completions
3. Run PHPStan — ensure no type errors from SDK changes
4. Manually test an existing OpenAI model (e.g. `gpt-5.4`) still works via chat

### Phase 2: Model entity + catalog

1. Add `getApiType()` / `requiresResponsesApi()` to `Model` entity
2. Add `gpt-5.4-pro` to `ModelCatalog`
3. Run `php bin/console app:models:sync` to push catalog to DB

### Phase 3: Provider routing

1. Add `responsesChat()` private method to `OpenAIProvider`
2. Add routing logic in `chat()` based on `$options['api']`
3. Test non-streaming with `gpt-5.4-pro` via API or `bin/console`

### Phase 4: Streaming

1. Add `responsesChatStream()` private method
2. Add routing logic in `chatStream()`
3. Test streaming via the web UI with `gpt-5.4-pro` selected
4. Verify reasoning chunks flow through if the model provides them

### Phase 5: ChatHandler wiring

1. Update `ChatHandler::handleStream()` to read `$model->getApiType()` and pass it
2. End-to-end test: select GPT-5.4 Pro in UI → stream works

### Phase 6: Quality gate

```bash
make lint
make -C backend phpstan
make test
docker compose exec -T frontend npm run check:types
```

---

## Part 7 — Risks + Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| SDK v0.19 breaks existing `chat()` calls | All OpenAI models broken | Upgrade library first, run full tests before adding any code |
| Responses streaming event format differs from what we expect | Streaming broken for new models only | Write a raw-dump test script post-upgrade to inspect actual event shapes |
| `gpt-5.4-pro` is slow (minutes per request) | Timeouts, poor UX | Use streaming (avoids HTTP timeout), add a note in the model description |
| OpenAI adds more Responses-only models | Need to keep updating catalog | The `'api' => 'responses'` pattern in BJSON is generic — just add the flag per model |
| Message format edge cases (multi-turn with mixed system messages) | Incorrect prompt construction | Consolidate all system messages into `instructions`, test with real conversations |

---

## Part 8 — Future Considerations (not in scope for v1)

- **`previous_response_id` for multi-turn:** Could reduce token usage by not resending
  full conversation history. Requires storing OpenAI response IDs in our message table.
- **Built-in tools (web_search, file_search):** The Responses API supports native tools.
  We could expose these to users who select Responses API models.
- **Migrate all OpenAI models to Responses API:** OpenAI recommends this for better
  performance and cost (40-80% cache improvement). Could be done gradually by flipping
  the `api` field in each model's BJSON.
- **Vision via Responses API:** Different content type names (`input_text`, `input_image`).
  Needed if future vision models become Responses-only.
- **OpenAI-compatible controller:** Our `OpenAICompatibleController` exposes Synaplan
  as an OpenAI-compatible API. Consider adding a `/v1/responses` incoming endpoint too.
- **Reasoning effort control:** The Responses API supports `reasoning.effort` (medium,
  high, xhigh). Wire this to the frontend's reasoning toggle for fine-grained control.

---

## Quick Reference: API Differences

| Aspect | Chat Completions | Responses API |
|--------|-----------------|---------------|
| Endpoint | `POST /v1/chat/completions` | `POST /v1/responses` |
| Input messages | `messages: [{role, content}]` | `input: [{role, content}]` or string |
| System prompt | `{role: 'system', content}` in messages | `instructions: '...'` top-level |
| Output | `choices[0].message.content` | `output[].content[].text` |
| Token limit param | `max_tokens` / `max_completion_tokens` | `max_output_tokens` |
| Temperature | `temperature: 0.7` | `temperature: 0.7` (same) |
| Streaming deltas | `choices[0].delta.content` | `response.output_text.delta` event |
| Reasoning deltas | `choices[0].delta.reasoning_content` | `response.reasoning_summary_text.delta` event |
| Multi-turn | Resend full message history | `previous_response_id` (optional) |
| Store on OpenAI | `store: true/false` | `store: true/false` (default true) |
| SDK (PHP) | `$client->chat()->create()` | `$client->responses()->create()` |
| SDK (PHP stream) | `$client->chat()->createStreamed()` | `$client->responses()->createStreamed()` |
| Min SDK version | `openai-php/client ^0.17` | `openai-php/client ^0.19` |

---

## Vibes Checklist

- [ ] `composer require "openai-php/client:^0.19.0"` — upgrade
- [ ] `make -C backend test && make -C backend phpstan` — no regressions
- [ ] Add `getApiType()` to `Model` entity
- [ ] Add `gpt-5.4-pro` to `ModelCatalog` with `'api' => 'responses'`
- [ ] Add `responsesChat()` to `OpenAIProvider`
- [ ] Add `responsesChatStream()` to `OpenAIProvider`
- [ ] Route in `chat()` / `chatStream()` based on `$options['api']`
- [ ] Wire `api` type in `ChatHandler`
- [ ] End-to-end test: GPT-5.4 Pro in web UI streams correctly
- [ ] Full quality gate: `make lint && make -C backend phpstan && make test`
