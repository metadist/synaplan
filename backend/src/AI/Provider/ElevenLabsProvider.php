<?php

declare(strict_types=1);

namespace App\AI\Provider;

use App\AI\Catalog\Attribute\AsAiProvider;
use App\AI\Catalog\DescribesProviderInterface;
use App\AI\Catalog\ProviderBranding;
use App\AI\Catalog\ProviderDescriptor;
use App\AI\Catalog\ProviderDialects;
use App\AI\Catalog\ProviderEndpoint;
use App\AI\Catalog\ProviderKeySpec;
use App\AI\Catalog\ProviderKeyValidation;
use App\AI\Catalog\ProviderPricing;

/**
 * ElevenLabs — a key-only provider.
 *
 * The platform key store, the admin AI-keys page and the system-config tab
 * carry an ElevenLabs row so an operator has one place for every instance
 * key, but no PHP adapter reads it yet: text-to-speech runs on Piper, the
 * cloud voices, or a provider with its own adapter. This class exists so the
 * key row is described where every other provider is described, with no
 * capability and no registry tag.
 */
#[AsAiProvider]
final class ElevenLabsProvider implements DescribesProviderInterface
{
    private const BASE_URL = 'https://api.elevenlabs.io/v1';

    public static function descriptor(): ProviderDescriptor
    {
        return new ProviderDescriptor(
            name: 'elevenlabs',
            displayName: 'ElevenLabs',
            capabilities: [],
            endpoints: [ProviderEndpoint::api(self::BASE_URL)],
            key: ProviderKeySpec::platform(
                envVar: 'ELEVENLABS_API_KEY',
                consoleUrl: 'https://elevenlabs.io/app/settings/api-keys',
                // The user endpoint authenticates the key; it lists no models.
                validation: ProviderKeyValidation::header(self::BASE_URL.'/user', 'xi-api-key'),
                listOrder: 15,
                freeTier: true,
                servesChat: false,
                fieldDescription: 'ElevenLabs TTS API key',
            ),
            dialects: new ProviderDialects(),
            defaults: null,
            pricing: ProviderPricing::byServiceName(),
            liveVoiceSessions: null,
            branding: new ProviderBranding(icon: 'simple-icons:elevenlabs'),
        );
    }
}
