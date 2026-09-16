<?php

declare(strict_types=1);

namespace App\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Interface\SpeechToTextProviderInterface;
use App\Service\WhisperService;

/**
 * Local whisper.cpp speech-to-text. No API key — availability is the
 * WHISPER_ENABLED flag plus the binary shipped in the image.
 */
final readonly class WhisperProvider implements SpeechToTextProviderInterface
{
    public function __construct(
        private WhisperService $whisperService,
    ) {
    }

    public function getName(): string
    {
        return 'whisper';
    }

    public function getDisplayName(): string
    {
        return 'Whisper';
    }

    public function getDescription(): string
    {
        return 'On-server whisper.cpp transcription. No cloud API key required.';
    }

    public function getCapabilities(): array
    {
        return ['speech_to_text'];
    }

    public function getDefaultModels(): array
    {
        return [
            'speech_to_text' => 'whisper',
        ];
    }

    public function getStatus(): array
    {
        $available = $this->whisperService->isAvailable();

        return [
            'healthy' => $available,
            'error' => $available ? null : 'whisper.cpp is disabled or the binary is missing',
        ];
    }

    public function isAvailable(): bool
    {
        return $this->whisperService->isAvailable();
    }

    public function getRequiredEnvVars(): array
    {
        return [
            'WHISPER_ENABLED' => [
                'required' => false,
                'hint' => 'Set to true to expose local whisper.cpp as a SOUND2TEXT model (default true in the image).',
            ],
            'WHISPER_DEFAULT_MODEL' => [
                'required' => false,
                'hint' => 'whisper.cpp model file to use: tiny, base, small, medium, or large.',
            ],
        ];
    }

    public function transcribe(string $audioPath, array $options = []): array
    {
        if (!$this->whisperService->isAvailable()) {
            throw new ProviderException('Local Whisper is not available on this installation', 'whisper');
        }

        $model = $options['model'] ?? null;
        if (null === $model || 'whisper' === $model) {
            unset($options['model']);
        }

        try {
            return $this->whisperService->transcribe($audioPath, $options);
        } catch (\Throwable $e) {
            throw new ProviderException('Whisper transcription failed: '.$e->getMessage(), 'whisper', null, 0, $e);
        }
    }

    public function translateAudio(string $audioPath, string $targetLang): string
    {
        // whisper.cpp --translate always outputs English. The -l flag is an
        // input-language hint, not the target — never pass $targetLang as
        // language (same as OpenAI/Groq: ignore the requested target).
        $result = $this->transcribe($audioPath, [
            'translate' => true,
        ]);

        return $result['text'] ?? '';
    }
}
