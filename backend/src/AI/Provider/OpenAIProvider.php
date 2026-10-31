<?php

namespace App\AI\Provider;

use App\AI\Catalog\Attribute\AsAiProvider;
use App\AI\Catalog\DescribesProviderInterface;
use App\AI\Catalog\ModelListing;
use App\AI\Catalog\ProviderBranding;
use App\AI\Catalog\ProviderCapability;
use App\AI\Catalog\ProviderDefaults;
use App\AI\Catalog\ProviderDescriptor;
use App\AI\Catalog\ProviderDialects;
use App\AI\Catalog\ProviderEndpoint;
use App\AI\Catalog\ProviderKeyPresentation;
use App\AI\Catalog\ProviderKeySpec;
use App\AI\Catalog\ProviderKeyValidation;
use App\AI\Catalog\ProviderPricing;
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
use App\AI\StructuredOutput\StructuredOutputDialect;
use App\AI\StructuredOutput\StructuredOutputTranslator;
use App\AI\Tool\CatalogToolUse;
use App\Service\Chat\ProviderTranscriptCapture;
use OpenAI;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsAiProvider]
class OpenAIProvider implements ChatProviderInterface, ToolCallingChatProviderInterface, EmbeddingProviderInterface, ImageGenerationProviderInterface, VisionProviderInterface, SpeechToTextProviderInterface, TextToSpeechProviderInterface, DescribesProviderInterface
{
    private const BASE_URL = 'https://api.openai.com/v1';

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

    public static function descriptor(): ProviderDescriptor
    {
        return new ProviderDescriptor(
            name: 'openai',
            displayName: 'OpenAI',
            capabilities: [
                ProviderCapability::Chat,
                ProviderCapability::Embedding,
                ProviderCapability::Vision,
                ProviderCapability::ImageGeneration,
                ProviderCapability::SpeechToText,
                ProviderCapability::TextToSpeech,
            ],
            endpoints: [
                ProviderEndpoint::chatCompletions(self::BASE_URL.'/chat/completions'),
                ProviderEndpoint::api(self::BASE_URL),
            ],
            key: ProviderKeySpec::platformAndUser(
                envVar: 'OPENAI_API_KEY',
                consoleUrl: 'https://platform.openai.com/api-keys',
                validation: ProviderKeyValidation::bearer(self::BASE_URL.'/models'),
                listOrder: 2,
                modelListing: ModelListing::Lists,
                fieldDescription: 'OpenAI API key',
                configSection: ProviderKeyPresentation::SECTION_CLOUD,
                configOrder: 1,
            ),
            dialects: new ProviderDialects(
                structuredOutput: StructuredOutputDialect::OPENAI_RESPONSES_TEXT_FORMAT,
                catalogToolUse: true,
                gatewayVisionRewrite: true,
                reasoning: OpenAiReasoningEffort::ladder(),
            ),
            defaults: new ProviderDefaults(bindings: [
                'CHAT' => 'openai:gpt-5.5:chat',
                'TOOLS' => 'openai:gpt-5.5:chat',
                'ANALYZE' => 'openai:gpt-5.5:chat',
                'SORT' => 'openai:gpt-5.4-mini:chat',
                'PLAN' => 'openai:gpt-5.4-mini:chat',
                'SUMMARIZE' => 'openai:gpt-5.4-mini:chat',
                'PIC2TEXT' => 'openai:gpt-5.5:pic2text',
                'SOUND2TEXT' => 'openai:whisper-1:sound2text',
            ], preferenceRank: 2),
            pricing: ProviderPricing::litellm(['openai'], ['openai']),
            liveVoiceSessions: 50,
            branding: new ProviderBranding(icon: 'simple-icons:openai', flag: 'circle-flags:us', helpKey: 'openai'),
        );
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
