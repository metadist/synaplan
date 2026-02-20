# 01 — OpenAI API Compatible: User Keys + Generic Endpoints

## Goal

Users can bring their own API key and point Synaplan at **any** OpenAI-compatible endpoint (OpenAI, Azure OpenAI, OpenRouter, LM Studio, LocalAI, vLLM, etc.) for chat, image generation, and other capabilities.

## Current State

- `OpenAIProvider` takes a single `$apiKey` from env (`OPENAI_API_KEY`).
- Uses `\OpenAI::client($apiKey)` — the `openai-php/client` library.
- No custom base URL support.
- No per-user key storage.
- All users share the platform's API key.

## What Changes

### Step 1.1 — Per-User API Key Storage

**Where:** `BCONFIG` table, `ModelConfigService`

Store user-provided keys in `BCONFIG`:

| BOWNERID | BGROUP | BSETTING | BVALUE |
|----------|--------|----------|--------|
| 42 | `ai_keys` | `openai_api_key` | `sk-user-...` |
| 42 | `ai_keys` | `openai_base_url` | `https://api.openai.com/v1` |
| 42 | `ai_keys` | `custom_openai_name` | `My Local LLM` |

- **Encryption:** API keys must be encrypted at rest. Add an `EncryptedConfigService` wrapper that encrypts before write and decrypts on read. Use `sodium_crypto_secretbox` with `APP_SECRET` as key material.
- **Fallback:** If user has no key → fall back to platform key (existing behavior).

**Files to change:**
- `backend/src/Service/ModelConfigService.php` — add `getUserApiKey(int $userId, string $provider): ?string`
- New: `backend/src/Service/EncryptedConfigService.php`

**Test:** Unit test that encrypted value in BCONFIG decrypts correctly. Test fallback to platform key.

### Step 1.2 — Custom Base URL Support in OpenAI Provider

**Where:** `OpenAIProvider.php`

The `openai-php/client` library supports custom base URLs:

```php
$client = \OpenAI::factory()
    ->withApiKey($apiKey)
    ->withBaseUri($baseUrl)  // e.g. 'https://my-local-llm:8080/v1'
    ->make();
```

Change the provider to accept `$baseUrl` as constructor param + per-call override via `$options['base_url']`.

**Files to change:**
- `backend/src/AI/Provider/OpenAIProvider.php` — accept `$baseUrl`, use factory pattern
- `backend/config/services.yaml` — wire env var `OPENAI_BASE_URL`

**Test:** Unit test that provider uses custom base URL. Mock HTTP to verify correct endpoint called.

### Step 1.3 — Per-User Provider Resolution

**Where:** `ProviderRegistry.php`, `AiFacade.php`

When resolving a provider for a user:
1. Check if user has a custom API key for the requested provider
2. If yes → create a **per-request provider instance** with user's key + base URL
3. If no → use the platform's shared provider instance

Add to `ProviderRegistry`:
```php
public function getProviderForUser(string $name, ?int $userId): ChatProviderInterface
```

This method clones the base provider and injects user-specific credentials.

**Files to change:**
- `backend/src/AI/Service/ProviderRegistry.php` — add `getProviderForUser()`
- `backend/src/AI/Service/AiFacade.php` — pass userId through to registry

**Test:** Integration test: user with custom key gets a provider with that key. User without gets platform provider.

### Step 1.4 — Frontend: API Key Configuration UI

**Where:** AI Models Configuration page

Add a section "Your API Keys" in the AI configuration page:

- Input field for API key (masked, with show/hide toggle)
- Input field for custom base URL (optional, placeholder: `https://api.openai.com/v1`)
- Input field for display name (optional)
- "Test Connection" button → calls a lightweight `/api/v1/config/test-provider` endpoint
- Save/delete buttons

**Files to change:**
- New section in `frontend/src/components/config/AIModelsConfiguration.vue`
- New endpoint: `backend/src/Controller/ConfigController.php` — `POST /api/v1/config/test-provider`
- i18n: `en.json` + `de.json`

**Test:** Frontend: component renders, saves key. Backend: test-provider endpoint validates connection.

### Step 1.5 — OpenAPI Annotations for New Endpoints

Add `#[OA\...]` annotations to the `test-provider` endpoint and document the config keys.

**Test:** `GET /api/doc.json` includes the new endpoint.

## Implementation Order

```
1.1 (storage) → 1.2 (provider) → 1.3 (resolution) → 1.4 (UI) → 1.5 (docs)
```

Each step is independently testable. Don't start 1.3 until 1.1 + 1.2 pass tests.

## Security Considerations

- Keys encrypted at rest (Step 1.1)
- Keys never returned in API responses (masked: `sk-...xxxx`)
- Keys never logged (redact in logger)
- Per-user rate limiting still applies
- Circuit breaker protects against bad endpoints
