<?php

declare(strict_types=1);

namespace App\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Interface\TextToSpeechProviderInterface;
use App\Service\File\FileHelper;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Piper TTS Provider.
 *
 * Self-hosted text-to-speech via the synaplan-tts service (Piper engine).
 * Free, local, multi-language — no API key required.
 *
 * Output: WAV (22050 Hz, 16-bit) → converted to MP3 via ffmpeg.
 */
final class PiperProvider implements TextToSpeechProviderInterface
{
    private const DEFAULT_URL = 'http://host.docker.internal:10200';
    private const MAX_TEXT_LENGTH = 5000;
    private const TIMEOUT = 30;

    /** @var array<string, string> Language → Piper voice mapping */
    private const VOICE_MAP = [
        'en' => 'en_US-lessac-medium',
        'de' => 'de_DE-thorsten-medium',
        'es' => 'es_ES-davefx-medium',
        'tr' => 'tr_TR-dfki-medium',
        'ru' => 'ru_RU-irina-medium',
        'fa' => 'fa_IR-reza_ibrahim-medium',
    ];

    /** @var array<string> Allowed language codes (whitelist for input validation) */
    private const ALLOWED_LANGUAGES = ['en', 'de', 'es', 'tr', 'ru', 'fa'];

    private readonly string $ttsUrl;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $uploadDir,
        ?string $ttsUrl = null,
    ) {
        $this->ttsUrl = !empty($ttsUrl) ? $ttsUrl : self::DEFAULT_URL;
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
        return 'Self-hosted Piper TTS via synaplan-tts. Multi-language (en, de, es, tr, ru, fa). Free, no API key.';
    }

    public function getCapabilities(): array
    {
        return ['text_to_speech'];
    }

    public function getDefaultModels(): array
    {
        return [
            'text_to_speech' => 'piper-multi',
        ];
    }

    public function getStatus(): array
    {
        try {
            $available = $this->isAvailable();

            return [
                'healthy' => $available,
                'latency_ms' => 100,
                'error_rate' => 0.0,
                'active_connections' => 0,
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
        if (empty($this->ttsUrl)) {
            return false;
        }

        try {
            $response = $this->httpClient->request('GET', $this->ttsUrl.'/health', [
                'timeout' => 5,
            ]);

            $data = $response->toArray();

            return 'ok' === ($data['status'] ?? '');
        } catch (\Throwable) {
            return false;
        }
    }

    public function getRequiredEnvVars(): array
    {
        return [
            'SYNAPLAN_TTS_URL' => [
                'required' => false,
                'hint' => 'URL of the synaplan-tts service (default: http://host.docker.internal:10200)',
            ],
        ];
    }

    /**
     * Synthesize text to speech.
     *
     * @param string $text    Text to synthesize (max 5000 chars)
     * @param array  $options Options: language (en|de|es|tr|ru|fa), voice, format
     *
     * @return string Filename of generated MP3 file in uploadDir
     */
    public function synthesize(string $text, array $options = []): string
    {
        if (empty($text)) {
            throw new ProviderException('Text is required for TTS synthesis', 'piper');
        }

        // Truncate text if too long
        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            $text = mb_substr($text, 0, self::MAX_TEXT_LENGTH - 3).'...';
        }

        $language = $this->resolveLanguage($options);

        $this->logger->info('Piper TTS: Synthesizing speech', [
            'language' => $language,
            'text_length' => mb_strlen($text),
        ]);

        try {
            // POST JSON to Piper service
            $response = $this->httpClient->request('POST', $this->ttsUrl.'/api/tts', [
                'json' => [
                    'text' => $text,
                    'language' => $language,
                ],
                'timeout' => self::TIMEOUT,
            ]);

            $statusCode = $response->getStatusCode();
            if (200 !== $statusCode) {
                throw new \RuntimeException('Piper TTS returned HTTP '.$statusCode);
            }

            $wavContent = $response->getContent();
            if (empty($wavContent)) {
                throw new \RuntimeException('Piper TTS returned empty audio');
            }

            // Ensure upload directory exists
            if (!FileHelper::createDirectory($this->uploadDir)) {
                throw new \RuntimeException('Unable to create upload directory: '.$this->uploadDir);
            }

            // Save WAV to temp file
            $wavFilename = 'tts_'.uniqid().'.wav';
            $wavPath = $this->uploadDir.'/'.$wavFilename;
            FileHelper::writeFile($wavPath, $wavContent);

            // Convert WAV → MP3
            $mp3Path = $this->convertWavToMp3($wavPath);
            $mp3Filename = basename($mp3Path);

            $this->logger->info('Piper TTS: Synthesis complete', [
                'filename' => $mp3Filename,
                'size' => filesize($mp3Path),
            ]);

            return $mp3Filename;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('Piper TTS: Synthesis failed', [
                'error' => $e->getMessage(),
            ]);

            throw new ProviderException('Piper TTS synthesis failed: '.$e->getMessage(), 'piper', null, 0, $e);
        }
    }

    /**
     * Get available voices.
     *
     * @return array<array{id: string, name: string, language: string}>
     */
    public function getVoices(): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->ttsUrl.'/api/voices', [
                'timeout' => 10,
            ]);

            return $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->warning('Piper TTS: Failed to fetch voices', [
                'error' => $e->getMessage(),
            ]);

            // Return static voice list as fallback
            $voices = [];
            foreach (self::VOICE_MAP as $lang => $voiceId) {
                $voices[] = [
                    'id' => $voiceId,
                    'name' => $voiceId,
                    'language' => $lang,
                ];
            }

            return $voices;
        }
    }

    /**
     * Resolve language from options with whitelist validation.
     */
    private function resolveLanguage(array $options): string
    {
        $lang = $options['language'] ?? 'en';

        // Normalize: take first 2 chars (e.g., 'en-US' → 'en')
        $lang = strtolower(substr($lang, 0, 2));

        // Validate against whitelist
        if (!in_array($lang, self::ALLOWED_LANGUAGES, true)) {
            $this->logger->debug('Piper TTS: Unsupported language, falling back to en', [
                'requested' => $lang,
            ]);

            return 'en';
        }

        return $lang;
    }

    /**
     * Convert WAV to MP3 using ffmpeg.
     *
     * @return string Absolute path to the generated MP3 file
     */
    private function convertWavToMp3(string $wavPath): string
    {
        $mp3Path = preg_replace('/\.wav$/', '.mp3', $wavPath);

        $cmd = sprintf(
            'ffmpeg -i %s -codec:a libmp3lame -qscale:a 4 -y %s 2>&1',
            escapeshellarg($wavPath),
            escapeshellarg($mp3Path)
        );

        exec($cmd, $output, $exitCode);

        if (0 !== $exitCode) {
            // Clean up WAV on failure
            @unlink($wavPath);

            throw new \RuntimeException('ffmpeg WAV→MP3 conversion failed: '.implode("\n", $output));
        }

        // Clean up WAV file after successful conversion
        @unlink($wavPath);

        return $mp3Path;
    }
}
