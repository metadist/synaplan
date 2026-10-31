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
use App\AI\Catalog\ProviderKeyPresentation;
use App\AI\Catalog\ProviderKeySpec;
use App\AI\Catalog\ProviderKeyValidation;
use App\AI\Catalog\ProviderPricing;
use App\AI\Catalog\UserCredential;
use App\AI\Provider\HiggsfieldProvider;

/**
 * The descriptor of {@see HiggsfieldProvider}, kept beside the adapter
 * because the adapter is frozen in the size ledger (`03_contracts.md` §2.3.3).
 */
#[AsAiProvider(adapter: HiggsfieldProvider::class)]
final class HiggsfieldDescriptor implements DescribesProviderInterface
{
    public static function descriptor(): ProviderDescriptor
    {
        return new ProviderDescriptor(
            name: 'higgsfield',
            displayName: 'Higgsfield',
            capabilities: [ProviderCapability::ImageGeneration, ProviderCapability::VideoGeneration],
            endpoints: [ProviderEndpoint::api(HiggsfieldProvider::BASE_URL)],
            key: ProviderKeySpec::platform(
                envVar: 'HIGGSFIELD_API_KEY',
                consoleUrl: 'https://cloud.higgsfield.ai/',
                // The landing page authenticates the pair; it lists no models and
                // the health probe does not read it (03 §2.3.4 #7).
                validation: ProviderKeyValidation::header(HiggsfieldProvider::BASE_URL.'/', 'Authorization', 'Key {key}:{secret}'),
                listOrder: 14,
                servesChat: false,
                // A user's own pair is resolved at call time by HiggsfieldCredentialResolver;
                // the Messages gateway's BYOK list does not know Higgsfield.
                userCredential: UserCredential::Personal,
                secretEnvVar: 'HIGGSFIELD_API_SECRET',
                fieldDescription: 'Higgsfield API key — Soul/Reve images, DoP and Kling video. Both halves are required',
                secretFieldDescription: 'Higgsfield API secret — the key alone will not authenticate',
                configSection: ProviderKeyPresentation::SECTION_MEDIA,
                configOrder: 2,
            ),
            dialects: new ProviderDialects(),
            defaults: null,
            pricing: ProviderPricing::byServiceName(),
            liveVoiceSessions: null,
            branding: new ProviderBranding(icon: 'mdi:robot'),
        );
    }
}
