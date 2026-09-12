<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Service;

use App\AI\Interface\ChatProviderInterface;
use App\AI\Service\ProviderDisplayNames;
use App\AI\Service\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class ProviderDisplayNamesEnrichTest extends TestCase
{
    private function names(): ProviderDisplayNames
    {
        $anthropic = $this->createStub(ChatProviderInterface::class);
        $anthropic->method('getDisplayName')->willReturn('Anthropic');

        $registry = $this->createStub(ProviderRegistry::class);
        $registry->method('getChatProvider')->willReturn($anthropic);
        $registry->method('getUniqueProviders')->willReturn(['anthropic' => $anthropic]);

        return new ProviderDisplayNames($registry);
    }

    public function testAddsTheBrandedLabelNextToTheServiceKey(): void
    {
        $out = $this->names()->enrich(['provider' => 'anthropic', 'model_name' => 'claude-opus-4-8']);

        self::assertSame('Anthropic', $out['provider_label']);
        self::assertSame('anthropic', $out['provider'], 'the routing key is untouched');
    }

    public function testLeavesMetadataWithoutProviderAlone(): void
    {
        self::assertSame(['results_count' => 10], $this->names()->enrich(['results_count' => 10]));
    }

    public function testNeverLabelsTheInternalTestProvider(): void
    {
        self::assertArrayNotHasKey('provider_label', $this->names()->enrich(['provider' => 'test']));
    }

    public function testKeepsAnExplicitLabel(): void
    {
        $out = $this->names()->enrich(['provider' => 'anthropic', 'provider_label' => 'Custom']);

        self::assertSame('Custom', $out['provider_label']);
    }
}
