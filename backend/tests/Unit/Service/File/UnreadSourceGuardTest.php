<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\UnreadSourceGuard;
use App\Service\UrlContentService;
use PHPUnit\Framework\TestCase;

final class UnreadSourceGuardTest extends TestCase
{
    /**
     * Issue #2050: a URL the message treats as a source that no fetch could
     * read refuses generation with a localized, URL-naming sentence.
     */
    public function testRefusesNamedButUnreadSource(): void
    {
        $guard = $this->guard(['https://example.com/data.csv']);

        $refusal = $guard->refusalFor('Lad https://example.com/data.csv und mach ein Diagramm.', 0, 'de');

        self::assertNotNull($refusal);
        self::assertStringContainsString('https://example.com/data.csv', $refusal);
        self::assertStringContainsString('keine Datei erstellt', $refusal);
    }

    public function testRefusalUsesEnglishFallbackForUnknownLocale(): void
    {
        $guard = $this->guard(['https://example.com/data.csv']);

        $refusal = $guard->refusalFor('Load https://example.com/data.csv and chart it.', 0, 'xx');

        self::assertNotNull($refusal);
        self::assertStringContainsString('no file was created', $refusal);
    }

    public function testAllowsWhenSourceWasRead(): void
    {
        $guard = $this->guard(['https://example.com/data.csv']);

        self::assertNull($guard->refusalFor('Lad https://example.com/data.csv und mach ein Diagramm.', 1, 'de'));
    }

    public function testAllowsWhenNoReadRan(): void
    {
        $guard = $this->guard(['https://example.com/data.csv']);

        self::assertNull($guard->refusalFor('Lad https://example.com/data.csv und mach ein Diagramm.', null, 'de'));
    }

    public function testAllowsIncidentalUrl(): void
    {
        $guard = $this->guard(['https://example.com']);

        // No load/use verb: the link is mentioned in passing, not a source.
        self::assertNull($guard->refusalFor('Write a bedtime story. See also https://example.com for inspiration.', 0, 'en'));
    }

    public function testAllowsWhenNoUrlNamed(): void
    {
        $guard = $this->guard([]);

        self::assertNull($guard->refusalFor('Lad die Datei und mach ein Diagramm.', 0, 'de'));
    }

    /**
     * @param list<string> $urls
     */
    private function guard(array $urls): UnreadSourceGuard
    {
        $urlContent = $this->createMock(UrlContentService::class);
        $urlContent->method('extractUrls')->willReturn($urls);

        return new UnreadSourceGuard($urlContent);
    }
}
