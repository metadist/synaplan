<?php

namespace App\AI\Provider;

use App\AI\Credential\ProviderKeyStore;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\ImageGenerationProviderInterface;
use App\AI\Interface\SpeechToTextProviderInterface;
use App\AI\Interface\SupportsAsyncVideo;
use App\AI\Interface\TextToSpeechProviderInterface;
use App\AI\Interface\ToolCallingChatProviderInterface;
use App\AI\Interface\VideoGenerationProviderInterface;
use App\AI\Interface\VisionProviderInterface;
use App\AI\Provider\Google\GoogleApiClient;
use App\AI\Provider\Google\GoogleChat;
use App\AI\Provider\Google\GoogleImageGeneration;
use App\AI\Provider\Google\GoogleOmniVideo;
use App\AI\Provider\Google\GoogleSpeech;
use App\AI\Provider\Google\GoogleVideoGeneration;
use App\AI\Provider\Google\GoogleVision;
use App\AI\StructuredOutput\StructuredOutputCapability;
use App\AI\StructuredOutput\StructuredOutputTranslator;
use App\AI\Tool\CatalogToolUse;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google AI Provider.
 *
 * Supports:
 * - Gemini 2.5 / 3.x Flash and Pro (Chat, Vision, tools)
 * - Gemini native image models (Nano Banana) and optional Vertex Imagen
 * - Veo 3.1 (predictLongRunning) and Gemini Omni Flash (Interactions API)
 * - Gemini TTS and Gemini 3.5 Transcribe
 */
class GoogleProvider implements ChatProviderInterface, ToolCallingChatProviderInterface, ImageGenerationProviderInterface, VideoGenerationProviderInterface, VisionProviderInterface, SpeechToTextProviderInterface, TextToSpeechProviderInterface, SupportsAsyncVideo
{
    private readonly GoogleApiClient $api;
    private readonly GoogleChat $chat;
    private readonly GoogleImageGeneration $images;
    private readonly GoogleVision $vision;
    private readonly GoogleVideoGeneration $video;
    private readonly GoogleSpeech $speech;

    /**
     * $apiKey is an explicit override (tests, custom wiring) that wins over
     * the ProviderKeyStore. Production wiring passes only the store, so keys
     * saved in the admin UI (or imported from env) apply without a restart.
     * $projectId, $region and $vertexAccessToken stay env-injected — only the
     * Gemini API key is resolved through the store.
     */
    public function __construct(
        LoggerInterface $logger,
        HttpClientInterface $httpClient,
        ?string $apiKey = null,
        ?string $projectId = null,
        string $region = 'us-central1',
        string $uploadDir = '/var/www/backend/var/uploads',
        ?string $vertexAccessToken = null,
        ?ProviderKeyStore $keyStore = null,
        StructuredOutputTranslator $structuredOutputTranslator = new StructuredOutputTranslator(new StructuredOutputCapability()),
    ) {
        $this->api = new GoogleApiClient($httpClient, $logger, $apiKey, $keyStore, $projectId, $region, $vertexAccessToken);
        $this->chat = new GoogleChat($this->api, $logger, $structuredOutputTranslator);
        $this->images = new GoogleImageGeneration($this->api, $logger, $uploadDir);
        $this->vision = new GoogleVision($this->api, $logger, $uploadDir);
        $this->video = new GoogleVideoGeneration($this->api, $logger, new GoogleOmniVideo($this->api, $logger));
        $this->speech = new GoogleSpeech($this->api, $logger, $uploadDir);
    }

    public function getName(): string
    {
        return 'google';
    }

    public function supportsToolCalling(string $model): bool
    {
        if (CatalogToolUse::hasChatRow($this->getName(), $model)) {
            return CatalogToolUse::supports($this->getName(), $model);
        }

        return true;
    }

    public function getDisplayName(): string
    {
        return 'Google AI';
    }

    public function getDescription(): string
    {
        return 'Gemini models with multimodal capabilities including video';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'embedding', 'vision', 'image_generation', 'video_generation', 'speech_to_text', 'text_to_speech'];
    }

    public function getDefaultModels(): array
    {
        return [];
    }

    public function getStatus(): array
    {
        if (null === $this->api->key()) {
            return [
                'healthy' => false,
                'error' => 'API key not configured',
            ];
        }

        return [
            'healthy' => true,
            'latency_ms' => 100,
            'error_rate' => 0.0,
            'active_connections' => 0,
        ];
    }

    public function isAvailable(): bool
    {
        return null !== $this->api->key();
    }

    public function getRequiredEnvVars(): array
    {
        return [
            'GOOGLE_GEMINI_API_KEY' => [
                'required' => true,
                'any_of' => ['GOOGLE_GEMINI_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_API_KEY'],
                'hint' => 'Get your API key from https://aistudio.google.com/app/apikey',
            ],
        ];
    }

    // ==================== CHAT ====================

    public function chat(array $messages, array $options = []): array
    {
        return $this->chat->chat($messages, $options);
    }

    public function chatStream(array $messages, callable $callback, array $options = []): array
    {
        return $this->chat->chatStream($messages, $callback, $options);
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

    // ==================== VIDEO GENERATION ====================

    public function generateVideo(string $prompt, array $options = []): array
    {
        return $this->video->generateVideo($prompt, $options);
    }

    public function startVideoOperation(string $prompt, array $options = []): array
    {
        return $this->video->startVideoOperation($prompt, $options);
    }

    public function pollVideoOperationOnce(string $operationName, array $options = []): array
    {
        return $this->video->pollVideoOperationOnce($operationName, $options);
    }

    public function downloadVideoContent(string $videoUri): string
    {
        return $this->video->downloadVideoContent($videoUri);
    }

    public function downloadVideoRaw(string $videoUri, array $options = []): string
    {
        return $this->video->downloadVideoRaw($videoUri, $options);
    }

    public function cancelVideoOperation(string $operationName, array $options = []): void
    {
        $this->video->cancelVideoOperation($operationName, $options);
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

    // ==================== SPEECH TO TEXT ====================

    public function transcribe(string $audioPath, array $options = []): array
    {
        return $this->speech->transcribe($audioPath, $options);
    }

    public function translateAudio(string $audioPath, string $targetLang): string
    {
        return $this->speech->translateAudio($audioPath, $targetLang);
    }

    // ==================== TEXT TO SPEECH ====================

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
}
