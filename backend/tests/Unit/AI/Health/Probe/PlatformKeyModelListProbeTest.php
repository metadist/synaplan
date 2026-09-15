<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Health\Probe;

use App\AI\Credential\ProviderKeyStore;
use App\AI\Health\Probe\PlatformKeyModelListProbe;
use App\AI\Service\ProviderModelInventoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HuggingFace's whoami-v2 is not a model list but still a credential check.
 * Media/speech providers stay out — they have no cheap authenticated probe.
 */
final class PlatformKeyModelListProbeTest extends TestCase
{
    public function testSupportsListingProvidersAndHuggingFaceCredentialCheck(): void
    {
        $probe = new PlatformKeyModelListProbe(
            $this->createStub(HttpClientInterface::class),
            $this->createStub(ProviderKeyStore::class),
            $this->createStub(ProviderModelInventoryInterface::class),
            new NullLogger(),
        );

        self::assertTrue($probe->supports('groq'));
        self::assertTrue($probe->supports('huggingface'), 'whoami-v2 still tells a bad key from an outage');
        self::assertFalse($probe->supports('thehive'));
        self::assertFalse($probe->supports('higgsfield'));
        self::assertFalse($probe->supports('elevenlabs'));
    }
}
