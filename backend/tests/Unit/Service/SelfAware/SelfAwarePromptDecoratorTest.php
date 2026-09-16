<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SelfAware;

use App\Repository\ConfigRepository;
use App\Service\Knowledge\KnowledgeContextFormatter;
use App\Service\SelfAware\CapabilityFact;
use App\Service\SelfAware\CapabilityInventory;
use App\Service\SelfAware\CapabilityReport;
use App\Service\SelfAware\CapabilityReportRenderer;
use App\Service\SelfAware\CapabilityState;
use App\Service\SelfAware\Docs\PlatformDocsHit;
use App\Service\SelfAware\Docs\PlatformDocsHits;
use App\Service\SelfAware\SelfAwareConfig;
use App\Service\SelfAware\SelfAwarePromptDecorator;
use PHPUnit\Framework\TestCase;

final class SelfAwarePromptDecoratorTest extends TestCase
{
    public function testReplacesCapabilitiesForSynaplanAndStripsOtherwise(): void
    {
        $decorator = $this->decorator(enabled: true, inventoryInGeneral: true);

        $synaplan = $decorator->apply(
            "Hello\n[PLATFORM_CAPABILITIES]\n[PLATFORM_DOCS]\n",
            'synaplan',
            2,
            false,
        );
        $this->assertStringContainsString('AVAILABLE NOW:', $synaplan);
        $this->assertStringNotContainsString('[PLATFORM_CAPABILITIES]', $synaplan);
        $this->assertStringNotContainsString('[PLATFORM_DOCS]', $synaplan);

        $general = $decorator->apply('x [PLATFORM_CAPABILITIES] y', 'general', 2, false);
        $this->assertStringContainsString('AVAILABLE NOW:', $general);

        $other = $decorator->apply('x [PLATFORM_CAPABILITIES] y', 'officemaker', 2, false);
        $this->assertSame('x  y', $other);
    }

    public function testGeneralHonoursInventoryFlagAndWidgetsAreStripped(): void
    {
        $decorator = $this->decorator(enabled: true, inventoryInGeneral: false);
        $general = $decorator->apply('[PLATFORM_CAPABILITIES]', 'general', 2, false);
        $this->assertSame('', $general);

        $on = $this->decorator(enabled: true, inventoryInGeneral: true);
        $widget = $on->apply('[PLATFORM_CAPABILITIES]', 'synaplan', 2, true);
        $this->assertSame('', $widget);
    }

    public function testDisabledFlagStripsPlaceholders(): void
    {
        $decorator = $this->decorator(enabled: false, inventoryInGeneral: true);
        $out = $decorator->apply('[PLATFORM_CAPABILITIES][PLATFORM_DOCS]', 'synaplan', 2, false);
        $this->assertSame('', $out);
    }

    public function testDocsPlaceholderFilledOnlyForSynaplanHits(): void
    {
        $decorator = $this->decorator(enabled: true, inventoryInGeneral: true);
        $hits = new PlatformDocsHits([
            new PlatformDocsHit('channels', 'Channels', 'https://docs.synaplan.com/channels', 'Using', 'WhatsApp steps', 0.9),
        ]);

        $withDocs = $decorator->apply('[PLATFORM_DOCS]', 'synaplan', 2, false, $hits);
        $this->assertStringContainsString('[Doc:channels]', $withDocs);

        $general = $decorator->apply('[PLATFORM_DOCS]', 'general', 2, false, $hits);
        $this->assertSame('', $general);
    }

    public function testWidgetConversationHelper(): void
    {
        $this->assertTrue(SelfAwarePromptDecorator::isWidgetConversation(['source' => 'widget'], []));
        $this->assertTrue(SelfAwarePromptDecorator::isWidgetConversation([], ['channel' => 'WIDGET']));
        $this->assertFalse(SelfAwarePromptDecorator::isWidgetConversation(['source' => 'web'], ['channel' => 'WEB']));
    }

    private function decorator(bool $enabled, bool $inventoryInGeneral): SelfAwarePromptDecorator
    {
        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->method('getValue')->willReturnCallback(
            static function (int $owner, string $group, string $setting) use ($enabled, $inventoryInGeneral): ?string {
                if (SelfAwareConfig::CONFIG_GROUP !== $group) {
                    return null;
                }

                return match ($setting) {
                    SelfAwareConfig::KEY_ENABLED => $enabled ? '1' : '0',
                    SelfAwareConfig::KEY_INVENTORY_IN_GENERAL => $inventoryInGeneral ? '1' : '0',
                    default => null,
                };
            }
        );

        $inventory = $this->createMock(CapabilityInventory::class);
        $inventory->method('build')->willReturn(new CapabilityReport(
            [new CapabilityFact('chat', 'Chat', CapabilityState::Available, 'ready', null, null, null)],
            '4.2.1',
            false,
            false,
        ));

        return new SelfAwarePromptDecorator(
            new SelfAwareConfig($configRepo),
            $inventory,
            new CapabilityReportRenderer(),
            new KnowledgeContextFormatter(),
        );
    }
}
