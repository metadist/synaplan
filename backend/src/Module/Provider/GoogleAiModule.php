<?php

declare(strict_types=1);

namespace App\Module\Provider;

use App\AI\Credential\ProviderKeyStore;
use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;

/**
 * Google AI (Gemini / Vertex) — provider with three env aliases for the API key
 * plus an admin-entered key in `BCONFIG`.
 *
 * `isConfigured()` follows `ProviderKeyStore::getStatus('google')` so an env
 * key and a key entered at runtime both count, exactly like the provider
 * itself. The Vertex path (`GOOGLE_CLOUD_PROJECT_ID` + access token) is a
 * secondary transport and does not make the module configured on its own.
 */
final class GoogleAiModule implements FeatureModuleInterface
{
    public const ID = 'google_ai';

    private const PROVIDER = 'google';

    public function __construct(
        private readonly ProviderKeyStore $keyStore,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'modules.google_ai.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return new ConfiguredBy(
            envKeys: [
                'GOOGLE_GEMINI_API_KEY',
                'GEMINI_API_KEY',
                'GOOGLE_API_KEY',
                'GOOGLE_CLOUD_PROJECT_ID',
                'GOOGLE_VERTEX_ACCESS_TOKEN',
            ],
            bconfigKeys: ['PROVIDER_KEYS.google'],
            providerKeys: [self::PROVIDER],
        );
    }

    public function isConfigured(): bool
    {
        return $this->keyStore->getStatus(self::PROVIDER)['configured'];
    }

    public function status(): ModuleStatus
    {
        $status = $this->keyStore->getStatus(self::PROVIDER);

        if (!$status['configured']) {
            return ModuleStatus::absent('No Google AI API key in the environment or admin settings');
        }

        return new ModuleStatus(
            configured: true,
            healthy: true,
            message: 'Google AI key present',
            details: ['source' => $status['source']],
        );
    }

    public function capabilityIds(): array
    {
        return [];
    }

    public function routeNames(): array
    {
        return [];
    }

    public function serviceIds(): array
    {
        return [
            'App\AI\Provider\GoogleProvider',
            'App\AI\Messages\Translator\GeminiMessagesTranslator',
            'App\AI\StructuredOutput\GoogleJsonSchemaNormalizer',
        ];
    }

    public function docsAnchor(): string
    {
        return 'modules/google-ai';
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::BackendOnly;
    }
}
