<?php

namespace App\AI\Provider\OpenAI;

use App\AI\Exception\ProviderException;
use App\Service\File\FileHelper;
use App\Service\Media\PublicMediaName;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;

/**
 * OpenAI speech for OpenAIProvider: transcription (GPT Transcribe) and
 * Whisper translation to English from the upload directory, speech synthesis into a stored
 * file, and the synthesis stream sent to the client as it arrives.
 */
final class OpenAISpeech
{
    private const DEFAULT_TRANSCRIBE_MODEL = 'gpt-transcribe';
    private const TRANSCRIBE_ENDPOINT = 'https://api.openai.com/v1/audio/transcriptions';
    private const TRANSCRIBE_TIMEOUT_SECONDS = 300;

    public function __construct(
        private readonly OpenAIApiClient $api,
        private readonly LoggerInterface $logger,
        private readonly string $uploadDir,
    ) {
    }

    public function transcribe(string $audioPath, array $options = []): array
    {
        $key = $this->api->key();
        if (null === $key || '' === $key) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $model = (string) ($options['model'] ?? self::DEFAULT_TRANSCRIBE_MODEL);

            // Handle both absolute and relative paths
            $fullPath = str_starts_with($audioPath, '/')
                ? $audioPath
                : $this->uploadDir.'/'.ltrim($audioPath, '/');

            if (!is_file($fullPath)) {
                throw new \RuntimeException('Audio file not found: '.basename($audioPath));
            }

            $this->logger->info('OpenAI: Transcribing audio', [
                'model' => $model,
                'file' => basename($audioPath),
            ]);

            // `json` is the one format every transcription model accepts;
            // gpt-transcribe has no verbose_json. The SDK drops `usage` and
            // `languages` from the answer, so the request goes out directly.
            $fields = [
                'model' => $model,
                'file' => DataPart::fromPath($fullPath),
                'response_format' => 'json',
            ];
            if (\is_string($options['language'] ?? null) && '' !== $options['language']) {
                // GPT Transcribe takes the hint as the `languages` list and
                // must not get the singular field; older models take `language`.
                if (str_starts_with($model, self::DEFAULT_TRANSCRIBE_MODEL)) {
                    $fields[] = ['languages[]' => $options['language']];
                } else {
                    $fields['language'] = $options['language'];
                }
            }

            $response = $this->api->postMultipart(self::TRANSCRIBE_ENDPOINT, $fields, $key, self::TRANSCRIBE_TIMEOUT_SECONDS);
            if (200 !== $response->getStatusCode()) {
                throw new \RuntimeException('HTTP '.$response->getStatusCode().': '.substr($response->getContent(false), 0, 500));
            }

            return self::transcription($response->toArray(false));
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI transcription error: '.$e->getMessage(), 'openai');
        }
    }

    /**
     * The transcription result every STT provider returns, from OpenAI's
     * `json` answer. gpt-transcribe reports the detected language as
     * `languages[0].code` and the audio length as a `duration` usage object;
     * the billing path needs that length, since STT is priced per minute.
     *
     * @param array<string, mixed> $data
     *
     * @return array{text: string, language: string, duration: float, segments: list<mixed>}
     */
    private static function transcription(array $data): array
    {
        $language = $data['language'] ?? null;
        $languages = $data['languages'] ?? null;
        if (!\is_string($language) && \is_array($languages) && \is_array($languages[0] ?? null) && \is_string($languages[0]['code'] ?? null)) {
            $language = $languages[0]['code'];
        }

        $usage = \is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $duration = 'duration' === ($usage['type'] ?? null) && is_numeric($usage['seconds'] ?? null)
            ? (float) $usage['seconds']
            : (is_numeric($data['duration'] ?? null) ? (float) $data['duration'] : 0.0);

        return [
            'text' => \is_string($data['text'] ?? null) ? $data['text'] : '',
            'language' => \is_string($language) && '' !== $language ? $language : 'unknown',
            'duration' => $duration,
            'segments' => [],
        ];
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
            $model = $options['model'] ?? throw self::noSpeechModel();
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
            $filename = PublicMediaName::speech('mp3');
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

        $model = $options['model'] ?? throw self::noSpeechModel();
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

    /**
     * OpenAI has no text-to-speech model left to fall back on: tts-1 and
     * tts-1-hd are retired, and their replacement only speaks over the
     * Realtime API. A caller must name the model.
     */
    private static function noSpeechModel(): ProviderException
    {
        return new ProviderException('OpenAI TTS needs a model: OpenAI has no default text-to-speech model.', 'openai');
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
