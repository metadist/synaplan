<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Channel;

use App\Module\Channel\TelegramModule;
use PHPUnit\Framework\TestCase;

final class TelegramModuleTest extends TestCase
{
    public function testFlagOffIsAbsent(): void
    {
        $module = new TelegramModule(false);

        $this->assertFalse($module->isConfigured());
        $this->assertSame('absent', $module->status()->state());
        $this->assertContains('api_webhooks_telegram', $module->routeNames());
        $this->assertNotContains('api_webhooks_telegram_verify', $module->routeNames());
    }

    public function testFlagOnIsConfiguredWithoutAToken(): void
    {
        $module = new TelegramModule(true);

        $this->assertTrue($module->isConfigured());
        $this->assertSame('available', $module->status()->state());
        $this->assertSame(['channel_telegram'], $module->capabilityIds());
    }
}
