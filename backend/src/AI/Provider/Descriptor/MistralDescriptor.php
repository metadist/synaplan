<?php

declare(strict_types=1);

namespace App\AI\Provider\Descriptor;

use App\AI\Catalog\Attribute\AsAiProvider;
use App\AI\Catalog\DescribesProviderInterface;
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
use App\AI\Provider\MistralProvider;
use App\AI\StructuredOutput\StructuredOutputDialect;

/**
 * The descriptor of {@see MistralProvider}, kept beside the adapter because
 * the adapter is frozen in the size ledger (`03_contracts.md` §2.3.3).
 */
#[AsAiProvider]
final class MistralDescriptor implements DescribesProviderInterface
{
    private const BASE_URL = 'https://api.mistral.ai/v1';

    public static function descriptor(): ProviderDescriptor
    {
        return new ProviderDescriptor(
            name: 'mistral',
            displayName: 'Mistral AI',
            capabilities: [ProviderCapability::Chat, ProviderCapability::Vision, ProviderCapability::SpeechToText, ProviderCapability::TextToSpeech],
            endpoints: [ProviderEndpoint::chatCompletions(MistralProvider::CHAT_ENDPOINT)],
            key: ProviderKeySpec::platform(
                envVar: 'MISTRAL_API_KEY',
                consoleUrl: 'https://console.mistral.ai/api-keys',
                validation: ProviderKeyValidation::bearer(self::BASE_URL.'/models'),
                listOrder: 5,
                modelListing: ModelListing::Lists,
                freeTier: true,
                displayName: 'Mistral',
                fieldDescription: 'Mistral API key — chat, vision and the Voxtral audio pair',
                configSection: ProviderKeyPresentation::SECTION_CLOUD,
                configOrder: 5,
            ),
            dialects: new ProviderDialects(
                structuredOutput: StructuredOutputDialect::OPENAI_JSON_SCHEMA,
                catalogToolUse: true,
            ),
            defaults: new ProviderDefaults(bindings: [
                'CHAT' => 'mistral:mistral-medium-latest:chat',
                'TOOLS' => 'mistral:mistral-medium-latest:chat',
                'ANALYZE' => 'mistral:mistral-medium-latest:chat',
                'SORT' => 'mistral:mistral-medium-latest:chat',
                'PLAN' => 'mistral:mistral-medium-latest:chat',
                'SUMMARIZE' => 'mistral:mistral-medium-latest:chat',
                'PIC2TEXT' => 'mistral:mistral-medium-latest:pic2text',
                'SOUND2TEXT' => 'mistral:voxtral-mini-latest:sound2text',
            ], preferenceRank: 4),
            pricing: ProviderPricing::byServiceName(),
            liveVoiceSessions: null,
            branding: new ProviderBranding(icon: 'simple-icons:mistral', iconComponent: 'MistralIcon', flag: 'circle-flags:fr', helpKey: 'mistral'),
        );
    }
}
