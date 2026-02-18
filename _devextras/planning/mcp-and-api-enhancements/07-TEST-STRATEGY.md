# 07 — Test Strategy

## Principles

1. **Every step has tests** — no step is "done" until tests pass
2. **Backend: PHPUnit** — unit tests for services, integration tests for API endpoints
3. **Frontend: Vitest** — component tests for new Vue components
4. **Fast feedback** — tests run in under 30s total
5. **No mocking production APIs** — use stubs and test doubles, not real AI/MCP calls

## Test Matrix

### 01 — OpenAI API Compatible

| Step | Test File | Type | What to Test |
|------|-----------|------|-------------|
| 1.1 | `tests/Unit/Service/EncryptedConfigServiceTest.php` | Unit | Encrypt/decrypt roundtrip, key derivation, corrupted data handling |
| 1.1 | `tests/Unit/Service/ModelConfigServiceTest.php` | Unit | `getUserApiKey()` returns user key, falls back to platform key |
| 1.2 | `tests/Unit/AI/Provider/OpenAIProviderTest.php` | Unit | Custom base URL used in factory, default URL fallback |
| 1.3 | `tests/Unit/AI/Service/ProviderRegistryTest.php` | Unit | `getProviderForUser()` returns user-specific provider, fallback to shared |
| 1.4 | `tests/Integration/Controller/ConfigControllerTest.php` | Integration | `POST /api/v1/config/test-provider` validates connection |
| 1.5 | `tests/Integration/OpenApiTest.php` | Integration | New endpoint appears in `/api/doc.json` |

### 02 — MCP Prompt Enrichment

| Step | Test File | Type | What to Test |
|------|-----------|------|-------------|
| 2.1 | `tests/Unit/Service/MCP/McpConfigServiceTest.php` | Unit | CRUD on server configs, validation |
| 2.2 | `tests/Unit/Service/MCP/McpClientTest.php` | Unit | Tool list, tool call, timeout, SSRF blocking |
| 2.3 | `tests/Unit/Service/MCP/McpToolRegistryTest.php` | Unit | Cache behavior, refresh, disabled servers |
| 2.4 | `tests/Unit/Service/PromptServiceTest.php` | Unit | Default metadata includes `tool_mcp` |
| 2.5 | `tests/Unit/Service/MCP/McpEnrichmentServiceTest.php` | Unit | Results formatted, injected into prompt |
| 2.5 | `tests/Unit/Service/Message/MessageProcessorTest.php` | Unit | MCP step runs when enabled, skipped when disabled |
| 2.6 | `tests/Integration/Repository/SearchResultRepositoryTest.php` | Integration | MCP results stored and retrieved |

### 03 — URL Screenshot Audit

| Step | Test File | Type | What to Test |
|------|-----------|------|-------------|
| 3.1 | `tests/Unit/Service/PromptServiceTest.php` | Unit | `tool_url_screenshot` in defaults, no `tool_screenshot` |
| 3.2 | `tests/Unit/Service/UrlScreenshotServiceTest.php` | Unit | URL fetch, text extraction, timeout, SSRF block |
| 3.3 | `tests/Unit/Service/Message/MessageProcessorTest.php` | Unit | URL regex extracts valid URLs |
| 3.4 | `tests/Unit/Service/Message/MessageProcessorTest.php` | Unit | URL results in prompt when enabled, not when disabled |
| 3.5 | `tests/Frontend/components/MessageScreenshot.test.ts` | Component | Renders with text-only data, handles missing image |

### 04 — Enrichment UI & Logging

| Step | Test File | Type | What to Test |
|------|-----------|------|-------------|
| 4.1 | `tests/Unit/DTO/EnrichmentResultTest.php` | Unit | Serialization, all fields |
| 4.2 | `tests/Unit/Service/Message/MessageProcessorTest.php` | Unit | SSE events emitted for each enrichment source |
| 4.3 | `tests/Frontend/components/MessageMcpResults.test.ts` | Component | Renders results, collapses, empty state |
| 4.4 | `tests/Frontend/components/MessageEnrichedPrompt.test.ts` | Component | Shows prompt in debug mode, hidden otherwise |
| 4.5 | `tests/Unit/Service/Message/MessageProcessorTest.php` | Unit | Structured log output matches expected format |

### 05 — API Custom Prompts

| Step | Test File | Type | What to Test |
|------|-----------|------|-------------|
| 5.1 | `tests/Integration/Controller/StreamControllerTest.php` | Integration | `promptTopic` param accepted, correct prompt loaded |
| 5.2 | `tests/Integration/Controller/StreamControllerTest.php` | Integration | `promptId` param accepted, ownership validated |
| 5.3 | `tests/Integration/Controller/PromptControllerTest.php` | Integration | Scope enforcement: no scope → 403, correct scope → 200 |
| 5.4 | `tests/Integration/OpenApiTest.php` | Integration | New params in spec |

### 06 — BONUS: MCP Server

| Step | Test File | Type | What to Test |
|------|-----------|------|-------------|
| 6.1 | `tests/Integration/Controller/McpServerControllerTest.php` | Integration | SSE connection, auth required |
| 6.2 | `tests/Unit/Service/MCP/McpToolCatalogTest.php` | Unit | Tools from allowlist, schema generation |
| 6.3 | `tests/Unit/Service/MCP/McpToolInvokerTest.php` | Unit | Routes to correct controller, validates args |
| 6.4 | `tests/Unit/Service/MCP/McpServerServiceTest.php` | Unit | JSON-RPC handling for each method |
| 6.5 | `tests/Unit/Service/MCP/McpToolCatalogTest.php` | Unit | Plugin tools included when installed |
| 6.6 | `tests/Integration/Controller/McpServerControllerTest.php` | Integration | Calls logged in BUSELOG |

## Test Patterns

### Unit Test Template (PHPUnit)

```php
final class McpClientTest extends TestCase
{
    private McpClient $client;
    private MockObject&HttpClientInterface $httpClient;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->client = new McpClient(
            $this->httpClient,
            new NullLogger(),
        );
    }

    public function testListToolsReturnsToolArray(): void
    {
        $this->httpClient->method('request')->willReturn(/* mock response */);
        $tools = $this->client->listTools($serverConfig);
        $this->assertIsArray($tools);
        $this->assertNotEmpty($tools);
    }

    public function testCallToolTimesOut(): void
    {
        $this->httpClient->method('request')->willThrowException(
            new TransportException('Timeout')
        );
        $this->expectException(McpTimeoutException::class);
        $this->client->callTool($serverConfig, 'search', []);
    }
}
```

### Frontend Component Test Template (Vitest)

```typescript
import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'
import MessageMcpResults from '@/components/MessageMcpResults.vue'

describe('MessageMcpResults', () => {
  it('renders server name and result count', () => {
    const wrapper = mount(MessageMcpResults, {
      props: {
        results: [{
          source: 'mcp',
          label: 'My CRM',
          items: [{ title: 'Contact', content: 'John Doe' }],
        }],
      },
    })
    expect(wrapper.text()).toContain('My CRM')
    expect(wrapper.text()).toContain('1')
  })

  it('handles empty results gracefully', () => {
    const wrapper = mount(MessageMcpResults, {
      props: { results: [] },
    })
    expect(wrapper.find('.mcp-results').exists()).toBe(false)
  })
})
```

## Running Tests

```bash
# All backend tests
make -C backend test

# Specific test file
docker compose exec backend php bin/phpunit tests/Unit/Service/MCP/McpClientTest.php

# All frontend tests
make -C frontend test

# Specific frontend test
cd frontend && npx vitest run src/components/__tests__/MessageMcpResults.test.ts

# Full pre-commit gate (as always)
make lint && make -C backend phpstan && make test
```

## Definition of Done

A step is **done** when:
1. Code compiles without errors
2. `make -C backend lint` passes
3. `make -C backend phpstan` passes
4. Step-specific tests pass
5. `make -C frontend lint` passes (if frontend changed)
6. No regressions in existing tests
