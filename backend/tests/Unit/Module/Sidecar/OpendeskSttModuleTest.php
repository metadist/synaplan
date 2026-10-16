<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Sidecar;

use App\Module\Sidecar\OpendeskSttModule;
use App\Tests\Unit\Module\Fixture\FakeSidecarHealthProbe;
use PHPUnit\Framework\TestCase;

final class OpendeskSttModuleTest extends TestCase
{
    public function testEmptyUrlIsAbsent(): void
    {
        $module = new OpendeskSttModule(new FakeSidecarHealthProbe(), '', '', '', '');

        self::assertFalse($module->isConfigured());
        self::assertSame('absent', $module->status()->state());
        self::assertSame(['api_opendesk_meeting_notes*'], $module->routeNames());
    }

    public function testDisabledUrlIsAbsent(): void
    {
        $module = new OpendeskSttModule(new FakeSidecarHealthProbe(), 'disabled', '', 'de', 'jitsi');

        self::assertFalse($module->isConfigured());
    }

    public function testReachableSidecarIsHealthy(): void
    {
        $probe = new FakeSidecarHealthProbe(reachable: ['http://transcriber:8095/health' => true]);
        $module = new OpendeskSttModule($probe, 'http://transcriber:8095/', 'https://notes.example', 'de', 'opendesk');

        $status = $module->status();

        self::assertTrue($status->configured);
        self::assertTrue($status->healthy);
        self::assertSame('Meeting notes are running', $status->message);
        self::assertSame('https://notes.example', $status->details['public_url']);
        self::assertSame('de', $status->details['language']);
    }
}
