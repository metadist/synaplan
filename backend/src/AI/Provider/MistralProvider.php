<?php

namespace App\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\SpeechToTextProviderInterface;
use App\AI\Interface\TextToSpeechProviderInterface;
use App\AI\Interface\VisionProviderInterface;
use App\Service\File\FileHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Mistral AI Provider.
 *
 * Chat uses Mistral's OpenAI-compatible endpoint (/v1/chat/completions) via the
 * openai-php client, mirroring GroqProvider. The Voxtral audio endpoints are
 * NOT OpenAI-shaped, so they are called directly over HTTP:
 *  - Speech-to-text  : POST /v1/audio/transcriptions (Voxtral Mini Transcribe)
 *  - Text-to-speech  : POST /v1/audio/speech (Voxtral TTS, returns base64 JSON)
 *
 * @see https://docs.mistral.ai/
 * @see https://docs.mistral.ai/studio-api/audio/overview
 */
class MistralProvider implements ChatProviderInterface, SpeechToTextProviderInterface, TextToSpeechProviderInterface, VisionProviderInterface
{
    private const PROVIDER_NAME = 'mistral';
    private const BASE_URI = 'https://api.mistral.ai/v1';
    private const TRANSCRIBE_ENDPOINT = 'https://api.mistral.ai/v1/audio/transcriptions';
    private const SPEECH_ENDPOINT = 'https://api.mistral.ai/v1/audio/speech';
    private const VOICES_ENDPOINT = 'https://api.mistral.ai/v1/audio/voices';

    private const DEFAULT_TRANSCRIBE_MODEL = 'voxtral-mini-latest';
    private const DEFAULT_TTS_MODEL = 'voxtral-mini-tts-2603';
    private const DEFAULT_TTS_FORMAT = 'mp3';

    // Hosted Voxtral TTS has NO implicit default voice (unlike the open-weights
    // checkpoint): /v1/audio/speech rejects a request that supplies neither
    // `voice_id` nor `ref_audio`. This documented preset is the last-resort
    // fallback when the live voices catalog can't be reached.
    private const DEFAULT_TTS_VOICE = 'fr_marie_neutral';
    private const DEFAULT_VISION_MODEL = 'mistral-medium-latest';
    private const VISION_MAX_TOKENS = 2048;

    private const TIMEOUT_AUDIO_SECONDS = 120;

    private $client;

    /**
     * Cached raw preset-voice catalog, used to resolve a default voice when the
     * caller didn't pick one. `null` until first lookup.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $presetVoiceCache = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ?string $apiKey = null,
        private readonly string $uploadDir = '/var/www/backend/var/uploads',
    ) {
        if (!empty($apiKey)) {
            // Mistral exposes an OpenAI-compatible chat API; reuse the same client.
            $this->client = \OpenAI::factory()
                ->withApiKey($apiKey)
                ->withBaseUri(self::BASE_URI)
                ->make();
        }
    }

    // ==================== METADATA ====================

    public function getName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function getDisplayName(): string
    {
        return 'Mistral AI';
    }

    public function getDescription(): string
    {
        return 'Mistral AI — chat (Mistral Medium/Large), transcription (Voxtral Mini Transcribe) and text-to-speech (Voxtral TTS).';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'vision', 'speech_to_text', 'text_to_speech'];
    }

    public function getDefaultModels(): array
    {
        return [
            'chat' => 'mistral-medium-latest',
            'speech_to_text' => self::DEFAULT_TRANSCRIBE_MODEL,
            'text_to_speech' => self::DEFAULT_TTS_MODEL,
        ];
    }

    public function getStatus(): array
    {
        if (!$this->isAvailable()) {
            return [
                'healthy' => false,
                'error' => 'API key not configured',
            ];
        }

        return [
            'healthy' => true,
            'latency_ms' => 0,
            'error_rate' => 0.0,
            'active_connections' => 0,
        ];
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey) && null !== $this->client;
    }

    public function getRequiredEnvVars(): array
    {
        return [
            'MISTRAL_API_KEY' => [
                'required' => true,
                'hint' => 'Get your API key from https://console.mistral.ai/',
            ],
        ];
    }

    // ==================== CHAT ====================

    public function chat(array $messages, array $options = []): array
    {
        $this->assertChat($options);

        try {
            $requestOptions = $this->buildChatOptions($messages, $options, false);
            $response = $this->client->chat()->create($requestOptions);
            $responseArray = $response->toArray();

            return [
                'content' => $response->choices[0]->message->content ?? '',
                'usage' => $this->parseUsage($responseArray['usage'] ?? []),
            ];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Mistral chat error', [
                'error' => $e->getMessage(),
                'model' => $options['model'] ?? 'unknown',
            ]);

            throw new ProviderException('Mistral chat error: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }
    }

    public function chatStream(array $messages, callable $callback, array $options = []): array
    {
        $this->assertChat($options);

        try {
            $requestOptions = $this->buildChatOptions($messages, $options, true);
            $stream = $this->client->chat()->createStreamed($requestOptions);

            $usage = $this->parseUsage([]);
            $finishReason = null;

            foreach ($stream as $response) {
                $responseArray = $response->toArray();

                if (isset($responseArray['usage'])) {
                    $usage = $this->parseUsage($responseArray['usage']);
                }

                $chunkFinishReason = $responseArray['choices'][0]['finish_reason'] ?? null;
                if (null !== $chunkFinishReason) {
                    $finishReason = $chunkFinishReason;
                }

                // Reasoning content (Magistral / reasoning-capable models).
                if (isset($response->choices[0]->delta->reasoning_content)) {
                    $callback([
                        'type' => 'reasoning',
                        'content' => $response->choices[0]->delta->reasoning_content,
                    ]);
                }

                if (isset($response->choices[0]->delta->content)) {
                    $callback($response->choices[0]->delta->content);
                }
            }

            $callback(['type' => 'finish', 'finish_reason' => $finishReason ?? 'stop']);

            return ['usage' => $usage];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Mistral streaming error', [
                'error' => $e->getMessage(),
                'model' => $options['model'] ?? 'unknown',
            ]);

            throw new ProviderException('Mistral streaming error: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }
    }

    // ==================== VISION (Pixtral / multimodal chat) ====================

    public function explainImage(string $imageUrl, string $prompt = '', array $options = []): string
    {
        $this->assertApiKey();

        $model = $options['model'] ?? self::DEFAULT_VISION_MODEL;
        $prompt = '' !== $prompt ? $prompt : 'Please describe this image in detail.';

        try {
            $response = $this->client->chat()->create([
                'model' => $model,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        ['type' => 'image_url', 'image_url' => ['url' => $this->imageToDataUrl($imageUrl)]],
                    ],
                ]],
                'max_tokens' => $options['max_tokens'] ?? self::VISION_MAX_TOKENS,
            ]);

            return $response->choices[0]->message->content ?? '';
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProviderException('Mistral vision error: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }
    }

    public function extractTextFromImage(string $imageUrl): string
    {
        return $this->explainImage(
            $imageUrl,
            'Extract all text from this image. Provide only the extracted text, preserving line breaks, without any commentary.',
        );
    }

    public function compareImages(string $imageUrl1, string $imageUrl2): array
    {
        $this->assertApiKey();

        try {
            $response = $this->client->chat()->create([
                'model' => self::DEFAULT_VISION_MODEL,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Compare these two images and describe the differences and similarities.'],
                        ['type' => 'image_url', 'image_url' => ['url' => $this->imageToDataUrl($imageUrl1)]],
                        ['type' => 'image_url', 'image_url' => ['url' => $this->imageToDataUrl($imageUrl2)]],
                    ],
                ]],
                'max_tokens' => self::VISION_MAX_TOKENS,
            ]);

            return [
                'comparison' => $response->choices[0]->message->content ?? '',
                'image1' => basename($imageUrl1),
                'image2' => basename($imageUrl2),
            ];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProviderException('Mistral image comparison error: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }
    }

    // ==================== SPEECH TO TEXT (Voxtral Mini Transcribe) ====================

    public function transcribe(string $audioPath, array $options = []): array
    {
        $this->assertApiKey();

        $model = $options['model'] ?? self::DEFAULT_TRANSCRIBE_MODEL;
        $fullPath = $this->resolveExistingPath($audioPath);

        $fields = [
            'model' => $model,
            'file' => DataPart::fromPath($fullPath),
        ];

        if (!empty($options['language'])) {
            $fields['language'] = $options['language'];
        }

        $this->logger->info('Mistral: Transcribing audio', [
            'model' => $model,
            'file' => basename($audioPath),
        ]);

        try {
            $formData = new FormDataPart($fields);

            $response = $this->httpClient->request('POST', self::TRANSCRIBE_ENDPOINT, [
                'auth_bearer' => $this->apiKey,
                'headers' => $formData->getPreparedHeaders()->toArray(),
                'body' => $formData->bodyToIterable(),
                'timeout' => self::TIMEOUT_AUDIO_SECONDS,
            ]);

            $this->assertHttpOk($response, 'transcription');
            $data = $response->toArray();

            return [
                'text' => $data['text'] ?? '',
                'language' => $data['language'] ?? 'unknown',
                'duration' => $data['usage']['audio_duration'] ?? ($data['duration'] ?? 0),
                'segments' => $data['segments'] ?? [],
            ];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProviderException('Mistral transcription error: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }
    }

    /**
     * Mistral's Voxtral transcription endpoint has no dedicated translate mode
     * (unlike Whisper). Translation is therefore not supported.
     */
    public function translateAudio(string $audioPath, string $targetLang): string
    {
        throw new ProviderException('Mistral (Voxtral) does not support audio translation. Use transcribe() instead.', self::PROVIDER_NAME);
    }

    // ==================== TEXT TO SPEECH (Voxtral TTS) ====================

    public function synthesize(string $text, array $options = []): string
    {
        $this->assertApiKey();

        $format = $options['format'] ?? self::DEFAULT_TTS_FORMAT;

        $this->logger->info('Mistral: Synthesizing speech', [
            'model' => $options['model'] ?? self::DEFAULT_TTS_MODEL,
            'format' => $format,
            'text_length' => strlen($text),
        ]);

        try {
            $response = $this->httpClient->request('POST', self::SPEECH_ENDPOINT, [
                'auth_bearer' => $this->apiKey,
                'json' => $this->buildSpeechBody($text, $options, false),
                'timeout' => self::TIMEOUT_AUDIO_SECONDS,
            ]);

            $this->assertHttpOk($response, 'TTS');
            $data = $response->toArray();

            $base64 = $data['audio_data'] ?? '';
            if ('' === $base64) {
                throw new ProviderException('Mistral TTS returned no audio data', self::PROVIDER_NAME);
            }

            $audio = base64_decode($base64, true);
            if (false === $audio) {
                throw new ProviderException('Mistral TTS returned invalid base64 audio', self::PROVIDER_NAME);
            }

            $extension = 'pcm' === $format ? 'wav' : $format;
            $filename = 'tts_'.uniqid().'.'.$extension;
            $outputPath = $this->uploadDir.'/'.$filename;

            if (!FileHelper::createDirectory($this->uploadDir)) {
                throw new \RuntimeException('Unable to create upload directory: '.$this->uploadDir);
            }

            FileHelper::writeFile($outputPath, $audio);

            return $filename;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProviderException('Mistral TTS error: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }
    }

    public function synthesizeStream(string $text, array $options = []): \Generator
    {
        $this->assertApiKey();

        $response = $this->httpClient->request('POST', self::SPEECH_ENDPOINT, [
            'auth_bearer' => $this->apiKey,
            'headers' => ['Accept' => 'text/event-stream'],
            'json' => $this->buildSpeechBody($text, $options, true),
            'buffer' => false,
            'timeout' => self::TIMEOUT_AUDIO_SECONDS,
        ]);

        $this->assertHttpOk($response, 'TTS stream');

        // Mistral streams SSE: each `speech.audio.delta` event carries a
        // base64-encoded audio fragment; `speech.audio.done` ends the stream.
        $buffer = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            $buffer .= $chunk->getContent();

            while (false !== ($pos = strpos($buffer, "\n"))) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                if ('' === $line || !str_starts_with($line, 'data:')) {
                    continue;
                }

                $payload = trim(substr($line, 5));
                if ('' === $payload || '[DONE]' === $payload) {
                    continue;
                }

                $event = json_decode($payload, true);
                if (!is_array($event)) {
                    continue;
                }

                $audioB64 = $event['audio_data']
                    ?? ($event['audio'] ?? ($event['delta'] ?? ($event['data']['audio'] ?? null)));
                if (is_string($audioB64) && '' !== $audioB64) {
                    $decoded = base64_decode($audioB64, true);
                    if (false !== $decoded && '' !== $decoded) {
                        yield $decoded;
                    }
                }
            }
        }
    }

    public function getStreamContentType(array $options = []): string
    {
        $format = $options['format'] ?? self::DEFAULT_TTS_FORMAT;

        return match ($format) {
            'opus' => 'audio/ogg',
            'flac' => 'audio/flac',
            'wav', 'pcm' => 'audio/wav',
            default => 'audio/mpeg',
        };
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function getVoices(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        try {
            $response = $this->httpClient->request('GET', self::VOICES_ENDPOINT, [
                'auth_bearer' => $this->apiKey,
                'timeout' => 30,
            ]);

            if (200 !== $response->getStatusCode()) {
                return [];
            }

            $data = $response->toArray(false);
            $voices = $data['voices'] ?? (array_is_list($data) ? $data : []);

            $result = [];
            foreach ($voices as $voice) {
                if (!is_array($voice)) {
                    continue;
                }
                $id = $voice['id'] ?? ($voice['voice_id'] ?? ($voice['name'] ?? null));
                if (null === $id) {
                    continue;
                }
                $result[] = [
                    'id' => (string) $id,
                    'name' => (string) ($voice['name'] ?? $id),
                    'description' => (string) ($voice['description'] ?? ''),
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch Mistral voices: '.$e->getMessage());

            return [];
        }
    }

    // ==================== HELPERS ====================

    private function assertChat(array $options): void
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', self::PROVIDER_NAME);
        }
        $this->assertApiKey();
    }

    private function assertApiKey(): void
    {
        if (!$this->isAvailable()) {
            throw ProviderException::missingApiKey(self::PROVIDER_NAME, 'MISTRAL_API_KEY');
        }
    }

    /**
     * @param array<int, mixed>    $messages
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildChatOptions(array $messages, array $options, bool $stream): array
    {
        $requestOptions = [
            'model' => $options['model'],
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? ChatProviderInterface::DEFAULT_MAX_COMPLETION_TOKENS,
        ];

        if (isset($options['temperature'])) {
            $requestOptions['temperature'] = $options['temperature'];
        }

        if ($stream) {
            $requestOptions['stream'] = true;
            $requestOptions['stream_options'] = ['include_usage' => true];
        }

        return $requestOptions;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildSpeechBody(string $text, array $options, bool $stream): array
    {
        $body = [
            'model' => $options['model'] ?? self::DEFAULT_TTS_MODEL,
            'input' => $text,
            'response_format' => $options['format'] ?? self::DEFAULT_TTS_FORMAT,
        ];

        // voice_id (preset or saved voice) and ref_audio (zero-shot cloning) are
        // mutually exclusive; pass whichever the caller supplied. When neither is
        // given we MUST still send a voice — the hosted endpoint 400s otherwise
        // ("Either ref_audio or voice must be provided.") — so resolve a sensible
        // preset default instead of letting the request fail.
        if (!empty($options['voice'])) {
            $body['voice_id'] = $options['voice'];
        } elseif (!empty($options['ref_audio'])) {
            $body['ref_audio'] = $options['ref_audio'];
        } else {
            $language = is_string($options['language'] ?? null) ? $options['language'] : null;
            $body['voice_id'] = $this->resolveDefaultVoiceId($language);
        }

        if ($stream) {
            $body['stream'] = true;
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $usage
     *
     * @return array<string, int>
     */
    private function parseUsage(array $usage): array
    {
        return [
            'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
            'completion_tokens' => $usage['completion_tokens'] ?? 0,
            'total_tokens' => $usage['total_tokens'] ?? 0,
            'cached_tokens' => $usage['prompt_tokens_details']['cached_tokens'] ?? 0,
            'cache_creation_tokens' => 0,
        ];
    }

    private function resolveExistingPath(string $audioPath): string
    {
        $fullPath = str_starts_with($audioPath, '/')
            ? $audioPath
            : $this->uploadDir.'/'.ltrim($audioPath, '/');

        if (!file_exists($fullPath)) {
            throw new ProviderException("Audio file not found: {$fullPath}", self::PROVIDER_NAME);
        }

        return $fullPath;
    }

    /**
     * Build a base64 `data:` URL for the Mistral chat vision API. Remote
     * http(s) URLs are passed through unchanged (Mistral fetches them).
     */
    private function imageToDataUrl(string $imageUrl): string
    {
        if (str_starts_with($imageUrl, 'http://') || str_starts_with($imageUrl, 'https://') || str_starts_with($imageUrl, 'data:')) {
            return $imageUrl;
        }

        $fullPath = str_starts_with($imageUrl, '/')
            ? $imageUrl
            : $this->uploadDir.'/'.ltrim($imageUrl, '/');

        if (!file_exists($fullPath)) {
            throw new ProviderException("Image file not found: {$fullPath}", self::PROVIDER_NAME);
        }

        $mimeType = mime_content_type($fullPath) ?: 'image/jpeg';
        $base64 = base64_encode((string) file_get_contents($fullPath));

        return "data:{$mimeType};base64,{$base64}";
    }

    private function assertHttpOk(ResponseInterface $response, string $context): void
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $detail = '';
            try {
                $detail = substr($response->getContent(false), 0, 500);
            } catch (\Throwable) {
            }

            throw new ProviderException(sprintf('Mistral %s HTTP %d: %s', $context, $status, $detail), self::PROVIDER_NAME);
        }
    }
}
