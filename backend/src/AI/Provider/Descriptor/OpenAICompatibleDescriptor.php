<?php

declare(strict_types=1);

namespace App\AI\Provider\Descriptor;

use App\AI\Catalog\Attribute\AsAiProvider;
use App\AI\Catalog\DescribesProviderInterface;
use App\AI\Catalog\ProviderBranding;
use App\AI\Catalog\ProviderCapability;
use App\AI\Catalog\ProviderDescriptor;
use App\AI\Catalog\ProviderDialects;
use App\AI\Catalog\ProviderEndpoint;
use App\AI\Catalog\ProviderKeySpec;
use App\AI\Catalog\ProviderPricing;
use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use App\AI\Provider\OpenAICompatibleProvider;
use App\AI\StructuredOutput\StructuredOutputDialect;

/**
 * The descriptor of {@see OpenAICompatibleProvider}, kept beside the adapter
 * because the adapter is at the size limit (`03_contracts.md` §2.3.3).
 */
#[AsAiProvider(adapter: OpenAICompatibleProvider::class)]
final class OpenAICompatibleDescriptor implements DescribesProviderInterface
{
    /** Live voice sessions this install may hold open against compatible realtime endpoints. */
    private const LIVE_VOICE_SESSIONS = 4;

    public static function descriptor(): ProviderDescriptor
    {
        return new ProviderDescriptor(
            name: OpenAiCompatibleEndpointRegistry::PROVIDER_NAME,
            displayName: 'OpenAI Compatible',
            capabilities: [ProviderCapability::Chat, ProviderCapability::Embedding, ProviderCapability::Vision, ProviderCapability::ImageGeneration],
            // Every endpoint is admin-registered; the host comes from the registry.
            endpoints: [ProviderEndpoint::localChatCompletions()],
            key: ProviderKeySpec::endpointRegistry(),
            dialects: new ProviderDialects(structuredOutput: StructuredOutputDialect::OPENAI_JSON_SCHEMA),
            defaults: null,
            pricing: ProviderPricing::byServiceName(),
            liveVoiceSessions: self::LIVE_VOICE_SESSIONS,
            branding: ProviderBranding::local('mdi:server-network', iconComponent: 'OpenAiCompatibleIcon'),
        );
    }
}
