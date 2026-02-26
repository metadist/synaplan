# 06 — Test Strategy: MCP Integration

## Principles

1. **Every step has tests** — no step is "done" until tests pass
2. **Backend: PHPUnit** — unit tests for services, integration tests for API endpoints
3. **Frontend: Vitest** — component tests for new Vue components
4. **Fast feedback** — tests run in under 30s total
5. **No mocking production APIs** — use stubs and test doubles, not real AI/MCP calls

## Test Matrix

### 01 — Plugin Architecture

| Step | Test File | Type | What to Test |
|------|-----------|------|-------------|
| 1.1 | `tests/Integration/Plugin/PluginServiceTest.php` | Integration | Plugin loads, services registered |
| 1.2 | `tests/Unit/Service/PluginDataServiceTest.php` | Unit | Data stored/retrieved correctly |

### 02 — MCP Client: Enrichment (Pull)

| Step | Test File | Type | What to Test |
|------|-----------|------|-------------|
| 2.1 | `tests/Unit/Service/MCP/McpConfigServiceTest.php` | Unit | CRUD on server configs, validation |
| 2.2 | `tests/Unit/Service/MCP/McpClientTest.php` | Unit | Tool list, tool call, timeout, SSRF blocking |
| 2.3 | `tests/Unit/Service/MCP/McpToolRegistryTest.php` | Unit | Cache behavior, refresh, disabled servers |
| 2.4 | `tests/Unit/Service/PromptServiceTest.php` | Unit | Default metadata includes `tool_mcp` |
| 2.5 | `tests/Unit/Service/MCP/McpEnrichmentServiceTest.php` | Unit | Results formatted, injected into prompt |
| 2.5 | `tests/Unit/Service/Message/MessageProcessorTest.php` | Unit | MCP step runs when enabled, skipped when disabled |
| 2.6 | `tests/Integration/Repository/SearchResultRepositoryTest.php` | Integration | MCP results stored and retrieved |

### 03 — MCP Server: Push (Action)

| Step | Test File | Type | What to Test |
|------|-----------|------|-------------|
| 3.1 | `tests/Unit/Service/MCP/McpToolRegistryTest.php` | Unit | Converts MCP tools to OpenAI tools format |
| 3.2 | `tests/Unit/Service/MCP/McpActionServiceTest.php` | Unit | Intercepts tool calls, routes to correct server/tool |
| 3.3 | `tests/Unit/Service/MCP/McpActionServiceTest.php` | Unit | Sensitive actions pause for confirmation |
| 3.4 | `tests/Frontend/components/ChatMessage.test.ts` | Component | Displays confirmation card, handles approve/deny |

### 04 — UI/UX Design

| Step | Test File | Type | What to Test |
|------|-----------|------|-------------|
| 4.1 | `tests/Frontend/views/McpSettingsView.test.ts` | Component | Server list renders, add/edit modal works |
| 4.2 | `tests/Frontend/components/McpToolBrowser.test.ts` | Component | Tools list renders, search works |
| 4.3 | `tests/Frontend/components/McpPromptMapper.test.ts` | Component | Maps tools to prompts correctly |

### 05 — Enrichment UI & Logging

| Step | Test File | Type | What to Test |
|------|-----------|------|-------------|
| 5.1 | `tests/Unit/DTO/EnrichmentResultTest.php` | Unit | Serialization, all fields |
| 5.2 | `tests/Unit/Service/Message/MessageProcessorTest.php` | Unit | SSE events emitted for each enrichment source |
| 5.3 | `tests/Frontend/components/MessageMcpResults.test.ts` | Component | Renders results, collapses, empty state |
| 5.4 | `tests/Frontend/components/MessageEnrichedPrompt.test.ts` | Component | Shows prompt in debug mode, hidden otherwise |
| 5.5 | `tests/Unit/Service/Message/MessageProcessorTest.php` | Unit | Structured log output matches expected format |

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
```
