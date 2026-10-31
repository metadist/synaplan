<?php

declare(strict_types=1);

namespace Plugin\AcmeAi;

use App\AI\Catalog\Attribute\AsAiProvider;
use App\AI\Catalog\ModelListing;
use App\AI\Catalog\ProviderBranding;
use App\AI\Catalog\ProviderCapability;
use App\AI\Catalog\ProviderDefaults;
use App\AI\Catalog\ProviderDescriptor;
use App\AI\Catalog\ProviderDialects;
use App\AI\Catalog\ProviderEndpoint;
use App\AI\Catalog\ProviderKeyPresentation;
use App\AI\Catalog\ProviderKeySpec;
use App\AI\Catalog\ProviderKeyValidation;
use App\AI\Catalog\ProviderPricing;
use App\AI\Provider\AbstractChatCompletionsCloudProvider;
use App\AI\StructuredOutput\StructuredOutputDialect;

/**
 * Journey J-AU-4: "Acme AI" exists only as this class. Nothing in
 * `backend/src` or `config/` names it; every table it has to reach (keys,
 * defaults, upstreams, the admin schema, the public catalog, the egress
 * allowlist) must find it through the descriptor.
 */
#[AsAiProvider]
final class AcmeAiProvider extends AbstractChatCompletionsCloudProvider
{
    public const PROVIDER_NAME = 'acme';
    public const BASE_URI = 'https://api.acme.example/v1';
    public const ENV_VAR = 'ACME_API_KEY';

    public static function descriptor(): ProviderDescriptor
    {
        return new ProviderDescriptor(
            name: self::PROVIDER_NAME,
            displayName: 'Acme AI',
            capabilities: [ProviderCapability::Chat],
            endpoints: [ProviderEndpoint::chatCompletions(self::BASE_URI.'/chat/completions')],
            key: ProviderKeySpec::platform(
                envVar: self::ENV_VAR,
                consoleUrl: 'https://console.acme.example/keys',
                validation: ProviderKeyValidation::bearer(self::BASE_URI.'/models'),
                listOrder: 99,
                modelListing: ModelListing::Lists,
                fieldDescription: 'Acme AI API key — fixture provider',
                configSection: ProviderKeyPresentation::SECTION_CLOUD,
                configOrder: 99,
            ),
            dialects: new ProviderDialects(
                structuredOutput: StructuredOutputDialect::OPENAI_JSON_SCHEMA,
                catalogToolUse: true,
            ),
            defaults: new ProviderDefaults(bindings: [
                'CHAT' => 'acme:acme-1:chat',
                'SORT' => 'acme:acme-1:chat',
            ], preferenceRank: 99),
            pricing: ProviderPricing::byServiceName(),
            liveVoiceSessions: null,
            branding: new ProviderBranding(icon: 'mdi:robot', flag: 'circle-flags:eu', helpKey: 'acme'),
        );
    }

    public function getName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function getDisplayName(): string
    {
        return 'Acme AI';
    }

    public function getDescription(): string
    {
        return 'Acme AI — fixture OpenAI-compatible provider.';
    }

    public function getCapabilities(): array
    {
        return ['chat'];
    }

    public function getDefaultModels(): array
    {
        return ['chat' => 'acme-1'];
    }

    protected function baseUri(): string
    {
        return self::BASE_URI;
    }

    protected function envVarName(): string
    {
        return self::ENV_VAR;
    }

    protected function envVarHint(): string
    {
        return 'Create an API key at https://console.acme.example/keys.';
    }
}
