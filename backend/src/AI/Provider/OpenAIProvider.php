<?php

namespace App\AI\Provider;

use App\AI\Credential\ProviderKeyStore;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\EmbeddingProviderInterface;
use App\AI\Interface\ImageGenerationProviderInterface;
use App\AI\Interface\SpeechToTextProviderInterface;
use App\AI\Interface\TextToSpeechProviderInterface;
use App\AI\Interface\ToolCallingChatProviderInterface;
use App\AI\Interface\VisionProviderInterface;
use App\AI\Provider\OpenAI\OpenAIApiClient;
use App\AI\Provider\OpenAI\OpenAIChat;
use App\AI\Provider\OpenAI\OpenAICurlTransport;
use App\AI\Provider\OpenAI\OpenAIEmbedding;
use App\AI\Provider\OpenAI\OpenAIImageGeneration;
use App\AI\Provider\OpenAI\OpenAIResponses;
use App\AI\Provider\OpenAI\OpenAISpeech;
use App\AI\Provider\OpenAI\OpenAIVision;
use App\AI\StructuredOutput\StructuredOutputCapability;
use App\AI\StructuredOutput\StructuredOutputTranslator;
use App\AI\Tool\CatalogToolUse;
use App\Service\Chat\ProviderTranscriptCapture;
use OpenAI;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAIProvider implements ChatProviderInterface, ToolCallingChatProviderInterface, EmbeddingProviderInterface, ImageGenerationProviderInterface, VisionProviderInterface, SpeechToTextProviderInterface, TextToSpeechProviderInterface
{
    private readonly OpenAIApiClient $api;
    private readonly OpenAIResponses $responses;
    private readonly OpenAIChat $chat;
    private readonly OpenAIEmbedding $embedding;
    private readonly OpenAIImageGeneration $images;
    private readonly OpenAIVision $vision;
    private readonly OpenAISpeech $speech;

    /**
     * $apiKey is an explicit override (tests, custom wiring) that wins over
     * the ProviderKeyStore. Production wiring passes only the store, so keys
     * saved in the admin UI (or imported from env) apply without a restart.
     * $sdkFactory builds the SDK client for a key (`\OpenAI::client()` when
     * absent) and $curl sends the gpt-image request; tests pass doubles of
     * both, so no request leaves the process.
     *
     * @param (\Closure(string): OpenAI\Client)|null $sdkFactory
     */
    public function __construct(
        LoggerInterface $logger,
        HttpClientInterface $httpClient,
        ?string $apiKey = null,
        string $uploadDir = '/var/www/backend/var/uploads',
        bool $storeResponses = false,
        ?ProviderKeyStore $keyStore = null,
        StructuredOutputTranslator $structuredOutputTranslator = new StructuredOutputTranslator(new StructuredOutputCapability()),
        ?ProviderTranscriptCapture $transcripts = null,
        ?\Closure $sdkFactory = null,
        ?OpenAICurlTransport $curl = null,
    ) {
        $this->api = new OpenAIApiClient($httpClient, $curl ?? new OpenAICurlTransport($httpClient), $apiKey, $keyStore, $sdkFactory);
        $this->responses = new OpenAIResponses($this->api, $logger, $transcripts);
        $this->chat = new OpenAIChat($this->api, $this->responses, $logger, $structuredOutputTranslator, $storeResponses);
        $this->embedding = new OpenAIEmbedding($this->api);
        $this->images = new OpenAIImageGeneration($this->api, $logger);
        $this->vision = new OpenAIVision($this->api, $this->responses, $logger, $uploadDir, $storeResponses);
        $this->speech = new OpenAISpeech($this->api, $logger, $uploadDir);
    }

    public function getName(): string
    {
        return 'openai';
    }

    public function getDisplayName(): string
    {
        return 'OpenAI';
    }

    public function getDescription(): string
    {
        return 'GPT models for chat, vision, and content generation';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'embedding', 'vision', 'image_generation', 'speech_to_text', 'text_to_speech'];
    }

    public function getDefaultModels(): array
    {
        return [];
    }

    public function getStatus(): array
    {
        if (null === $this->api->sdk()) {
            return [
                'healthy' => false,
                'error' => 'API key not configured',
            ];
        }

        return [
            'healthy' => true,
            'latency_ms' => 50,
            'error_rate' => 0.0,
            'active_connections' => 0,
        ];
    }

    public function isAvailable(): bool
    {
        return null !== $this->api->sdk();
    }

    public function supportsToolCalling(string $model): bool
    {
        if (CatalogToolUse::hasChatRow($this->getName(), $model)) {
            return CatalogToolUse::supports($this->getName(), $model);
        }

        return true;
    }

    public function getRequiredEnvVars(): array
    {
        return [
            'OPENAI_API_KEY' => [
                'required' => true,
                'hint' => 'Get your API key from https://platform.openai.com/',
            ],
        ];
    }

    // ==================== CHAT (Responses API) ====================

    public function chat(array $messages, array $options = []): array
    {
        return $this->chat->chat($messages, $options);
    }

    public function chatStream(array $messages, callable $callback, array $options = []): array
    {
        return $this->chat->chatStream($messages, $callback, $options);
    }

    // ==================== EMBEDDING ====================

    public function embed(string $text, array $options = []): array
    {
        return $this->embedding->embed($text, $options);
    }

    public function embedBatch(array $texts, array $options = []): array
    {
        return $this->embedding->embedBatch($texts, $options);
    }

    public function getDimensions(string $model): int
    {
        return $this->embedding->getDimensions($model);
    }

    // ==================== IMAGE GENERATION ====================

    public function generateImage(string $prompt, array $options = []): array
    {
        return $this->images->generateImage($prompt, $options);
    }

    public function createVariations(string $imageUrl, int $count = 1): array
    {
        return $this->images->createVariations($imageUrl, $count);
    }

    public function editImage(string $imageUrl, string $maskUrl, string $prompt): string
    {
        return $this->images->editImage($imageUrl, $maskUrl, $prompt);
    }

    // ==================== VISION ====================

    public function explainImage(string $imageUrl, string $prompt = '', array $options = []): string
    {
        return $this->vision->explainImage($imageUrl, $prompt, $options);
    }

    public function extractTextFromImage(string $imageUrl): string
    {
        return $this->vision->extractTextFromImage($imageUrl);
    }

    public function compareImages(string $imageUrl1, string $imageUrl2): array
    {
        return $this->vision->compareImages($imageUrl1, $imageUrl2);
    }

    public function analyzeImage(string $imagePath, string $prompt, array $options = []): string
    {
        return $this->vision->analyzeImage($imagePath, $prompt, $options);
    }

    // ==================== SPEECH TO TEXT (Whisper) ====================

    public function transcribe(string $audioPath, array $options = []): array
    {
        return $this->speech->transcribe($audioPath, $options);
    }

    public function translateAudio(string $audioPath, string $targetLang): string
    {
        return $this->speech->translateAudio($audioPath, $targetLang);
    }

    public function synthesize(string $text, array $options = []): string
    {
        return $this->speech->synthesize($text, $options);
    }

    public function synthesizeStream(string $text, array $options = []): \Generator
    {
        return $this->speech->synthesizeStream($text, $options);
    }

    public function getStreamContentType(array $options = []): string
    {
        return $this->speech->getStreamContentType($options);
    }

    public function supportsStreaming(): bool
    {
        return $this->speech->supportsStreaming();
    }

    public function getVoices(): array
    {
        return $this->speech->getVoices();
    }

    // ==================== TEXT TO SPEECH ====================
}
