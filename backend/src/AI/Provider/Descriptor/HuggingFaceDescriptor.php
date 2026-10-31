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
use App\AI\Catalog\ReasoningLadder;
use App\AI\Catalog\ReasoningLevelRule;
use App\AI\Catalog\ReasoningMatch;
use App\AI\Provider\HuggingFaceProvider;
use App\AI\StructuredOutput\StructuredOutputDialect;

/**
 * The descriptor of {@see HuggingFaceProvider}, kept beside the adapter
 * because the adapter is frozen in the size ledger (`03_contracts.md` §2.3.3).
 */
#[AsAiProvider]
final class HuggingFaceDescriptor implements DescribesProviderInterface
{
    /** Proves the token; says nothing about served models. */
    private const WHOAMI_URL = 'https://huggingface.co/api/whoami-v2';

    public static function descriptor(): ProviderDescriptor
    {
        return new ProviderDescriptor(
            name: 'huggingface',
            displayName: 'HuggingFace',
            capabilities: [
                ProviderCapability::Chat,
                ProviderCapability::Vision,
                ProviderCapability::Embedding,
                ProviderCapability::ImageGeneration,
                ProviderCapability::VideoGeneration,
            ],
            endpoints: [ProviderEndpoint::chatCompletions(HuggingFaceProvider::CHAT_ENDPOINT)],
            key: ProviderKeySpec::platform(
                envVar: 'HUGGINGFACE_API_KEY',
                consoleUrl: HuggingFaceProvider::TOKENS_URL,
                validation: ProviderKeyValidation::bearer(self::WHOAMI_URL),
                listOrder: 8,
                modelListing: ModelListing::CredentialsOnly,
                freeTier: true,
                fieldDescription: 'HuggingFace API token — routes the Kimi models through HF Inference',
                configSection: ProviderKeyPresentation::SECTION_CLOUD,
                configOrder: 11,
            ),
            dialects: new ProviderDialects(
                structuredOutput: StructuredOutputDialect::OPENAI_JSON_SCHEMA,
                catalogToolUse: true,
                // Kimi K3 accepts low|high|max; K2 rows that advertise reasoning share it.
                reasoning: new ReasoningLadder([
                    new ReasoningLevelRule(ReasoningMatch::Contains, ['kimi-k3', 'kimi-k2'], ['low', 'high', 'max']),
                ]),
            ),
            // Colons inside a catalog providerId are normalized to dashes by
            // ModelCatalog::modelKey() — hence "Kimi-K2.6-deepinfra", not ":deepinfra".
            defaults: new ProviderDefaults(bindings: [
                'CHAT' => 'huggingface:moonshotai/Kimi-K2.6-deepinfra:chat',
                'TOOLS' => 'huggingface:moonshotai/Kimi-K2.6-deepinfra:chat',
                'ANALYZE' => 'huggingface:moonshotai/Kimi-K2.6-deepinfra:chat',
                'SORT' => 'huggingface:moonshotai/Kimi-K2.6-deepinfra:chat',
                'PLAN' => 'huggingface:moonshotai/Kimi-K2.6-deepinfra:chat',
                'SUMMARIZE' => 'huggingface:moonshotai/Kimi-K2.6-deepinfra:chat',
                'PIC2TEXT' => 'huggingface:moonshotai/Kimi-K2.6-deepinfra:pic2text',
            ], preferenceRank: 7),
            pricing: ProviderPricing::litellm(['huggingface']),
            liveVoiceSessions: null,
            branding: new ProviderBranding(icon: 'simple-icons:huggingface', helpKey: 'huggingface'),
        );
    }
}
