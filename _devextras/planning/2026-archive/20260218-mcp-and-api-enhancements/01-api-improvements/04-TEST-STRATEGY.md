# 04 — Test Strategy: API Improvements

## Principles

1. **No regressions** — existing tests must still pass
2. **Backend: PHPUnit** — unit tests for response formatting, integration tests for endpoints
3. **No mocking production APIs** — use stubs and test doubles

## Test Matrix

### 01 — OpenAI-Compatible API

| Step | Test File | Type | What to Test |
|------|-----------|------|-------------|
| 1.1 | `tests/Unit/Controller/OpenAICompatibleControllerTest.php` | Unit | Non-streaming: request parsing, response envelope matches OpenAI spec |
| 1.2 | `tests/Integration/Controller/OpenAICompatibleControllerTest.php` | Integration | Streaming: SSE format matches OpenAI (`data: {json}\n\n`, `data: [DONE]`) |
| 1.3 | `tests/Integration/Controller/OpenAICompatibleControllerTest.php` | Integration | `/v1/models` returns OpenAI list format |
| 1.4 | `tests/Unit/Security/ApiKeyAuthenticatorTest.php` | Unit | `Authorization: Bearer` header parsed correctly, `X-API-Key` still works |
| 1.5 | `tests/Unit/Controller/OpenAICompatibleControllerTest.php` | Unit | Error responses match OpenAI format |

### 02 — API Custom Prompts (DONE)

| Step | Test File | Type | What to Test |
|------|-----------|------|-------------|
| 2.1 | `tests/Integration/Controller/StreamControllerTest.php` | Integration | `promptTopic` accepted, correct prompt loaded |
| 2.2 | `tests/Integration/Controller/StreamControllerTest.php` | Integration | `promptId` resolves to topic, ownership validated |

### 03 — URL Content Fix (DONE)

| Step | Test File | Type | What to Test |
|------|-----------|------|-------------|
| 3.1 | `tests/Unit/Service/PromptServiceTest.php` | Unit | `tool_url_screenshot` in defaults |
| 3.2 | `tests/Unit/Service/UrlContentServiceTest.php` | Unit | URL fetch, text extraction, SSRF block |
| 3.4 | `tests/Unit/MessageProcessorTest.php` | Unit | URL results in prompt when enabled |

## Running Tests

```bash
# Full pre-commit gate
make lint && make -C backend phpstan && make test && docker compose exec -T frontend npm run check:types

# Just the new OpenAI-compatible endpoint tests
docker compose exec backend php bin/phpunit tests/Unit/Controller/OpenAICompatibleControllerTest.php
docker compose exec backend php bin/phpunit tests/Integration/Controller/OpenAICompatibleControllerTest.php
```
