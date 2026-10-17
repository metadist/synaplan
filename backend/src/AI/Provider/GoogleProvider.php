<?php

namespace App\AI\Provider;

use App\AI\Credential\ProviderKeyStore;
use App\AI\Exception\ProviderException;
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
use App\AI\Provider\Google\GoogleVideoGeneration;
use App\AI\Provider\Google\GoogleVision;
use App\AI\StructuredOutput\StructuredOutputCapability;
use App\AI\StructuredOutput\StructuredOutputTranslator;
use App\AI\Tool\CatalogToolUse;
use App\Service\File\FileHelper;
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
    /**
     * Gemini Transcribe bills audio at 25 tokens/sec; used to recover duration
     * from usageMetadata when the response has no clock field.
     */
    private const TRANSCRIBE_AUDIO_TOKENS_PER_SECOND = 25;

    private readonly GoogleApiClient $api;
    private readonly GoogleChat $chat;
    private readonly GoogleImageGeneration $images;
    private readonly GoogleVision $vision;
    private readonly GoogleVideoGeneration $video;

    /**
     * $apiKey is an explicit override (tests, custom wiring) that wins over
     * the ProviderKeyStore. Production wiring passes only the store, so keys
     * saved in the admin UI (or imported from env) apply without a restart.
     * $projectId, $region and $vertexAccessToken stay env-injected — only the
     * Gemini API key is resolved through the store.
     */
    public function __construct(
        private LoggerInterface $logger,
        HttpClientInterface $httpClient,
        ?string $apiKey = null,
        ?string $projectId = null,
        string $region = 'us-central1',
        private string $uploadDir = '/var/www/backend/var/uploads',
        ?string $vertexAccessToken = null,
        ?ProviderKeyStore $keyStore = null,
        StructuredOutputTranslator $structuredOutputTranslator = new StructuredOutputTranslator(new StructuredOutputCapability()),
    ) {
        $this->api = new GoogleApiClient($httpClient, $logger, $apiKey, $keyStore, $projectId, $region, $vertexAccessToken);
        $this->chat = new GoogleChat($this->api, $logger, $structuredOutputTranslator);
        $this->images = new GoogleImageGeneration($this->api, $logger, $uploadDir);
        $this->vision = new GoogleVision($this->api, $logger, $uploadDir);
        $this->video = new GoogleVideoGeneration($this->api, $logger, new GoogleOmniVideo($this->api, $logger));
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
        $key = $this->api->requireKey();

        $model = $options['model'] ?? 'gemini-3.5-transcribe';
        if (!is_string($model) || !$this->isGeminiTranscribeModelId($model)) {
            $model = 'gemini-3.5-transcribe';
        }

        $fullPath = str_starts_with($audioPath, '/')
            ? $audioPath
            : $this->uploadDir.'/'.ltrim($audioPath, '/');

        if (!is_file($fullPath)) {
            throw new ProviderException('Audio file not found: '.$fullPath, 'google');
        }

        $fileSize = filesize($fullPath);
        if (false === $fileSize || $fileSize <= 0) {
            throw new ProviderException('Audio file is empty: '.$fullPath, 'google');
        }
        if ($fileSize > 100 * 1024 * 1024) {
            throw new ProviderException('Audio file too large: '.round($fileSize / 1024 / 1024, 2).'MB (max 100MB)', 'google');
        }

        $audioBytes = file_get_contents($fullPath);
        if (false === $audioBytes || '' === $audioBytes) {
            throw new ProviderException('Failed to read audio file: '.$fullPath, 'google');
        }

        $this->logger->info('Google Gemini: Transcribing audio', [
            'model' => $model,
            'file' => basename($fullPath),
            'size_mb' => round($fileSize / 1024 / 1024, 2),
        ]);

        $url = $this->api->modelUrl($model, 'generateContent');
        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        [
                            'inlineData' => [
                                'mimeType' => $this->audioMimeType($fullPath),
                                'data' => base64_encode($audioBytes),
                            ],
                        ],
                    ],
                ],
            ],
        ];

        try {
            $response = $this->api->postJson($url, $payload, $key, 180);

            $statusCode = $response->getStatusCode();
            if (200 !== $statusCode) {
                $errorBody = $response->getContent(false);
                throw new ProviderException("Google Gemini Transcribe API error (HTTP $statusCode): $errorBody", 'google');
            }

            $data = $response->toArray();
            $text = '';
            foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
                if (isset($part['text']) && is_string($part['text'])) {
                    $text .= $part['text'];
                }
            }

            $duration = $this->transcribeDurationSeconds($data, $fullPath);

            $this->logger->info('Google Gemini: Transcription complete', [
                'model' => $model,
                'duration_seconds' => $duration,
                'text_length' => strlen($text),
            ]);

            return [
                'text' => $text,
                'language' => $options['language'] ?? 'unknown',
                'duration' => $duration,
                'segments' => [],
            ];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProviderException('Google transcription error: '.$e->getMessage(), 'google', null, 0, $e);
        }
    }

    public function translateAudio(string $audioPath, string $targetLang): string
    {
        throw new ProviderException('Google Gemini Transcribe has no dedicated translate mode. Use transcribe() and translate the text with a chat model instead.', 'google');
    }

    private function isGeminiTranscribeModelId(string $model): bool
    {
        return str_starts_with($model, 'gemini-') && str_contains($model, 'transcribe');
    }

    private function audioMimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'wav' => 'audio/wav',
            'mp3' => 'audio/mpeg',
            'm4a', 'aac' => 'audio/mp4',
            'ogg' => 'audio/ogg',
            'flac' => 'audio/flac',
            'webm' => 'audio/webm',
            default => 'audio/wav',
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function transcribeDurationSeconds(array $data, string $fullPath): float
    {
        foreach ($data['usageMetadata']['promptTokensDetails'] ?? [] as $detail) {
            if (!is_array($detail)) {
                continue;
            }
            $modality = strtoupper((string) ($detail['modality'] ?? ''));
            if ('AUDIO' !== $modality) {
                continue;
            }
            $tokens = (int) ($detail['tokenCount'] ?? $detail['tokens'] ?? 0);
            if ($tokens > 0) {
                return $tokens / self::TRANSCRIBE_AUDIO_TOKENS_PER_SECOND;
            }
        }

        $wavSeconds = $this->wavDurationSeconds($fullPath);
        if (null !== $wavSeconds) {
            return $wavSeconds;
        }

        throw new ProviderException(sprintf('Google Gemini Transcribe response omitted audio token usage, and local duration fallback is not implemented for non-WAV format: %s', basename($fullPath)), 'google');
    }

    private function wavDurationSeconds(string $path): ?float
    {
        if (!str_ends_with(strtolower($path), '.wav')) {
            return null;
        }

        $handle = fopen($path, 'rb');
        if (false === $handle) {
            return null;
        }

        try {
            $header = fread($handle, 44);
            if (false === $header || strlen($header) < 44) {
                return null;
            }
            if ('RIFF' !== substr($header, 0, 4) || 'WAVE' !== substr($header, 8, 4)) {
                return null;
            }
            $byteRateParts = unpack('V', substr($header, 28, 4));
            $dataSizeParts = unpack('V', substr($header, 40, 4));
            if (!is_array($byteRateParts) || !is_array($dataSizeParts)) {
                return null;
            }
            $byteRate = (int) ($byteRateParts[1] ?? 0);
            $dataSize = (int) ($dataSizeParts[1] ?? 0);
            if ($byteRate <= 0 || $dataSize <= 0) {
                return null;
            }

            return $dataSize / $byteRate;
        } finally {
            fclose($handle);
        }
    }

    // ==================== TEXT TO SPEECH ====================

    public function synthesize(string $text, array $options = []): string
    {
        $key = $this->api->requireKey();

        try {
            // Accept any Gemini TTS variant Google ships so newer catalog rows
            // (e.g. gemini-3.1-flash-tts-preview) reach the API instead of
            // being silently downgraded to the 2.5 fallback. We still gate on
            // the "gemini-...-tts*" shape so a typo or non-TTS model can never
            // reach :generateContent with audio modality enabled.
            $defaultTtsModel = 'gemini-2.5-flash-preview-tts';
            $model = $options['model'] ?? $defaultTtsModel;
            if (!is_string($model) || !$this->isGeminiTtsModelId($model)) {
                $model = $defaultTtsModel;
            }
            $voiceName = $options['voice'] ?? 'Kore';

            $this->logger->info('Google Gemini: Synthesizing speech with multimodal output', [
                'model' => $model,
                'text_length' => strlen($text),
                'voice' => $voiceName,
            ]);

            // Use Gemini 2.0 Flash with TTS capability (audio output modality)
            $url = $this->api->modelUrl($model, 'generateContent');

            // Gemini 2.0 Flash TTS via speech modality
            $payload = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            [
                                'text' => $text,
                            ],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'responseModalities' => ['AUDIO'], // Request audio output
                    'speechConfig' => [
                        'voiceConfig' => [
                            'prebuiltVoiceConfig' => [
                                'voiceName' => $voiceName,
                            ],
                        ],
                    ],
                ],
            ];

            $response = $this->api->postJson($url, $payload, $key, 240, ['max_duration' => 300]);

            $statusCode = $response->getStatusCode();
            if (200 !== $statusCode) {
                $errorBody = $response->getContent(false);
                $this->logger->error('Google Gemini TTS: API error', [
                    'status_code' => $statusCode,
                    'error_body' => $errorBody,
                ]);
                throw new \Exception("Google Gemini TTS API error (HTTP $statusCode): $errorBody");
            }

            $data = $response->toArray();

            $parts = $data['candidates'][0]['content']['parts'] ?? [];
            $audioPart = null;
            foreach ($parts as $part) {
                if (isset($part['inline_data']['data'])) {
                    $this->logger->info('Google Gemini TTS: Found inline_data audio part', [
                        'keys' => array_keys($part['inline_data']),
                        'mime_type' => $part['inline_data']['mime_type'] ?? null,
                        'data_preview' => substr($part['inline_data']['data'], 0, 32),
                    ]);
                    $audioPart = $part['inline_data'];
                    break;
                }
                if (isset($part['inlineData']['data'])) {
                    $this->logger->info('Google Gemini TTS: Found inlineData audio part', [
                        'keys' => array_keys($part['inlineData']),
                        'mime_type' => $part['inlineData']['mimeType'] ?? null,
                        'data_preview' => substr($part['inlineData']['data'], 0, 32),
                    ]);
                    $audioPart = $part['inlineData'];
                    break;
                }
            }

            if (!$audioPart || empty($audioPart['data'])) {
                $this->logger->error('Google Gemini TTS: No audio data in response', [
                    'response' => json_encode($data),
                ]);
                throw new \Exception('No audio data returned from Gemini TTS');
            }

            $base64Audio = $audioPart['data'];
            $mimeType = strtolower($audioPart['mime_type'] ?? $audioPart['mimeType'] ?? 'audio/wav');

            // Decode base64 audio
            $audioData = base64_decode($base64Audio);

            if (false === $audioData || '' === $audioData) {
                throw new \Exception('Failed to decode audio data returned from Gemini TTS');
            }

            // Gemini TTS returns signed 16-bit PCM data by default (see docs)
            // Convert PCM payload to a proper WAV container so browsers can play it.
            // The mime carries the actual format, e.g. "audio/L16;rate=24000;channels=1"
            // (RFC 2586) — parse the rate/channels rather than assuming defaults.
            if ($this->isRawPcmMimeType($mimeType)) {
                [$sampleRate, $channels] = $this->parsePcmParams($mimeType);
                $audioData = $this->convertPcmToWav($audioData, $sampleRate, $channels);
                $mimeType = 'audio/wav';
            }

            // Determine file extension from mime type (after PCM conversion)
            $extension = match (true) {
                str_contains($mimeType, 'wav') => 'wav',
                str_contains($mimeType, 'mp3') => 'mp3',
                str_contains($mimeType, 'ogg') => 'ogg',
                default => 'wav',
            };

            // Save to file with proper permissions
            $this->ensureUploadDirectory();
            $filename = 'tts_'.uniqid().'.'.$extension;
            $outputPath = rtrim($this->uploadDir, '/\\').'/'.$filename;

            $written = FileHelper::writeFile($outputPath, $audioData);
            if (false === $written) {
                throw new \Exception("Failed to write audio file to {$outputPath}");
            }

            $this->logger->info('Google Gemini TTS: Audio saved', [
                'filename' => $filename,
                'size_bytes' => strlen($audioData),
                'mime_type' => $mimeType,
            ]);

            return $filename;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ProviderException('Google TTS error: '.$e->getMessage(), 'google');
        }
    }

    private function ensureUploadDirectory(): void
    {
        if (!FileHelper::createDirectory($this->uploadDir)) {
            throw new \RuntimeException(sprintf('Unable to create upload directory: %s', $this->uploadDir));
        }

        if (!is_writable($this->uploadDir)) {
            throw new \RuntimeException(sprintf('Upload directory is not writable: %s', $this->uploadDir));
        }
    }

    public function synthesizeStream(string $text, array $options = []): \Generator
    {
        $filename = $this->synthesize($text, $options);
        $fullPath = $this->uploadDir.'/'.$filename;

        if (!file_exists($fullPath)) {
            throw new ProviderException('Google TTS: synthesized file not found', 'google');
        }

        $handle = fopen($fullPath, 'rb');
        if (!$handle) {
            throw new ProviderException('Google TTS: cannot read synthesized file', 'google');
        }

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 8192);
                if (false !== $chunk && '' !== $chunk) {
                    yield $chunk;
                }
            }
        } finally {
            fclose($handle);
            @unlink($fullPath);
        }
    }

    public function getStreamContentType(array $options = []): string
    {
        return 'audio/wav';
    }

    public function supportsStreaming(): bool
    {
        return false;
    }

    public function getVoices(): array
    {
        // Google Cloud TTS voices would be loaded here
        return [];
    }

    private function isRawPcmMimeType(?string $mimeType): bool
    {
        if (!$mimeType) {
            return true;
        }

        return str_contains($mimeType, 'pcm')
            || str_contains($mimeType, 'x-raw')
            || str_contains($mimeType, 'linear16')
            // RFC 2586: "audio/L16" is headerless linear 16-bit PCM — what Gemini
            // TTS actually returns ("audio/l16;rate=24000;channels=1"). Without
            // this, the raw bytes were saved as .wav with no RIFF header and no
            // browser could decode them ("audio not available").
            || str_contains($mimeType, 'l16');
    }

    /**
     * Parse sample rate + channel count from a PCM mime type such as
     * "audio/l16;rate=24000;channels=1" (RFC 2586). Falls back to the Gemini
     * defaults (24 kHz mono) when a parameter is absent.
     *
     * @return array{0: int, 1: int} [sampleRate, channels]
     */
    private function parsePcmParams(string $mimeType): array
    {
        $sampleRate = 24000;
        $channels = 1;

        if (preg_match('/rate=(\d+)/', $mimeType, $m)) {
            $sampleRate = max(8000, min(192000, (int) $m[1]));
        }
        if (preg_match('/channels=(\d+)/', $mimeType, $m)) {
            $channels = max(1, min(8, (int) $m[1]));
        }

        return [$sampleRate, $channels];
    }

    /**
     * Recognise the Gemini TTS model family so the catalog can ship new
     * preview ids (gemini-2.5-flash-preview-tts, gemini-2.5-pro-preview-tts,
     * gemini-3.1-flash-tts-preview, …) without a code change. We keep the
     * shape strict enough that non-TTS Gemini models still get rejected.
     */
    private function isGeminiTtsModelId(string $model): bool
    {
        if (!str_starts_with($model, 'gemini-')) {
            return false;
        }

        return str_contains($model, '-tts');
    }

    /**
     * Gemini TTS returns signed 16-bit PCM buffers (24 kHz, mono) by default.
     * Wrap the PCM payload into a RIFF/WAVE container so browsers can play it.
     */
    private function convertPcmToWav(
        string $pcmData,
        int $sampleRate = 24000,
        int $channels = 1,
        int $bitsPerSample = 16,
    ): string {
        $dataSize = strlen($pcmData);
        $blockAlign = (int) ($channels * ($bitsPerSample / 8));
        $byteRate = $sampleRate * $blockAlign;

        $header = 'RIFF'
            .pack('V', 36 + $dataSize)
            .'WAVEfmt '
            .pack('V', 16)
            .pack('v', 1) // PCM format
            .pack('v', $channels)
            .pack('V', $sampleRate)
            .pack('V', $byteRate)
            .pack('v', $blockAlign)
            .pack('v', $bitsPerSample)
            .'data'
            .pack('V', $dataSize);

        return $header.$pcmData;
    }
}
