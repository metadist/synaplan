# Synaplan Architecture (Simplified)

## System Overview

```
┌─────────────┐     ┌──────────────┐     ┌─────────────┐
│  Vue.js UI  │────▶│  Symfony API │────▶│  MariaDB    │
└─────────────┘     └──────────────┘     └─────────────┘
                           │
                           ├──▶ Redis (Cache + Queues)
                           │
                           └──▶ AI Providers
                                 ├─ Anthropic
                                 ├─ OpenAI
                                 ├─ Ollama
                                 └─ TestProvider
```

## Request Flow

### Sync Request (Fast)
```
POST /api/v1/auth/login
  → Controller validates
  → UserRepository finds user
  → JWT generated
  → Return token (< 300ms)
```

### Async Request (AI Processing)
```
POST /api/v1/messages
  → Controller accepts (202)
  → Save to DB
  → Dispatch to queue
  → Return tracking_id

[Queue Worker]
  → PreProcess (file extract if needed)
  → Classify (topic, language, intent)
  → Route to handler
  → Call AI provider
  → Save response
  → Update status
```

## Core Components

### Controllers (Thin)
- `AuthController`: Login, register, verify, password reset
- `MessageController`: Create, get, stream
- `FileController`: Upload, analyze
- `HealthController`: System status

### Services (Business Logic)
- `MessagePreProcessor`: Download files, extract text (Tika/OCR)
- `MessageClassifier`: Detect topic/language/intent using AI
- `InferenceRouter`: Route to correct handler
- `MessageHandlerInterface`: Chat, Tools, Analyze implementations
- `ModelConfigService`: Get default models per user
- `RateLimiterService`: Check user limits

### AI System
```php
AiFacade
  → ProviderRegistry
      → Provider (Anthropic|OpenAI|Ollama|Test)
          → HttpClient (API calls)
```

**Interfaces**: Chat, Vision, Embedding, ImageGen, STT, TTS, FileAnalysis, Metadata

**Provider Selection**:
- Default from .env: `AI_DEFAULT_PROVIDER=anthropic`
- Override per request: `['provider' => 'openai']`
- Fallback chain: Primary → Secondary → Local

### Messenger (Async)
```yaml
Transports:
  - async_ai_high    # Message processing
  - async_extract    # File extraction
  - async_index      # Vector indexing

Commands:
  - ProcessMessageCommand
  - ExtractFileCommand
  - IndexDocumentCommand
```

**Worker**: `php bin/console messenger:consume async_ai_high -vv`

## Database Schema (Key Tables)

```sql
BUSER              # Users + auth
BMESSAGES          # Chat messages
BMESSAGEMETA       # Additional message data
BPROMPTS           # System prompts
BMODELS            # AI models config
BCONFIG            # Key-value config
BRAG               # Vector embeddings (RAG)
BAPIKEYS           # API keys for external access
BSESSIONS          # Sessions
BTOKENS            # JWT/OAuth tokens
BUSELOG            # Usage tracking
BRATELIMITS_CONFIG # Rate limit rules
BVERIFICATION_TOKENS # Email verification + password reset
```

**Doctrine ORM**: Custom naming strategy (BUSER → User entity, BID → id)

## AI Provider Pattern

### Interface Example
```php
interface ChatProviderInterface
{
    public function simplePrompt(string $prompt, array $options = []): string;
    public function streamPrompt(string $prompt, callable $callback): void;
    public function chat(array $messages, array $options = []): string;
    // ...
}
```

### Provider Implementation
```php
class AnthropicProvider implements ChatProviderInterface
{
    public function simplePrompt(string $prompt, array $options = []): string
    {
        $response = $this->httpClient->request('POST', '/v1/messages', [
            'json' => [
                'model' => $options['model'] ?? 'claude-3-5-sonnet-20241022',
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'max_tokens' => $options['max_tokens'] ?? 1024,
            ],
            'headers' => ['x-api-key' => $this->apiKey],
        ]);
        
        return $this->parseResponse($response);
    }
}
```

### Registry + Facade
```php
// ProviderRegistry: Find provider by name/capability
$provider = $registry->getChatProvider('anthropic');

// AiFacade: Simple entry point
$response = $aiFacade->chat('Hello, how are you?');
```

## Message Processing Pipeline

```
┌──────────────┐
│ New Message  │
└──────┬───────┘
       │
       ▼
┌──────────────────┐
│ PreProcessor     │  • Download file (if any)
│                  │  • Extract text (Tika/OCR)
│                  │  • Detect file type
└──────┬───────────┘
       │
       ▼
┌──────────────────┐
│ Classifier       │  • Topic (CHAT/TOOLS/ANALYZE)
│                  │  • Language (en/de/fr/...)
│                  │  • Intent
└──────┬───────────┘
       │
       ▼
┌──────────────────┐
│ Router           │  • Select handler
│                  │  • Get context (RAG search)
└──────┬───────────┘
       │
       ▼
┌──────────────────┐
│ Handler          │  • ChatHandler
│ (Strategy)       │  • ToolHandler (later)
│                  │  • AnalyzeHandler (later)
└──────┬───────────┘
       │
       ▼
┌──────────────────┐
│ AI Provider      │  • Call Anthropic/OpenAI/Ollama
│                  │  • Streaming or batch
│                  │  • Retry on failure
└──────┬───────────┘
       │
       ▼
┌──────────────────┐
│ PostProcessor    │  • Format response
│                  │  • Save to DB
│                  │  • Update status
└──────────────────┘
```

## Security

### Authentication
- JWT tokens (24h expiry)
- Email verification required
- Password reset via token
- Refresh token endpoint

### Authorization
- User levels: NEW, PRO, TEAM, BUSINESS
- Rate limits per level
- API keys for external access

### Validation
- Symfony Validator on DTOs
- Email format + password rules
- Input sanitization

## Testing Strategy

### Unit Tests
```php
// Mock HTTP client for providers
$mockClient = $this->createMock(HttpClientInterface::class);
$provider = new AnthropicProvider($mockClient);

// Test with TestProvider
$testProvider = new TestProvider();
$response = $testProvider->simplePrompt('Hello');
```

### Integration Tests
```php
// Test with real DB
$message = new Message();
$this->em->persist($message);
$this->em->flush();
```

### Contract Tests
```php
abstract class ChatProviderContractTest
{
    abstract protected function getProvider(): ChatProviderInterface;
    
    public function testSimplePrompt(): void
    {
        $result = $this->getProvider()->simplePrompt('Test');
        $this->assertIsString($result);
    }
}
```

## Configuration

### Service Tags
```yaml
# Tag providers by capability
App\AI\Provider\AnthropicProvider:
    tags:
        - { name: app.ai.chat }
        - { name: app.ai.vision }

# Inject via tagged iterator
App\AI\Service\ProviderRegistry:
    arguments:
        $chatProviders: !tagged_iterator app.ai.chat
```

### Rate Limiting
```yaml
framework:
    rate_limiter:
        api_user:
            policy: token_bucket
            limit: 100
            rate: { interval: '1 minute' }
```

## Deployment

### Docker
```dockerfile
FROM php:8.3-fpm-alpine
RUN apk add --no-cache postgresql-dev
COPY . /var/www
CMD ["php-fpm"]
```

### Docker Compose
```yaml
services:
  app:
    build: .
    volumes:
      - .:/var/www
  mariadb:
    image: mariadb:11.8
  redis:
    image: redis:7-alpine
```

### Production Checklist
- [ ] .env configured (no secrets in git)
- [ ] JWT keys generated
- [ ] SSL certificate installed
- [ ] Database migrations run
- [ ] Workers running (systemd/supervisor)
- [ ] Logs rotated
- [ ] Backups automated

## Performance

### Targets
- Login: < 300ms (P95)
- API CRUD: < 500ms (P95)
- AI Response: Async (no timeout)

### Optimization
- Redis cache for configs
- Database indexes on user_id, tracking_id
- Eager loading (avoid N+1)
- Queue workers scaled by load

---

**Keep it simple. One service, one database, scale when needed.**

