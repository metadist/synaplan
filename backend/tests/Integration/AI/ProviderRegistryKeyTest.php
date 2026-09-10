<?php

declare(strict_types=1);

namespace App\Tests\Integration\AI;

use App\AI\Service\ProviderRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The lazy registry indexes providers by the `key` attribute of their
 * `app.ai.*` tags in services.yaml instead of calling getName() on every
 * instance at boot. This is the one place that instantiates every registered
 * provider and proves each key equals the provider's own name — a typo in a
 * tag would otherwise make a provider unreachable by name.
 */
final class ProviderRegistryKeyTest extends KernelTestCase
{
    public function testEveryTagKeyEqualsTheProviderName(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(ProviderRegistry::class);
        \assert($registry instanceof ProviderRegistry);

        $checked = 0;
        foreach (ProviderRegistry::CAPABILITIES as $capability) {
            $names = $registry->getRegisteredProviderNames($capability);
            $providers = $registry->getProvidersForCapability($capability);
            $this->assertSame($names, array_keys($providers), "every registered {$capability} provider must implement ProviderMetadataInterface");

            foreach ($providers as $key => $provider) {
                $this->assertSame($provider->getName(), $key, sprintf('%s tag key "%s" differs from %s::getName()', $capability, $key, $provider::class));
                $this->assertSame(strtolower($key), $key, "tag keys are lowercase so lookups can normalise: {$key}");
                ++$checked;
            }
        }

        $this->assertGreaterThan(30, $checked, 'the registered provider set must not silently shrink');
        $this->assertContains('openai', $registry->getRegisteredProviderNames('chat'));
        $this->assertContains('test', $registry->getRegisteredProviderNames('chat'), 'the test environment registers TestProvider');
    }
}
