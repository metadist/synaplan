<?php

namespace App\AI\Provider\OpenAI;

use App\AI\Exception\ProviderException;
use App\Service\File\FileHelper;
use Psr\Log\LoggerInterface;

/**
 * OpenAI speech for OpenAIProvider: Whisper transcription and translation
 * to English from the upload directory, speech synthesis into a stored
 * file, and the synthesis stream sent to the client as it arrives.
 */
final class OpenAISpeech
{
    public function __construct(
        private readonly OpenAIApiClient $api,
        private readonly LoggerInterface $logger,
        private readonly string $uploadDir,
    ) {
    }

    public function transcribe(string $audioPath, array $options = []): array
    {
        if (null === $this->api->sdk()) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $model = $options['model'] ?? 'whisper-1';

            // Handle both absolute and relative paths
            $fullPath = str_starts_with($audioPath, '/')
                ? $audioPath
                : $this->uploadDir.'/'.ltrim($audioPath, '/');

            if (!file_exists($fullPath)) {
                throw new \Exception("Audio file not found: {$fullPath}");
            }

            $this->logger->info('OpenAI: Transcribing audio', [
                'model' => $model,
                'file' => basename($audioPath),
                'path' => $fullPath,
            ]);

            // Open file and ensure it's a valid resource
            $fileHandle = fopen($fullPath, 'r');
            if (!$fileHandle) {
                throw new \Exception("Failed to open audio file: {$fullPath}");
            }

            try {
                // Build request params - only include language if provided
                $requestParams = [
                    'model' => $model,
                    'file' => $fileHandle,
                    'response_format' => 'verbose_json',
                ];

                if (!empty($options['language'])) {
                    $requestParams['language'] = $options['language'];
                }

                $response = $this->api->sdk()->audio()->transcribe($requestParams);

                return [
                    'text' => $response['text'] ?? '',
                    'language' => $response['language'] ?? 'unknown',
                    'duration' => $response['duration'] ?? 0,
                    'segments' => $response['segments'] ?? [],
                ];
            } finally {
                if (is_resource($fileHandle)) {
                    fclose($fileHandle);
                }
            }
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI transcription error: '.$e->getMessage(), 'openai');
        }
    }

    public function translateAudio(string $audioPath, string $targetLang): string
    {
        if (null === $this->api->sdk()) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            // Handle both absolute and relative paths
            $fullPath = str_starts_with($audioPath, '/')
                ? $audioPath
                : $this->uploadDir.'/'.ltrim($audioPath, '/');

            if (!file_exists($fullPath)) {
                throw new \Exception("Audio file not found: {$fullPath}");
            }

            $this->logger->info('OpenAI: Translating audio', [
                'file' => basename($audioPath),
                'target_lang' => $targetLang,
            ]);

            // Open file and ensure it's a valid resource
            $fileHandle = fopen($fullPath, 'r');
            if (!$fileHandle) {
                throw new \Exception("Failed to open audio file: {$fullPath}");
            }

            try {
                // Whisper's translate endpoint translates to English only
                $response = $this->api->sdk()->audio()->translate([
                    'model' => 'whisper-1',
                    'file' => $fileHandle,
                ]);

                return $response['text'] ?? '';
            } finally {
                if (is_resource($fileHandle)) {
                    fclose($fileHandle);
                }
            }
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI audio translation error: '.$e->getMessage(), 'openai');
        }
    }

    public function synthesize(string $text, array $options = []): string
    {
        if (null === $this->api->sdk()) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $model = $options['model'] ?? 'tts-1';
            $voice = $options['voice'] ?? 'alloy'; // alloy, echo, fable, onyx, nova, shimmer

            $this->logger->info('OpenAI: Synthesizing speech', [
                'model' => $model,
                'voice' => $voice,
                'text_length' => strlen($text),
            ]);

            $response = $this->api->sdk()->audio()->speech([
                'model' => $model,
                'voice' => $voice,
                'input' => $text,
                'response_format' => $options['format'] ?? 'mp3', // mp3, opus, aac, flac
                'speed' => $options['speed'] ?? 1.0,
            ]);

            // Save to temporary file with proper permissions
            $filename = 'tts_'.uniqid().'.mp3';
            $outputPath = $this->uploadDir.'/'.$filename;

            if (!FileHelper::createDirectory($this->uploadDir)) {
                throw new \RuntimeException('Unable to create upload directory: '.$this->uploadDir);
            }

            if (!is_writable($this->uploadDir)) {
                throw new \RuntimeException('Upload directory is not writable: '.$this->uploadDir);
            }

            FileHelper::writeFile($outputPath, $response);

            return $filename;
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI TTS error: '.$e->getMessage(), 'openai');
        }
    }

    public function synthesizeStream(string $text, array $options = []): \Generator
    {
        $key = $this->api->key();
        if (!$key) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        $model = $options['model'] ?? 'tts-1';
        $voice = $options['voice'] ?? 'alloy';
        $format = $options['format'] ?? 'mp3';
        $speed = $options['speed'] ?? 1.0;

        $this->logger->info('OpenAI: Streaming TTS', [
            'model' => $model,
            'voice' => $voice,
            'format' => $format,
            'text_length' => strlen($text),
        ]);

        $response = $this->api->postJson('https://api.openai.com/v1/audio/speech', [
            'model' => $model,
            'voice' => $voice,
            'input' => $text,
            'response_format' => $format,
            'speed' => $speed,
        ], $key, 120, ['Content-Type' => 'application/json'], ['buffer' => false]);

        if (200 !== $response->getStatusCode()) {
            throw new ProviderException('OpenAI TTS stream HTTP '.$response->getStatusCode().': '.substr($response->getContent(false), 0, 500), 'openai');
        }

        foreach ($this->api->stream($response) as $chunk) {
            $content = $chunk->getContent();
            if ('' !== $content) {
                yield $content;
            }
        }
    }

    public function getStreamContentType(array $options = []): string
    {
        $format = $options['format'] ?? 'mp3';

        return match ($format) {
            'opus' => 'audio/ogg',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            default => 'audio/mpeg',
        };
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function getVoices(): array
    {
        // OpenAI TTS voices (static list)
        return [
            [
                'id' => 'alloy',
                'name' => 'Alloy',
                'description' => 'Neutral and balanced voice',
            ],
            [
                'id' => 'echo',
                'name' => 'Echo',
                'description' => 'Male voice',
            ],
            [
                'id' => 'fable',
                'name' => 'Fable',
                'description' => 'British accent',
            ],
            [
                'id' => 'onyx',
                'name' => 'Onyx',
                'description' => 'Deep male voice',
            ],
            [
                'id' => 'nova',
                'name' => 'Nova',
                'description' => 'Female voice',
            ],
            [
                'id' => 'shimmer',
                'name' => 'Shimmer',
                'description' => 'Warm female voice',
            ],
        ];
    }
}
