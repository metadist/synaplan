<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Channel;

use App\Module\Channel\TelegramModule;
use PHPUnit\Framework\TestCase;

final class TelegramModuleTest extends TestCase
{
    public function testFlagOffIsAbsent(): void
    {
        $module = new TelegramModule(false, 'https://chat.example.com');

        $this->assertFalse($module->isConfigured());
        $this->assertSame('absent', $module->status()->state());
        $this->assertContains('api_webhooks_telegram', $module->routeNames());
        $this->assertNotContains('api_webhooks_telegram_verify', $module->routeNames());
    }

    public function testFlagOnWithAPublicAddressIsConfiguredWithoutAToken(): void
    {
        $module = new TelegramModule(true, 'https://chat.example.com');

        $this->assertTrue($module->isConfigured());
        $this->assertSame('available', $module->status()->state());
        $this->assertSame(['channel_telegram'], $module->capabilityIds());
    }

    public function testFlagOnWithoutAPublicAddressIsAbsentAndNamesTheFix(): void
    {
        $module = new TelegramModule(true, 'http://localhost:8000');

        $this->assertFalse($module->isConfigured());
        $this->assertSame('absent', $module->status()->state());
        $this->assertStringContainsString('APP_URL', $module->status()->message);
    }

    public function testThePublicWebhookBaseWinsOverALocalAppUrl(): void
    {
        $module = new TelegramModule(true, 'http://localhost:8000', 'https://hooks.example.com');

        $this->assertTrue($module->isConfigured());
    }

    public function testDevelopmentMayAllowALocalWebhook(): void
    {
        $module = new TelegramModule(true, 'http://localhost:8000', '', true);

        $this->assertTrue($module->isConfigured());
    }
}
