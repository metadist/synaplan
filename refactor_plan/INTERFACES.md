# Synaplan AI Provider Interfaces – ADR (Architecture Decision Record)

## Status
**Accepted** – 2025-10-10

## Kontext

Das bestehende System nutzt statische Klassen (`AIOpenAI::sortingPrompt()`, `AIGroq::topicPrompt()`) mit direkter Abhängigkeit zu $GLOBALS und hard-coded Provider-Logik. Dies verhindert:
- Testing ohne Token-Verbrauch
- Dynamische Provider-Auswahl zur Laufzeit
- Dependency Injection
- Mocking in Unit-Tests

## Entscheidung

Wir definieren **8 Provider-Interfaces** mit klaren Verträgen für alle AI-Capabilities. Jeder Provider implementiert die relevanten Interfaces. Ein **ProviderRegistry** verwaltet alle Provider als Tagged Services, und ein **AiFacade** bietet einen Single Entry Point.

## Interface-Definitionen

### 1. ChatProviderInterface

**Zweck**: Text-Generierung, Konversation, Prompts

```php
<?php

namespace App\AI\Interface;

use App\AI\DTO\ChatRequest;
use App\AI\DTO\ChatResponse;

interface ChatProviderInterface extends ProviderMetadataInterface
{
    /**
     * Sorting Prompt: Klassifiziert User-Intent (CHAT, TOOLS, ANALYZE, etc.)
     * 
     * @param array $message  User-Message mit BTEXT, BUSERID, etc.
     * @param array $thread   Conversation-History
     * @return array ['BTOPIC' => 'CHAT'|'TOOLS'|'ANALYZE', 'BLANG' => 'en', ...]
     * @throws ProviderException Bei API-Fehler
     */
    public function sortingPrompt(array $message, array $thread): array;

    /**
     * Topic Prompt: Generiert AI-Antwort basierend auf Topic (non-streaming)
     * 
     * @param array $message  User-Message
     * @param array $thread   Conversation-History
     * @return string AI-Response Text
     * @throws ProviderException
     */
    public function topicPrompt(array $message, array $thread): string;

    /**
     * Topic Prompt (Streaming): Generiert AI-Antwort mit Server-Sent Events
     * 
     * @param array $message
     * @param array $thread
     * @param callable $callback  function(string $chunk): void
     * @return void Gibt nichts zurück, streamt via callback
     * @throws ProviderException
     */
    public function topicPromptStream(array $message, array $thread, callable $callback): void;

    /**
     * Summarize: Fasst langen Text zusammen
     * 
     * @param string $text  Input-Text (max 100k chars)
     * @param array $options ['max_length' => 500, 'language' => 'de']
     * @return string Zusammenfassung
     */
    public function summarize(string $text, array $options = []): string;

    /**
     * Translate: Übersetzt Text
     * 
     * @param string $text
     * @param string $sourceLang  ISO 639-1 (en, de, fr, ...)
     * @param string $targetLang
     * @return string Übersetzter Text
     */
    public function translate(string $text, string $sourceLang, string $targetLang): string;

    /**
     * Simple Prompt: Einfacher Prompt ohne Context
     * 
     * @param string $prompt
     * @param array $options  ['temperature' => 0.7, 'max_tokens' => 1000]
     * @return string
     */
    public function simplePrompt(string $prompt, array $options = []): string;
}
```

### 2. VisionProviderInterface

**Zweck**: Bild-Analyse, OCR, Visual Q&A

```php
<?php

namespace App\AI\Interface;

interface VisionProviderInterface extends ProviderMetadataInterface
{
    /**
     * Erklärt Bild-Inhalt
     * 
     * @param string $imageUrl  URL oder Base64-Data-URI
     * @param string $prompt    Optional: "Describe this image in detail"
     * @param array $options    ['language' => 'de', 'detail' => 'high']
     * @return string Beschreibung
     * @throws ProviderException
     */
    public function explainImage(string $imageUrl, string $prompt = '', array $options = []): string;

    /**
     * OCR: Extrahiert Text aus Bild
     * 
     * @param string $imageUrl
     * @return string Extrahierter Text
     */
    public function extractTextFromImage(string $imageUrl): string;

    /**
     * Vergleicht zwei Bilder
     * 
     * @param string $imageUrl1
     * @param string $imageUrl2
     * @return array ['similarity' => 0.95, 'differences' => '...']
     */
    public function compareImages(string $imageUrl1, string $imageUrl2): array;
}
```

### 3. ImageGenerationProviderInterface

**Zweck**: Text-to-Image, Image-to-Image

```php
<?php

namespace App\AI\Interface;

interface ImageGenerationProviderInterface extends ProviderMetadataInterface
{
    /**
     * Generiert Bild aus Text-Prompt
     * 
     * @param string $prompt
     * @param array $options  [
     *     'size' => '1024x1024',
     *     'style' => 'vivid'|'natural',
     *     'quality' => 'standard'|'hd',
     *     'n' => 1  // Anzahl Bilder
     * ]
     * @return array [['url' => 'https://...', 'revised_prompt' => '...']]
     * @throws ProviderException
     */
    public function generateImage(string $prompt, array $options = []): array;

    /**
     * Erstellt Variationen eines Bildes
     * 
     * @param string $imageUrl  Basis-Bild
     * @param int $count        Anzahl Variationen
     * @return array URLs der generierten Bilder
     */
    public function createVariations(string $imageUrl, int $count = 1): array;

    /**
     * Image Editing mit Mask
     * 
     * @param string $imageUrl   Original
     * @param string $maskUrl    Maske (transparent = edit)
     * @param string $prompt     Was soll geändert werden
     * @return string URL des editierten Bildes
     */
    public function editImage(string $imageUrl, string $maskUrl, string $prompt): string;
}
```

### 4. EmbeddingProviderInterface

**Zweck**: Text → Vector Embeddings für RAG

```php
<?php

namespace App\AI\Interface;

interface EmbeddingProviderInterface extends ProviderMetadataInterface
{
    /**
     * Generiert Vector-Embedding für Text
     * 
     * @param string $text
     * @return array Float-Array [0.123, -0.456, ...] (Dimensionen: 768, 1536, ...)
     * @throws ProviderException
     */
    public function embed(string $text): array;

    /**
     * Batch-Embedding für mehrere Texte
     * 
     * @param array $texts  ['text1', 'text2', ...]
     * @return array [embedding1[], embedding2[], ...]
     */
    public function embedBatch(array $texts): array;

    /**
     * Embedding-Dimensionen
     * 
     * @return int  z.B. 1536 für OpenAI text-embedding-3-small
     */
    public function getDimensions(): int;
}
```

### 5. SpeechToTextProviderInterface

**Zweck**: Audio → Text (Transkription)

```php
<?php

namespace App\AI\Interface;

interface SpeechToTextProviderInterface extends ProviderMetadataInterface
{
    /**
     * Transkribiert Audio-Datei
     * 
     * @param string $audioPath  Lokaler Pfad zu MP3/WAV/OGG/...
     * @param array $options     [
     *     'language' => 'de',  // Optional: Auto-Detect
     *     'prompt' => '...',   // Context für besseres Ergebnis
     *     'temperature' => 0.0
     * ]
     * @return array [
     *     'text' => 'Transkribierter Text',
     *     'language' => 'de',
     *     'duration' => 123.45,  // Sekunden
     *     'segments' => [...]    // Optional: Timestamps
     * ]
     * @throws ProviderException
     */
    public function transcribe(string $audioPath, array $options = []): array;

    /**
     * Übersetzt Audio direkt zu Text in anderer Sprache
     * 
     * @param string $audioPath
     * @param string $targetLang
     * @return string Übersetzter Text
     */
    public function translateAudio(string $audioPath, string $targetLang): string;
}
```

### 6. TextToSpeechProviderInterface

**Zweck**: Text → Audio (Speech Synthesis)

```php
<?php

namespace App\AI\Interface;

interface TextToSpeechProviderInterface extends ProviderMetadataInterface
{
    /**
     * Generiert Audio aus Text
     * 
     * @param string $text
     * @param array $options  [
     *     'voice' => 'alloy'|'echo'|'fable'|...,
     *     'model' => 'tts-1'|'tts-1-hd',
     *     'speed' => 1.0  // 0.25 - 4.0
     * ]
     * @return string Pfad zur generierten Audio-Datei (MP3)
     * @throws ProviderException
     */
    public function synthesize(string $text, array $options = []): string;

    /**
     * Liste verfügbarer Stimmen
     * 
     * @return array [['id' => 'alloy', 'name' => 'Alloy', 'language' => 'en'], ...]
     */
    public function getVoices(): array;
}
```

### 7. FileAnalysisProviderInterface

**Zweck**: Datei-Analyse (PDF, DOCX, etc.)

```php
<?php

namespace App\AI\Interface;

interface FileAnalysisProviderInterface extends ProviderMetadataInterface
{
    /**
     * Analysiert Datei-Inhalt und gibt Zusammenfassung
     * 
     * @param string $filePath   Lokaler Pfad
     * @param string $fileType   pdf, docx, xlsx, pptx, ...
     * @param array $options     ['extract_tables' => true, 'ocr' => true]
     * @return array [
     *     'text' => 'Extrahierter Text',
     *     'summary' => 'Zusammenfassung',
     *     'metadata' => ['pages' => 5, 'author' => '...'],
     *     'tables' => [[...]]  // Optional
     * ]
     * @throws ProviderException
     */
    public function analyzeFile(string $filePath, string $fileType, array $options = []): array;

    /**
     * Beantwortet Fragen über Datei-Inhalt
     * 
     * @param string $filePath
     * @param string $question
     * @return string Antwort
     */
    public function askAboutFile(string $filePath, string $question): string;
}
```

### 8. ProviderMetadataInterface

**Zweck**: Basis-Interface für alle Provider

```php
<?php

namespace App\AI\Interface;

interface ProviderMetadataInterface
{
    /**
     * Provider-Name
     * 
     * @return string  'anthropic', 'openai', 'ollama', 'test'
     */
    public function getName(): string;

    /**
     * Unterstützte Capabilities
     * 
     * @return array ['chat', 'vision', 'embedding', ...]
     */
    public function getCapabilities(): array;

    /**
     * Default-Modelle pro Capability
     * 
     * @return array [
     *     'chat' => 'claude-3-5-sonnet-20241022',
     *     'vision' => 'claude-3-5-sonnet-20241022',
     *     ...
     * ]
     */
    public function getDefaultModels(): array;

    /**
     * Provider-Status (Health-Check)
     * 
     * @return array [
     *     'healthy' => true,
     *     'latency_ms' => 234,
     *     'error_rate' => 0.01,
     *     'active_connections' => 5
     * ]
     */
    public function getStatus(): array;

    /**
     * Provider ist verfügbar?
     * 
     * @return bool
     */
    public function isAvailable(): bool;
}
```

## ProviderRegistry (Tagged Services)

```php
<?php

namespace App\AI\Service;

use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\VisionProviderInterface;
// ... weitere Interfaces

class ProviderRegistry
{
    private array $chatProviders = [];
    private array $visionProviders = [];
    private array $embeddingProviders = [];
    // ... weitere

    public function __construct(
        iterable $chatProviders,
        iterable $visionProviders,
        iterable $embeddingProviders,
        // ... via Tagged Services injected
    ) {
        $this->chatProviders = iterator_to_array($chatProviders);
        $this->visionProviders = iterator_to_array($visionProviders);
        $this->embeddingProviders = iterator_to_array($embeddingProviders);
    }

    /**
     * Gibt Chat-Provider zurück (Default oder spezifisch)
     * 
     * @param string|null $name  'anthropic', 'openai', null = default aus .env
     * @return ChatProviderInterface
     * @throws ProviderNotFoundException
     */
    public function getChatProvider(?string $name = null): ChatProviderInterface
    {
        $name = $name ?? $_ENV['AI_DEFAULT_PROVIDER'] ?? 'anthropic';
        
        foreach ($this->chatProviders as $provider) {
            if ($provider->getName() === $name && $provider->isAvailable()) {
                return $provider;
            }
        }
        
        throw new ProviderNotFoundException("Chat provider '$name' not found or unavailable");
    }

    // Analog für andere Provider-Typen...
    
    public function getVisionProvider(?string $name = null): VisionProviderInterface { /* ... */ }
    public function getEmbeddingProvider(?string $name = null): EmbeddingProviderInterface { /* ... */ }
    // ...
}
```

**Fallback-Chain per .env**:
```env
# .env
AI_DEFAULT_PROVIDER=anthropic
AI_FALLBACK_CHAIN=anthropic,openai,ollama
AI_FALLBACK_TIMEOUT=60  # Sekunden bis Fallback
```

**Services Config**:
```yaml
# config/services.yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    # Provider-Registry
    App\AI\Service\ProviderRegistry:
        arguments:
            $chatProviders: !tagged_iterator app.ai.chat
            $visionProviders: !tagged_iterator app.ai.vision
            $embeddingProviders: !tagged_iterator app.ai.embedding
            $defaultProvider: '%env(AI_DEFAULT_PROVIDER)%'
            $fallbackChain: '%env(csv:AI_FALLBACK_CHAIN)%'
            # ...

    # Anthropic Provider
    App\AI\Provider\AnthropicProvider:
        tags:
            - { name: app.ai.chat }
            - { name: app.ai.vision }

    # OpenAI Provider
    App\AI\Provider\OpenAIProvider:
        tags:
            - { name: app.ai.chat }
            - { name: app.ai.vision }
            - { name: app.ai.embedding }
            - { name: app.ai.image_generation }
            - { name: app.ai.speech_to_text }
            - { name: app.ai.text_to_speech }

    # Ollama Provider
    App\AI\Provider\OllamaProvider:
        tags:
            - { name: app.ai.chat }
            - { name: app.ai.embedding }

    # TestProvider (Fake für alle Capabilities)
    App\AI\Provider\TestProvider:
        tags:
            - { name: app.ai.chat }
            - { name: app.ai.vision }
            - { name: app.ai.embedding }
            - { name: app.ai.image_generation }
            - { name: app.ai.speech_to_text }
            - { name: app.ai.text_to_speech }
            - { name: app.ai.file_analysis }
```

## AiFacade (Single Entry Point)

```php
<?php

namespace App\AI\Service;

use App\AI\Exception\ProviderException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiFacade
{
    public function __construct(
        private ProviderRegistry $registry,
        private RateLimiterFactory $aiLimiter,
        private CircuitBreaker $circuitBreaker
    ) {}

    /**
     * Chat: Einfacher Prompt
     */
    public function chat(string $prompt, array $options = []): string
    {
        $provider = $this->registry->getChatProvider($options['provider'] ?? null);
        
        // Rate Limiting
        $limiter = $this->aiLimiter->create($provider->getName());
        if (!$limiter->consume(1)->isAccepted()) {
            throw new RateLimitException("Rate limit exceeded for {$provider->getName()}");
        }
        
        // Circuit Breaker mit Fallback-Chain
        return $this->circuitBreaker->execute(
            fn() => $provider->simplePrompt($prompt, $options),
            fallback: function() use ($prompt, $options) {
                // Fallback-Chain: Anthropic → OpenAI → Ollama
                $fallbacks = ['openai', 'ollama'];
                foreach ($fallbacks as $fallbackName) {
                    try {
                        $fallbackProvider = $this->registry->getChatProvider($fallbackName);
                        return $fallbackProvider->simplePrompt($prompt, $options);
                    } catch (\Exception $e) {
                        continue;
                    }
                }
                throw new AllProvidersFailedException();
            }
        );
    }

    /**
     * Vision: Bild analysieren
     */
    public function analyzeImage(string $imageUrl, string $prompt = ''): string
    {
        $provider = $this->registry->getVisionProvider();
        return $this->circuitBreaker->execute(
            fn() => $provider->explainImage($imageUrl, $prompt)
        );
    }

    /**
     * Embedding: Text → Vector
     */
    public function embed(string $text): array
    {
        $provider = $this->registry->getEmbeddingProvider();
        return $provider->embed($text);
    }

    // Weitere Convenience-Methoden...
}
```

## Fehler- & Streaming-Policy

### Exception-Hierarchie

```php
namespace App\AI\Exception;

// Basis-Exception
class ProviderException extends \RuntimeException
{
    public function __construct(
        string $message,
        private string $providerName,
        private ?array $context = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
    
    public function getProviderName(): string { return $this->providerName; }
    public function getContext(): ?array { return $this->context; }
}

// Spezifische Exceptions
class RateLimitException extends ProviderException {}
class TimeoutException extends ProviderException {}
class InvalidResponseException extends ProviderException {}
class ModelNotFoundException extends ProviderException {}
class QuotaExceededException extends ProviderException {}
```

### Streaming-Protokoll (Unified SSE Format)

**Server-Sent Events (SSE)** mit einheitlichem Event-Format:

```json
{
  "type": "content_delta",
  "seq": 42,
  "content": "Das ist ein Text-Chunk...",
  "finish_reason": null
}

{
  "type": "content_delta",
  "seq": 43,
  "content": "",
  "finish_reason": "stop"
}
```

**Implementierung**:
```php
public function topicPromptStream(array $message, array $thread, callable $callback): void
{
    $seq = 0;
    $maxChunkSize = 500;  // Max 500 chars per chunk
    
    foreach ($this->httpClient->stream($stream) as $chunk) {
        if ($chunk->isLast()) break;
        
        $text = $this->extractText($chunk);
        
        // Split oversized chunks
        foreach (str_split($text, $maxChunkSize) as $part) {
            $event = [
                'type' => 'content_delta',
                'seq' => ++$seq,
                'content' => $part,
                'finish_reason' => null
            ];
            $callback($event);
        }
    }
    
    // Final event
    $callback(['type' => 'content_delta', 'seq' => ++$seq, 'content' => '', 'finish_reason' => 'stop']);
}
```

**Constraints**: Max 500 chars/chunk, Seq-Nummern für Ordering, finish_reason: `stop|length|error|rate_limit`

**Client-Seite** (Controller):
```php
public function chatStream(Request $request): Response
{
    $response = new StreamedResponse();
    $response->headers->set('Content-Type', 'text/event-stream');
    $response->headers->set('Cache-Control', 'no-cache');
    $response->headers->set('X-Accel-Buffering', 'no');
    
    $response->setCallback(function() use ($request) {
        $provider = $this->aiFacade->getChatProvider();
        
        $provider->topicPromptStream($message, $thread, function(string $chunk) {
            echo "data: " . json_encode(['chunk' => $chunk]) . "\n\n";
            ob_flush();
            flush();
        });
        
        echo "data: [DONE]\n\n";
    });
    
    return $response;
}
```

## TestProvider (Fake Implementation)

**Zweck**: Ermöglicht Tests ohne Token-Verbrauch und externe API-Calls.

```php
<?php

namespace App\AI\Provider;

use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\VisionProviderInterface;
// ... alle anderen Interfaces

class TestProvider implements 
    ChatProviderInterface,
    VisionProviderInterface,
    EmbeddingProviderInterface,
    ImageGenerationProviderInterface,
    SpeechToTextProviderInterface,
    TextToSpeechProviderInterface,
    FileAnalysisProviderInterface
{
    public function getName(): string { return 'test'; }
    
    public function getCapabilities(): array
    {
        return ['chat', 'vision', 'embedding', 'image_generation', 
                'speech_to_text', 'text_to_speech', 'file_analysis'];
    }
    
    public function getDefaultModels(): array
    {
        return ['chat' => 'test-model', 'vision' => 'test-vision'];
    }
    
    public function isAvailable(): bool { return true; }
    
    public function getStatus(): array
    {
        return [
            'healthy' => true,
            'latency_ms' => 10,
            'error_rate' => 0.0,
            'active_connections' => 0
        ];
    }

    // ChatProviderInterface
    public function sortingPrompt(array $message, array $thread): array
    {
        return [
            'BTOPIC' => 'CHAT',
            'BLANG' => 'en',
            'BTEXT' => $message['BTEXT']
        ];
    }

    public function topicPrompt(array $message, array $thread): string
    {
        return "Test response to: " . $message['BTEXT'];
    }

    public function topicPromptStream(array $message, array $thread, callable $callback): void
    {
        $response = "Test response to: " . $message['BTEXT'];
        foreach (str_split($response, 10) as $chunk) {
            $callback($chunk);
            usleep(50000); // 50ms delay
        }
    }

    public function summarize(string $text, array $options = []): string
    {
        return "Summary of: " . substr($text, 0, 50) . "...";
    }

    public function translate(string $text, string $from, string $to): string
    {
        return "[Translated $from→$to] $text";
    }

    public function simplePrompt(string $prompt, array $options = []): string
    {
        return "Test response to: $prompt";
    }

    // VisionProviderInterface
    public function explainImage(string $url, string $prompt = '', array $options = []): string
    {
        return "Test image description: A test image at $url";
    }

    public function extractTextFromImage(string $url): string
    {
        return "Extracted text from test image";
    }

    public function compareImages(string $url1, string $url2): array
    {
        return ['similarity' => 0.95, 'differences' => 'Test comparison'];
    }

    // EmbeddingProviderInterface
    public function embed(string $text): array
    {
        // Fake 1536-dimensional embedding
        return array_fill(0, 1536, 0.123);
    }

    public function embedBatch(array $texts): array
    {
        return array_map(fn($t) => $this->embed($t), $texts);
    }

    public function getDimensions(): int { return 1536; }

    // ImageGenerationProviderInterface
    public function generateImage(string $prompt, array $options = []): array
    {
        return [[
            'url' => 'https://via.placeholder.com/1024x1024?text=Test+Image',
            'revised_prompt' => $prompt
        ]];
    }

    public function createVariations(string $url, int $count = 1): array
    {
        return array_fill(0, $count, 'https://via.placeholder.com/1024x1024');
    }

    public function editImage(string $url, string $mask, string $prompt): string
    {
        return 'https://via.placeholder.com/1024x1024?text=Edited';
    }

    // SpeechToTextProviderInterface
    public function transcribe(string $audioPath, array $options = []): array
    {
        return [
            'text' => 'Test transcription',
            'language' => 'en',
            'duration' => 10.0
        ];
    }

    public function translateAudio(string $audioPath, string $targetLang): string
    {
        return "Test audio translation to $targetLang";
    }

    // TextToSpeechProviderInterface
    public function synthesize(string $text, array $options = []): string
    {
        // Return path to silent MP3
        return '/tmp/test_audio.mp3';
    }

    public function getVoices(): array
    {
        return [['id' => 'test', 'name' => 'Test Voice', 'language' => 'en']];
    }

    // FileAnalysisProviderInterface
    public function analyzeFile(string $path, string $type, array $options = []): array
    {
        return [
            'text' => 'Test file content',
            'summary' => 'Test summary',
            'metadata' => ['pages' => 1]
        ];
    }

    public function askAboutFile(string $path, string $question): string
    {
        return "Test answer to: $question";
    }
}
```

**Aktivierung via .env**:
```env
AI_DEFAULT_PROVIDER=test
AI_TEST_ENABLED=true
```

## Contract-Tests

**Zweck**: Alle Provider müssen gleiche Output-Struktur liefern.

```php
namespace App\Tests\AI\Contract;

use App\AI\Interface\ChatProviderInterface;
use PHPUnit\Framework\TestCase;

abstract class ChatProviderContractTest extends TestCase
{
    abstract protected function getProvider(): ChatProviderInterface;

    public function testSortingPromptReturnsValidStructure(): void
    {
        $provider = $this->getProvider();
        $result = $provider->sortingPrompt(
            ['BTEXT' => 'Hello', 'BUSERID' => 1],
            []
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('BTOPIC', $result);
        $this->assertArrayHasKey('BLANG', $result);
        $this->assertContains($result['BTOPIC'], ['CHAT', 'TOOLS', 'ANALYZE']);
    }

    public function testTopicPromptReturnsString(): void
    {
        $provider = $this->getProvider();
        $result = $provider->topicPrompt(
            ['BTEXT' => 'What is AI?', 'BTOPIC' => 'CHAT'],
            []
        );

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }
}

// Test-Implementierungen für jeden Provider:
class AnthropicProviderContractTest extends ChatProviderContractTest
{
    protected function getProvider(): ChatProviderInterface
    {
        return new AnthropicProvider(/* ... */);
    }
}

class TestProviderContractTest extends ChatProviderContractTest
{
    protected function getProvider(): ChatProviderInterface
    {
        return new TestProvider();
    }
}
```

---

**Review-Cycle**: Interfaces bei Major-Changes reviewen
**Versioning**: Änderungen nur mit neuen Interface-Versionen (ChatProviderV2Interface)

