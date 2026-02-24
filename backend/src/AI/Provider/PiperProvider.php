<?php

namespace App\AI\Provider;

use App\AI\Interface\TextToSpeechProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PiperProvider implements TextToSpeechProviderInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $ttsUrl,
        private LoggerInterface $logger,
        private Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%/var/temp')]
        private string $tempDir,
        private string $uploadDir,
    ) {
        // Ensure temp dir exists
        if (!$this->filesystem->exists($this->tempDir)) {
            $this->filesystem->mkdir($this->tempDir);
        }
        // Ensure upload dir exists
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
        // 1. Request WAV from Piper
        $response = $this->httpClient->request('POST', $this->ttsUrl.'/api/tts', [
            'json' => [
                'text' => $text,
                'voice' => $options['voice'] ?? null,
                'language' => $options['language'] ?? null,
                'length_scale' => $options['speed'] ?? 1.0,
            ],
        ]);

        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException('Piper TTS failed: '.$response->getContent(false));
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
            throw new \RuntimeException('FFmpeg conversion failed: '.$process->getErrorOutput());
        }

        // 4. Return filename (AiFacade expects this)
        return $filename;
    }

    public function synthesizeStream(string $text, array $options = []): \Generator
    {
        $response = $this->httpClient->request('GET', $this->ttsUrl.'/api/tts', [
            'query' => [
                'text' => $text,
                'voice' => $options['voice'] ?? null,
                'language' => $options['language'] ?? null,
                'stream' => 'true',
            ],
            'buffer' => false,
        ]);

        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException('Piper TTS streaming failed: '.$response->getContent(false));
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
}
