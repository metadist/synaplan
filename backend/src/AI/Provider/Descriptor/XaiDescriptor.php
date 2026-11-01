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
use App\AI\Catalog\ProviderKeySpec;
use App\AI\Catalog\ProviderKeyValidation;
use App\AI\Catalog\ProviderPricing;
use App\AI\Catalog\ReasoningLadder;
use App\AI\Provider\XaiProvider;
use App\AI\StructuredOutput\StructuredOutputDialect;

/**
 * The descriptor of {@see XaiProvider}, kept beside the adapter because the
 * adapter is frozen in the size ledger (`03_contracts.md` §2.3.3).
 */
#[AsAiProvider]
final class XaiDescriptor implements DescribesProviderInterface
{
    public static function descriptor(): ProviderDescriptor
    {
        return new ProviderDescriptor(
            name: 'xai',
            displayName: 'xAI',
            capabilities: [
                ProviderCapability::Chat,
                ProviderCapability::Vision,
                ProviderCapability::ImageGeneration,
                ProviderCapability::VideoGeneration,
                ProviderCapability::TextToSpeech,
                ProviderCapability::SpeechToText,
            ],
            endpoints: [ProviderEndpoint::chatCompletions(XaiProvider::BASE_URI.'/chat/completions')],
            key: ProviderKeySpec::platform(
                envVar: 'XAI_API_KEY',
                consoleUrl: 'https://console.x.ai/',
                validation: ProviderKeyValidation::bearer(XaiProvider::BASE_URI.'/models'),
                listOrder: 11,
                modelListing: ModelListing::Lists,
                fieldDescription: 'xAI (Grok) API key — chat, image understanding, Grok Imagine media, and Grok voice',
            ),
            dialects: new ProviderDialects(
                structuredOutput: StructuredOutputDialect::OPENAI_JSON_SCHEMA,
                catalogToolUse: true,
                // grok-4.5 and grok-4.6 reason at a fixed depth and reject the parameter.
                reasoning: ReasoningLadder::prefixes([
                    'grok-4.7' => XaiProvider::REASONING_EFFORTS_47,
                    'grok-4.3' => XaiProvider::REASONING_EFFORTS,
                ]),
            ),
            defaults: new ProviderDefaults(bindings: [
                'CHAT' => 'xai:grok-4.5:chat',
                'TOOLS' => 'xai:grok-4.5:chat',
                'ANALYZE' => 'xai:grok-4.5:chat',
                'SORT' => 'xai:grok-4.5:chat',
                'PLAN' => 'xai:grok-4.5:chat',
                'SUMMARIZE' => 'xai:grok-4.5:chat',
                'PIC2TEXT' => 'xai:grok-4.5:pic2text',
                // No SOUND2TEXT recommendation: xAI retired grok-stt and offers no
                // replacement; without the entry the capability keeps whatever it
                // was bound to instead of being rebound to a dead model (#1514).
            ], preferenceRank: 8),
            pricing: ProviderPricing::byServiceName(),
            liveVoiceSessions: 10,
            // simple-icons has no `xai` slug; `bxl:xai` is the official slash glyph.
            branding: new ProviderBranding(icon: 'bxl:xai', flag: 'circle-flags:us', helpKey: 'xai'),
        );
    }
}
