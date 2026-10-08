<?php

namespace App\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Interface\TextToSpeechProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class PiperProvider implements TextToSpeechProviderInterface
{
    /**
     * Frontend language selects the voice.
     *
     * ChatView sends the UI locale, then prefers the backend-detected reply
     * language (meta.language) when streaming TTS. The five keys en/de/es/fr/tr
     * match the voices baked into ghcr.io/metadist/synaplan-tts. ru/fa resolve
     * only when the operator added those extras under EXTRA_VOICES_DIR.
     *
     * @var array<string, string>
     */
    private const LANGUAGE_VOICE_MAP = [
        'en' => 'en_US-lessac-medium',
        'de' => 'de_DE-kerstin-low',
        'es' => 'es_ES-davefx-medium',
        'fr' => 'fr_FR-siwis-medium',
        'tr' => 'tr_TR-dfki-medium',
        'ru' => 'ru_RU-irina-medium',
        'fa' => 'fa_IR-reza_ibrahim-medium',
    ];

    private const DEFAULT_VOICE = 'en_US-lessac-medium';

    /** Requested formats the native WebM stream already satisfies. */
    private const WEBM_STREAM_FORMATS = ['webm', 'opus'];

    private const MIN_SPEED = 0.25;
    private const MAX_SPEED = 4.0;

    private const ERROR_EXCERPT_LENGTH = 500;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $ttsUrl,
        private LoggerInterface $logger,
        private Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%/var/temp')]
        private string $tempDir,
        private string $uploadDir,
    ) {
        if (!$this->filesystem->exists($this->tempDir)) {
            $this->filesystem->mkdir($this->tempDir);
        }
        if (!$this->filesystem->exists($this->uploadDir)) {
            $this->filesystem->mkdir($this->uploadDir);
        }
    }

    public function getName(): string
    {
        return 'piper';
    }

    public function getDisplayName(): string
    {
        return 'Piper TTS';
    }

    public function getDescription(): string
    {
        return 'Self-hosted neural text-to-speech using Piper.';
    }

    public function getCapabilities(): array
    {
        return ['text2sound'];
    }

    public function getDefaultModels(): array
    {
        return [
            'text2sound' => 'en_US-lessac-medium',
        ];
    }

    public function getStatus(): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->ttsUrl.'/health');
            $data = $response->toArray();

            return [
                'healthy' => ($data['status'] ?? '') === 'ok',
                'error' => null,
                'details' => $data,
            ];
        } catch (\Throwable $e) {
            return [
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function isAvailable(): bool
    {
        return !empty($this->ttsUrl);
    }

    public function getRequiredEnvVars(): array
    {
        return [
            'SYNAPLAN_TTS_URL' => [
                'required' => true,
                'hint' => 'URL of the Synaplan TTS service (e.g. http://synaplan-tts:10200)',
            ],
        ];
    }

    public function getVoices(): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->ttsUrl.'/api/voices');

            return $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch Piper voices: '.$e->getMessage());

            return [];
        }
    }

    public function synthesize(string $text, array $options = []): string
    {
        $wavContent = $this->synthesizeWav($text, $this->resolveVoice($options), $options);

        // 2. Save WAV to temp file
        $wavPath = $this->tempDir.'/'.uniqid('piper_', true).'.wav';
        $this->filesystem->dumpFile($wavPath, $wavContent);

        // 3. Convert to MP3 using ffmpeg
        $filename = 'tts_'.uniqid().'.mp3';
        $mp3Path = $this->uploadDir.'/'.$filename;

        $process = new Process([
            'ffmpeg',
            '-i', $wavPath,
            '-codec:a', 'libmp3lame',
            '-qscale:a', '2', // High quality VBR
            '-y', // Overwrite
            $mp3Path,
        ]);

        $process->run();

        // Cleanup WAV
        $this->filesystem->remove($wavPath);

        if (!$process->isSuccessful()) {
            throw new ProviderException('FFmpeg conversion failed: '.$process->getErrorOutput(), 'piper');
        }

        // 4. Return filename (AiFacade expects this)
        return $filename;
    }

    /**
     * Not a generator itself: the Piper request and its status check run
     * before the caller sends response headers, so a failing or unreachable
     * TTS service surfaces as an error instead of an empty 200 audio body.
     */
    public function synthesizeStream(string $text, array $options = []): \Generator
    {
        $voice = $this->resolveVoice($options);

        if ($this->streamsWav($options)) {
            return self::yieldOnce($this->synthesizeWav($text, $voice, $options));
        }

        $response = $this->httpClient->request('GET', $this->ttsUrl.'/api/tts', [
            'query' => [
                'text' => $text,
                'voice' => $voice,
                'stream' => 'true',
            ],
            'buffer' => false,
        ]);

        if (200 !== $response->getStatusCode()) {
            throw new ProviderException('Piper TTS streaming failed: '.self::errorExcerpt($response), 'piper');
        }

        return $this->streamChunks($response);
    }

    /**
     * @return \Generator<int, string, void, void>
     */
    private function streamChunks(ResponseInterface $response): \Generator
    {
        foreach ($this->httpClient->stream($response) as $chunk) {
            $content = $chunk->getContent();
            if ('' !== $content) {
                yield $content;
            }
        }
    }

    /**
     * @return \Generator<int, string, void, void>
     */
    private static function yieldOnce(string $content): \Generator
    {
        yield $content;
    }

    public function getStreamContentType(array $options = []): string
    {
        return $this->streamsWav($options) ? 'audio/wav' : 'audio/webm';
    }

    /**
     * Piper's streaming endpoint only produces WebM/Opus, which some clients
     * (AVFoundation on iOS) cannot play. A caller that explicitly asks for
     * another format gets one complete WAV body instead; callers that send no
     * format keep the WebM stream.
     *
     * @param array<string, mixed> $options
     */
    private function streamsWav(array $options): bool
    {
        $format = strtolower(trim((string) ($options['format'] ?? '')));

        return '' !== $format && !in_array($format, self::WEBM_STREAM_FORMATS, true);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function synthesizeWav(string $text, string $voice, array $options): string
    {
        $response = $this->httpClient->request('POST', $this->ttsUrl.'/api/tts', [
            'json' => [
                'text' => $text,
                'voice' => $voice,
                'length_scale' => self::lengthScale($options),
            ],
        ]);

        if (200 !== $response->getStatusCode()) {
            throw new ProviderException('Piper TTS failed: '.self::errorExcerpt($response), 'piper');
        }

        return $response->getContent();
    }

    /**
     * Piper's length_scale is the inverse of a speed factor: below 1.0 speaks
     * faster, above 1.0 slower.
     *
     * @param array<string, mixed> $options
     */
    private static function lengthScale(array $options): float
    {
        $speed = is_numeric($options['speed'] ?? null) ? (float) $options['speed'] : 1.0;
        $speed = max(self::MIN_SPEED, min(self::MAX_SPEED, $speed));

        return 1.0 / $speed;
    }

    private static function errorExcerpt(ResponseInterface $response): string
    {
        return 'HTTP '.$response->getStatusCode().': '.substr($response->getContent(false), 0, self::ERROR_EXCERPT_LENGTH);
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    /**
     * Resolve the Piper voice name from explicit voice, message language,
     * or the user's configured voice model.
     *
     * Priority (issue #490):
     *   1. Explicit per-request `voice` (deliberate override) always wins.
     *   2. A voice matching the message `language`, so e.g. a German reply is
     *      pronounced in German even when the configured default voice targets
     *      another language. If the configured `model` already targets that
     *      language, it is kept (respects a user's specific voice choice).
     *   3. The user's configured `model` (the TEXT2SOUND default is a Piper
     *      voice name) — used when the language is unknown/unmapped, instead of
     *      silently falling back to the English default.
     *   4. The English default as a last resort.
     *
     * Handles both short ("de") and locale ("de_DE" / "de-DE") language codes.
     *
     * @param array<string, mixed> $options
     */
    private function resolveVoice(array $options): string
    {
        if (!empty($options['voice'])) {
            return (string) $options['voice'];
        }

        $configuredModel = !empty($options['model']) ? (string) $options['model'] : '';

        // Normalize locale codes (e.g. "de_DE" or "de-DE") to short form.
        $shortLang = strtolower(substr((string) ($options['language'] ?? ''), 0, 2));

        if ('' !== $shortLang && isset(self::LANGUAGE_VOICE_MAP[$shortLang])) {
            // Keep the configured voice when it already targets this language
            // (Piper voice names are locale-prefixed, e.g. "de_DE-...").
            if ('' !== $configuredModel && str_starts_with(strtolower($configuredModel), $shortLang)) {
                return $configuredModel;
            }

            return self::LANGUAGE_VOICE_MAP[$shortLang];
        }

        if ('' !== $configuredModel) {
            return $configuredModel;
        }

        return self::DEFAULT_VOICE;
    }
}
