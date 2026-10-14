<?php

declare(strict_types=1);

namespace App\Service\Stt;

use App\AI\Service\ProviderRegistry;
use App\Service\Config\Runtime\RuntimeConfigContext;
use App\Service\Config\Runtime\RuntimeConfigContributorInterface;

/**
 * Speech-to-text switches: local Whisper, the browser API, and any
 * configured cloud transcription provider.
 */
final readonly class SpeechRuntimeConfig implements RuntimeConfigContributorInterface
{
    public function __construct(
        private WebSpeechConfig $webSpeechConfig,
        private WhisperService $whisperService,
        private ProviderRegistry $providerRegistry,
    ) {
    }

    public function contribute(RuntimeConfigContext $context): array
    {
        $whisperLocalEnabled = ($_ENV['WHISPER_ENABLED'] ?? 'true') === 'true';
        $whisperLocalAvailable = $whisperLocalEnabled && $this->whisperService->isInstalled();
        $apiProvidersAvailable = count($this->providerRegistry->getAvailableProviders(
            'speech_to_text',
            includeTest: false,
            exclude: ['whisper'],
        )) > 0;

        return [
            'speech' => [
                'whisperEnabled' => $whisperLocalAvailable,
                'webSpeechEnabled' => $this->webSpeechConfig->isEnabled(),
                'speechToTextAvailable' => $whisperLocalAvailable || $apiProvidersAvailable,
            ],
        ];
    }
}
