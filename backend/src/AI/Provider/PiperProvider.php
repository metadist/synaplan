<?php

namespace App\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Interface\TextToSpeechProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
        $voice = $this->resolveVoice($options);

        $response = $this->httpClient->request('POST', $this->ttsUrl.'/api/tts', [
            'json' => [
                'text' => $text,
                'voice' => $voice,
                'length_scale' => $options['speed'] ?? 1.0,
            ],
        ]);

        if (200 !== $response->getStatusCode()) {
            throw new ProviderException('Piper TTS failed: '.$response->getContent(false), 'piper');
        }

        $wavContent = $response->getContent();

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

    public function synthesizeStream(string $text, array $options = []): \Generator
    {
        $voice = $this->resolveVoice($options);

        $response = $this->httpClient->request('GET', $this->ttsUrl.'/api/tts', [
            'query' => [
                'text' => $text,
                'voice' => $voice,
                'stream' => 'true',
            ],
            'buffer' => false,
        ]);

        if (200 !== $response->getStatusCode()) {
            throw new ProviderException('Piper TTS streaming failed: '.$response->getContent(false), 'piper');
        }

        foreach ($this->httpClient->stream($response) as $chunk) {
            $content = $chunk->getContent();
            if ('' !== $content) {
                yield $content;
            }
        }
    }

    public function getStreamContentType(array $options = []): string
    {
        return 'audio/webm';
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
