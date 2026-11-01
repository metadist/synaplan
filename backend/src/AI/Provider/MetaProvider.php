<?php

declare(strict_types=1);

namespace App\AI\Provider;

use App\AI\Catalog\Attribute\AsAiProvider;
use App\AI\Catalog\ModelListing;
use App\AI\Catalog\ProviderBranding;
use App\AI\Catalog\ProviderCapability;
use App\AI\Catalog\ProviderDefaults;
use App\AI\Catalog\ProviderDescriptor;
use App\AI\Catalog\ProviderDialects;
use App\AI\Catalog\ProviderEndpoint;
use App\AI\Catalog\ProviderKeySpec;
use App\AI\Catalog\ProviderKeyValidation;
use App\AI\Catalog\ProviderPricing;
use App\AI\Catalog\ReasoningLadder;
use App\AI\Credential\ProviderKeyStore;
use App\AI\Exception\ProviderException;
use App\AI\Interface\ImageGenerationProviderInterface;
use App\AI\Interface\SpeechToTextProviderInterface;
use App\AI\StructuredOutput\StructuredOutputCapability;
use App\AI\StructuredOutput\StructuredOutputDialect;
use App\AI\StructuredOutput\StructuredOutputTranslator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Meta Model API — Muse Spark, Muse Wonder, Muse Image, and Muse Voice Transcribe.
 *
 * Chat and vision use OpenAI-compatible Chat Completions at
 * https://api.meta.ai/v1. Muse Image uses the Images API
 * (`/images/generations` and `/images/edits`). Muse Voice Transcribe uses
 * the file endpoint `POST /asr/transcribe`. The realtime WebSocket is not
 * wired: Synaplan transcribes a recording, not a live stream.
 *
 * Auth is `Authorization: Bearer`. Meta's own SDKs read MODEL_API_KEY;
 * Synaplan stores the same secret as META_API_KEY.
 *
 * `reasoning_effort` is a top-level Chat Completions field. `none` is not
 * supported and returns HTTP 400. The contributor-tier model id is not
 * offered: that tier is used to improve Meta's products.
 *
 * @see https://dev.meta.ai/docs/authentication
 * @see https://dev.meta.ai/docs/image-generation
 * @see https://dev.meta.ai/docs/speech-to-text
 * @see https://dev.meta.ai/docs/models
 */
#[AsAiProvider]
class MetaProvider extends AbstractChatCompletionsCloudProvider implements ImageGenerationProviderInterface, SpeechToTextProviderInterface
{
    private const PROVIDER_NAME = 'meta';
    private const BASE_URI = 'https://api.meta.ai/v1';
    private const DEFAULT_CHAT_MODEL = 'muse-spark-1.3';
    private const DEFAULT_VISION_MODEL = 'muse-spark-1.3';
    private const DEFAULT_IMAGE_MODEL = 'muse-image-1.0';
    private const DEFAULT_TRANSCRIBE_MODEL = 'muse-voice-transcribe-1.0';

    /** File transcription accepts at most 10 minutes. */
    private const MAX_AUDIO_SECONDS = 600;

    private const IMAGE_TIMEOUT_SECONDS = 180;
    private const TRANSCRIBE_TIMEOUT_SECONDS = 180;

    /**
     * English names Meta accepts in languageBias.
     *
     * @var array<string, string>
     */
    private const LANGUAGE_BIAS = [
        'ar' => 'Arabic',
        'bn' => 'Bengali',
        'nl' => 'Dutch',
        'en' => 'English',
        'fr' => 'French',
        'de' => 'German',
        'he' => 'Hebrew',
        'hi' => 'Hindi',
        'id' => 'Indonesian',
        'it' => 'Italian',
        'ja' => 'Japanese',
        'kn' => 'Kannada',
        'ko' => 'Korean',
        'ms' => 'Malay',
        'zh' => 'Mandarin Chinese',
        'mr' => 'Marathi',
        'pl' => 'Polish',
        'pt' => 'Portuguese',
        'es' => 'Spanish',
        'tl' => 'Tagalog',
        'ta' => 'Tamil',
        'te' => 'Telugu',
        'th' => 'Thai',
        'tr' => 'Turkish',
        'vi' => 'Vietnamese',
    ];

    private readonly HttpClientInterface $httpClient;

    public function __construct(
        LoggerInterface $logger,
        ?string $apiKey = null,
        string $uploadDir = '/var/www/backend/var/uploads',
        ?ProviderKeyStore $keyStore = null,
        ?StructuredOutputTranslator $structuredOutputTranslator = null,
        ?HttpClientInterface $httpClient = null,
    ) {
        parent::__construct(
            $logger,
            $apiKey,
            $uploadDir,
            $keyStore,
            $structuredOutputTranslator ?? new StructuredOutputTranslator(new StructuredOutputCapability()),
            $httpClient,
        );
        if (null === $httpClient) {
            throw new \LogicException('MetaProvider requires the shared HTTP client.');
        }
        $this->httpClient = $httpClient;
    }

    /** @var list<string> */
    private const REASONING_EFFORTS = ['minimal', 'low', 'medium', 'high', 'xhigh', 'max'];

    public static function descriptor(): ProviderDescriptor
    {
        return new ProviderDescriptor(
            name: self::PROVIDER_NAME,
            displayName: 'Meta',
            capabilities: [ProviderCapability::Chat, ProviderCapability::Vision, ProviderCapability::ImageGeneration, ProviderCapability::SpeechToText],
            endpoints: [ProviderEndpoint::chatCompletions(self::BASE_URI.'/chat/completions')],
            key: ProviderKeySpec::platform(
                envVar: 'META_API_KEY',
                consoleUrl: 'https://dev.meta.ai/',
                validation: ProviderKeyValidation::bearer(self::BASE_URI.'/models'),
                listOrder: 9,
                modelListing: ModelListing::Lists,
                fieldDescription: 'Meta Model API key — Muse Spark, Muse Wonder, Muse Image, and Muse Voice Transcribe. Create it at https://dev.meta.ai/ (API keys)',
            ),
            dialects: new ProviderDialects(
                structuredOutput: StructuredOutputDialect::OPENAI_JSON_SCHEMA,
                catalogToolUse: true,
                reasoning: ReasoningLadder::always(self::REASONING_EFFORTS),
            ),
            defaults: new ProviderDefaults(bindings: [
                'CHAT' => 'meta:muse-spark-1.3:chat',
                'TOOLS' => 'meta:muse-spark-1.3:chat',
                'ANALYZE' => 'meta:muse-spark-1.3:chat',
                'SORT' => 'meta:muse-spark-1.3:chat',
                'PLAN' => 'meta:muse-spark-1.3:chat',
                'SUMMARIZE' => 'meta:muse-spark-1.3:chat',
                'PIC2TEXT' => 'meta:muse-spark-1.3:pic2text',
            ], preferenceRank: 10),
            pricing: ProviderPricing::byServiceName(),
            liveVoiceSessions: null,
            branding: new ProviderBranding(icon: 'logos:meta-icon', flag: 'circle-flags:us', helpKey: 'meta'),
        );
    }

    public function getName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function getDisplayName(): string
    {
        return 'Meta';
    }

    public function getDescription(): string
    {
        return 'Meta Model API (Muse Spark, Muse Wonder, Muse Image, and Muse Voice Transcribe). Prompts are processed by Meta.';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'vision', 'image_generation', 'speech_to_text'];
    }

    public function getDefaultModels(): array
    {
        return [
            'chat' => self::DEFAULT_CHAT_MODEL,
            'vision' => self::DEFAULT_VISION_MODEL,
            'image_generation' => self::DEFAULT_IMAGE_MODEL,
            'speech_to_text' => self::DEFAULT_TRANSCRIBE_MODEL,
        ];
    }

    protected function baseUri(): string
    {
        return self::BASE_URI;
    }

    protected function envVarName(): string
    {
        return 'META_API_KEY';
    }

    protected function envVarHint(): string
    {
        return 'Create an API key at https://dev.meta.ai/ (API keys → Create API key). Meta\'s own SDK calls this MODEL_API_KEY.';
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options
     *
     * @return array<string, mixed>
     */
    protected function buildChatOptions(array $messages, array $options, bool $stream): array
    {
        $request = parent::buildChatOptions($messages, $options, $stream);

        $effort = $this->resolveReasoningEffort($options);
        if (null !== $effort) {
            $request['reasoning_effort'] = $effort;
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveReasoningEffort(array $options): ?string
    {
        $explicit = $options['reasoning_effort'] ?? null;
        if (is_string($explicit) && in_array(strtolower($explicit), self::REASONING_EFFORTS, true)) {
            return strtolower($explicit);
        }

        if (!array_key_exists('reasoning', $options)) {
            return null;
        }

        $features = $options['modelFeatures'] ?? null;
        if (is_array($features) && !in_array('reasoning', $features, true)) {
            return null;
        }

        if (!$this->isReasoningEnabled($options['reasoning'])) {
            return 'minimal';
        }

        $config = $options['modelConfig'] ?? null;
        $default = is_array($config) ? ($config['reasoning_effort_default'] ?? null) : null;
        if (is_string($default) && in_array($default, self::REASONING_EFFORTS, true)) {
            return $default;
        }

        return 'high';
    }

    private function isReasoningEnabled(mixed $reasoning): bool
    {
        if (is_array($reasoning)) {
            return [] !== $reasoning;
        }

        return (bool) $reasoning;
    }

    // ==================== IMAGE GENERATION (Muse Image) ====================

    /**
     * Text-to-image, or an edit when reference images are attached.
     *
     * One model covers both. A request with no images calls
     * `/images/generations`; a request with images calls `/images/edits`.
     * The price is a flat $0.01 per image the API actually returns.
     *
     * @param array<string, mixed> $options
     *
     * @return list<array{url: string, b64_json: string, revised_prompt: null}>
     */
    public function generateImage(string $prompt, array $options = []): array
    {
        $this->assertApiKey();

        $prompt = trim($prompt);
        if ('' === $prompt) {
            throw new ProviderException('Describe the picture you want before generating it.', self::PROVIDER_NAME);
        }

        $references = $this->referenceImages($options);
        $body = [
            'model' => $this->optionString($options, 'model', self::DEFAULT_IMAGE_MODEL),
            'prompt' => $prompt,
            'n' => $this->imageCount($options),
            'response_format' => 'b64_json',
            'output_format' => 'png',
        ];
        $size = $options['size'] ?? null;
        if (is_string($size) && 1 === preg_match('/^\d+x\d+$/', $size)) {
            $body['size'] = $size;
        }

        $endpoint = '/images/generations';
        if ([] !== $references) {
            $endpoint = '/images/edits';
            $body['images'] = array_map(
                fn (string $path): array => ['image_url' => $this->imageToDataUrl($path)],
                $references,
            );
        }

        $this->logger->info('Meta: generateImage', [
            'endpoint' => $endpoint,
            'model' => $body['model'],
            'references' => count($references),
            'n' => $body['n'],
        ]);

        $payload = $this->postJson(self::BASE_URI.$endpoint, $body, self::IMAGE_TIMEOUT_SECONDS);

        return $this->imagesFromPayload($payload);
    }

    public function createVariations(string $imageUrl, int $count = 1): array
    {
        throw new ProviderException('Muse Image has no variation endpoint. Attach the picture and describe the change instead.', self::PROVIDER_NAME);
    }

    public function editImage(string $imageUrl, string $maskUrl, string $prompt): string
    {
        throw new ProviderException('Muse Image does not take a mask. Attach the picture and describe the change instead.', self::PROVIDER_NAME);
    }

    // ==================== SPEECH TO TEXT (Muse Voice Transcribe) ====================

    /**
     * Transcribe one recording. Live WebSocket streaming is not used.
     *
     * The file endpoint accepts only mono 16-bit PCM WAV at 16 or 24 kHz and
     * at most 10 minutes, so other formats are converted with ffmpeg first.
     *
     * @param array<string, mixed> $options
     *
     * @return array{text: string, language: string, duration: float, segments: list<array<string, mixed>>}
     */
    public function transcribe(string $audioPath, array $options = []): array
    {
        $this->assertApiKey();

        $source = $this->existingAudioPath($audioPath);
        $prepared = $this->prepareWav($source);

        try {
            $duration = $this->wavDurationSeconds($prepared['path']);
            if ($duration > self::MAX_AUDIO_SECONDS) {
                throw new ProviderException('This recording is longer than 10 minutes. Split it into shorter clips. Muse Voice Transcribe accepts up to 10 minutes per file.', self::PROVIDER_NAME);
            }

            $request = [
                'model' => $this->optionString($options, 'model', self::DEFAULT_TRANSCRIBE_MODEL),
                'audioEncoding' => 'WAV',
                'mode' => 'PUSH_TO_TALK',
            ];
            $bias = $this->languageBias($options['language'] ?? null);
            if (null !== $bias) {
                $request['languageBias'] = [$bias];
            }

            $form = new FormDataPart([
                'request' => new DataPart(
                    json_encode($request, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
                    null,
                    'application/json',
                ),
                'audio' => DataPart::fromPath($prepared['path'], 'audio.wav', 'audio/wav'),
            ]);

            $this->logger->info('Meta: transcribe', [
                'model' => $request['model'],
                'file' => basename($source),
                'language' => $bias,
            ]);

            $key = $this->resolveApiKey();
            $response = $this->httpClient->request('POST', self::BASE_URI.'/asr/transcribe', [
                'auth_bearer' => $key,
                'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToIterable(),
                'timeout' => self::TRANSCRIBE_TIMEOUT_SECONDS,
            ]);

            $payload = $this->decodeResponse($response, 'transcription');

            return [
                'text' => is_string($payload['transcript'] ?? null) ? $payload['transcript'] : '',
                'language' => $bias ?? 'unknown',
                'duration' => $this->billedSeconds($payload, $duration),
                'segments' => [],
            ];
        } finally {
            if ($prepared['temporary'] && is_file($prepared['path'])) {
                unlink($prepared['path']);
            }
        }
    }

    public function translateAudio(string $audioPath, string $targetLang): string
    {
        throw new ProviderException('Muse Voice Transcribe does not translate audio. It returns a transcript in the language that was spoken.', self::PROVIDER_NAME);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<string>
     */
    private function referenceImages(array $options): array
    {
        $images = $options['images'] ?? [];
        if (!is_array($images)) {
            return [];
        }

        $paths = [];
        foreach ($images as $image) {
            if (is_string($image) && '' !== $image) {
                $paths[] = $image;
            }
        }

        return $paths;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function imageCount(array $options): int
    {
        $count = $options['n'] ?? 1;
        if (!is_int($count) && !(is_string($count) && ctype_digit($count))) {
            return 1;
        }

        return max(1, min(10, (int) $count));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function optionString(array $options, string $key, string $fallback): string
    {
        $value = $options[$key] ?? null;

        return is_string($value) && '' !== trim($value) ? $value : $fallback;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $body, int $timeout): array
    {
        $response = $this->httpClient->request('POST', $url, [
            'auth_bearer' => $this->resolveApiKey(),
            'json' => $body,
            'timeout' => $timeout,
        ]);

        return $this->decodeResponse($response, 'image generation');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(\Symfony\Contracts\HttpClient\ResponseInterface $response, string $action): array
    {
        $status = $response->getStatusCode();
        try {
            $payload = $response->toArray(false);
        } catch (\Throwable) {
            $payload = [];
        }

        if ($status >= 400) {
            throw new ProviderException($this->apiErrorMessage($payload, $status, $action), self::PROVIDER_NAME);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function apiErrorMessage(array $payload, int $status, string $action): string
    {
        $error = $payload['error'] ?? null;
        $message = is_array($error) ? ($error['message'] ?? null) : null;
        if (!is_string($message) || '' === trim($message)) {
            $message = 'Meta '.$action.' failed.';
        }

        return $message.' (HTTP '.$status.')';
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array{url: string, b64_json: string, revised_prompt: null}>
     */
    private function imagesFromPayload(array $payload): array
    {
        $items = $payload['data'] ?? null;
        if (!is_array($items) || [] === $items) {
            throw new ProviderException('Muse Image did not return a picture. The request may have been blocked by the safety filter.', self::PROVIDER_NAME);
        }

        $mime = match ($payload['output_format'] ?? 'png') {
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        $images = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $b64 = $item['b64_json'] ?? null;
            if (!is_string($b64) || '' === $b64) {
                continue;
            }
            $images[] = [
                'url' => 'data:'.$mime.';base64,'.$b64,
                'b64_json' => $b64,
                'revised_prompt' => null,
            ];
        }

        if ([] === $images) {
            throw new ProviderException('Muse Image did not return a picture. The request may have been blocked by the safety filter.', self::PROVIDER_NAME);
        }

        return $images;
    }

    private function existingAudioPath(string $audioPath): string
    {
        if (is_file($audioPath)) {
            return $audioPath;
        }

        throw new ProviderException('Audio file not found.', self::PROVIDER_NAME);
    }

    /**
     * @return array{path: string, temporary: bool}
     */
    private function prepareWav(string $source): array
    {
        if ($this->isSupportedWav($source)) {
            return ['path' => $source, 'temporary' => false];
        }

        $target = sys_get_temp_dir().'/'.uniqid('meta_asr_', true).'.wav';
        $process = new Process([
            'ffmpeg',
            '-y',
            '-loglevel', 'error',
            '-i', $source,
            // 16 kHz is accepted and keeps a 10-minute file under the 32 MB body cap.
            '-ar', '16000',
            '-ac', '1',
            '-c:a', 'pcm_s16le',
            '-f', 'wav',
            '-vn',
            '-map_metadata', '-1',
            $target,
        ]);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful() || !is_file($target)) {
            if (is_file($target)) {
                unlink($target);
            }
            $detail = trim($process->getErrorOutput());
            throw new ProviderException('This recording could not be prepared for Muse Voice Transcribe.'.('' !== $detail ? ' '.$detail : ' ffmpeg is required to convert it to WAV.'), self::PROVIDER_NAME);
        }

        return ['path' => $target, 'temporary' => true];
    }

    private function isSupportedWav(string $path): bool
    {
        $fmt = $this->wavFormat($path);

        return null !== $fmt
            && 1 === $fmt['format']
            && 1 === $fmt['channels']
            && in_array($fmt['rate'], [16000, 24000], true)
            && 16 === $fmt['bits'];
    }

    private function wavDurationSeconds(string $path): float
    {
        $fmt = $this->wavFormat($path);
        if (null === $fmt || $fmt['rate'] < 1 || $fmt['channels'] < 1 || $fmt['bits'] < 8) {
            return 0.0;
        }

        $bytesPerSecond = $fmt['rate'] * $fmt['channels'] * ($fmt['bits'] / 8);
        if ($bytesPerSecond <= 0) {
            return 0.0;
        }

        return $fmt['dataBytes'] / $bytesPerSecond;
    }

    /**
     * @return array{format: int, channels: int, rate: int, bits: int, dataBytes: int}|null
     */
    private function wavFormat(string $path): ?array
    {
        $handle = fopen($path, 'rb');
        if (false === $handle) {
            return null;
        }

        try {
            $riff = fread($handle, 12);
            if (!is_string($riff) || 12 !== strlen($riff) || 'RIFF' !== substr($riff, 0, 4) || 'WAVE' !== substr($riff, 8, 4)) {
                return null;
            }

            $format = null;
            $dataBytes = 0;
            while (!feof($handle)) {
                $header = fread($handle, 8);
                if (!is_string($header) || 8 !== strlen($header)) {
                    break;
                }
                $id = substr($header, 0, 4);
                $size = unpack('V', substr($header, 4, 4));
                $length = is_array($size) ? (int) $size[1] : 0;
                if ('fmt ' === $id) {
                    $fmt = fread($handle, 16);
                    if (!is_string($fmt) || 16 !== strlen($fmt)) {
                        return null;
                    }
                    $unpacked = unpack('vformat/vchannels/Vrate/VbyteRate/vblock/vbits', $fmt);
                    if (!is_array($unpacked)) {
                        return null;
                    }
                    $format = [
                        'format' => (int) $unpacked['format'],
                        'channels' => (int) $unpacked['channels'],
                        'rate' => (int) $unpacked['rate'],
                        'bits' => (int) $unpacked['bits'],
                    ];
                    $skip = $length - 16;
                    if ($skip > 0) {
                        fseek($handle, $skip + ($length % 2), SEEK_CUR);
                    }
                    continue;
                }
                if ('data' === $id) {
                    $dataBytes = $length;
                    break;
                }
                fseek($handle, $length + ($length % 2), SEEK_CUR);
            }

            if (null === $format) {
                return null;
            }

            $format['dataBytes'] = $dataBytes;

            return $format;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Meta bills whole seconds, rounded down. Prefer the duration the API
     * reports; fall back to the WAV length when the field is missing.
     *
     * @param array<string, mixed> $payload
     */
    private function billedSeconds(array $payload, float $wavSeconds): float
    {
        $reported = $payload['audioDurationMs'] ?? null;
        if (is_int($reported) || (is_string($reported) && ctype_digit($reported))) {
            return (float) intdiv((int) $reported, 1000);
        }

        return (float) intdiv((int) floor($wavSeconds), 1);
    }

    private function languageBias(mixed $language): ?string
    {
        if (!is_string($language) || '' === trim($language)) {
            return null;
        }

        $normalized = strtolower(str_replace('_', '-', trim($language)));
        $code = explode('-', $normalized)[0];
        if (isset(self::LANGUAGE_BIAS[$code])) {
            return self::LANGUAGE_BIAS[$code];
        }

        foreach (self::LANGUAGE_BIAS as $name) {
            if (strtolower($name) === $normalized) {
                return $name;
            }
        }

        return null;
    }
}
