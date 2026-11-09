<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Service\Message\StoredAdminDiagnostics;
use PHPUnit\Framework\TestCase;

final class StoredAdminDiagnosticsTest extends TestCase
{
    public function testSplitsAGermanAdminBlockOutOfTheUserSentence(): void
    {
        $text = "Das Video konnte leider nicht erstellt werden.\n\n---\n**Admin-Diagnose (nur für dich sichtbar)**\n```\nProvider: google\nError: missing\n```";

        $split = StoredAdminDiagnostics::split($text);

        self::assertSame('Das Video konnte leider nicht erstellt werden.', $split['text']);
        self::assertStringContainsString('Provider: google', (string) $split['debug']);
        self::assertStringNotContainsString('Admin-Diagnose', (string) $split['debug']);
    }

    public function testLeavesANormalSentenceUntouched(): void
    {
        $split = StoredAdminDiagnostics::split('Das Video konnte leider nicht erstellt werden.');

        self::assertSame('Das Video konnte leider nicht erstellt werden.', $split['text']);
        self::assertNull($split['debug']);
    }
}
