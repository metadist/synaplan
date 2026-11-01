<?php

declare(strict_types=1);

namespace App\AI\Provider\Descriptor;

use App\AI\Catalog\Attribute\AsAiProvider;
use App\AI\Catalog\DescribesProviderInterface;
use App\AI\Catalog\ProviderBranding;
use App\AI\Catalog\ProviderCapability;
use App\AI\Catalog\ProviderDescriptor;
use App\AI\Catalog\ProviderDialects;
use App\AI\Catalog\ProviderPricing;
use App\AI\Provider\TestProvider;

/**
 * The descriptor of {@see TestProvider}, kept beside the adapter because the
 * adapter is frozen in the size ledger (`03_contracts.md` §2.3.3). The mock
 * is a dev and test fixture; `PerfTestProviderPass` removes its service
 * elsewhere, and this descriptor goes with it.
 */
#[AsAiProvider(adapter: TestProvider::class)]
final class TestProviderDescriptor implements DescribesProviderInterface
{
    public static function descriptor(): ProviderDescriptor
    {
        return new ProviderDescriptor(
            name: 'test',
            displayName: 'Test Provider',
            capabilities: [
                ProviderCapability::Chat,
                ProviderCapability::Embedding,
                ProviderCapability::Vision,
                ProviderCapability::ImageGeneration,
                ProviderCapability::SpeechToText,
                ProviderCapability::TextToSpeech,
                ProviderCapability::FileAnalysis,
            ],
            endpoints: [],
            key: null,
            dialects: new ProviderDialects(),
            defaults: null,
            pricing: ProviderPricing::none(),
            liveVoiceSessions: null,
            branding: new ProviderBranding(icon: 'mdi:robot'),
        );
    }
}
