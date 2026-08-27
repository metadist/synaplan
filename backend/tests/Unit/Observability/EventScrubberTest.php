<?php

declare(strict_types=1);

namespace App\Tests\Unit\Observability;

use App\Observability\EventScrubber;
use PHPUnit\Framework\TestCase;

final class EventScrubberTest extends TestCase
{
    private EventScrubber $scrubber;

    protected function setUp(): void
    {
        $this->scrubber = new EventScrubber();
    }

    public function testNullPassesThrough(): void
    {
        self::assertNull($this->scrubber->scrub(null));
    }

    public function testMasksEmailAddresses(): void
    {
        $out = (string) $this->scrubber->scrub('User john.doe+tag@example.co.uk not found');

        self::assertStringNotContainsString('john.doe', $out);
        self::assertStringContainsString('[email]', $out);
    }

    public function testMasksBearerToken(): void
    {
        $out = (string) $this->scrubber->scrub('token was Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9 here');

        self::assertStringNotContainsString('eyJhbGci', $out);
        self::assertStringContainsString('Bearer [redacted]', $out);
    }

    public function testMasksProviderApiKeys(): void
    {
        $out = (string) $this->scrubber->scrub('call failed with key sk-ABCdef1234567890 and gsk_zzz9999999');

        self::assertStringNotContainsString('sk-ABCdef1234567890', $out);
        self::assertStringNotContainsString('gsk_zzz9999999', $out);
        self::assertStringContainsString('[key]', $out);
    }

    public function testMasksSensitiveKeyValuePairs(): void
    {
        $out = (string) $this->scrubber->scrub('password=hunter2 token: abc123');

        self::assertStringNotContainsString('hunter2', $out);
        self::assertStringNotContainsString('abc123', $out);
    }

    public function testCapsLength(): void
    {
        $out = (string) $this->scrubber->scrub(str_repeat('a', 5000));

        self::assertLessThanOrEqual(2001, mb_strlen($out));
        self::assertStringEndsWith('…', $out);
    }

    public function testKeepsHarmlessText(): void
    {
        $text = 'RuntimeException in ChatHandler at line 764: RAG context loading failed';
        self::assertSame($text, $this->scrubber->scrub($text));
    }
}
