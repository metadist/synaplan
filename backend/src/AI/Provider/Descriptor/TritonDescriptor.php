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
use App\AI\Provider\TritonProvider;

/**
 * The descriptor of {@see TritonProvider}, kept beside the adapter because
 * the adapter is frozen in the size ledger (`03_contracts.md` §2.3.3).
 */
#[AsAiProvider(adapter: TritonProvider::class)]
final class TritonDescriptor implements DescribesProviderInterface
{
    public static function descriptor(): ProviderDescriptor
    {
        return new ProviderDescriptor(
            name: 'triton',
            displayName: 'NVIDIA Triton',
            capabilities: [ProviderCapability::Chat, ProviderCapability::Embedding],
            // gRPC to TRITON_SERVER_URL; not an HTTP endpoint.
            endpoints: [],
            key: null,
            // No server-side constrained decoding: no schema mode at all.
            dialects: new ProviderDialects(structuredOutput: null),
            defaults: null,
            pricing: ProviderPricing::none(),
            liveVoiceSessions: null,
            branding: ProviderBranding::local('mdi:robot'),
        );
    }
}
