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

    /**
     * The patterns only ever see a capped string, so scrubbing cost does not
     * grow with the size of whatever ended up in an exception message.
     */
    public function testMasksEmailInsideAnOverlongText(): void
    {
        $text = 'Upstream rejected payload for victim@example.com: '.str_repeat('a@a.', 50000);

        $out = (string) $this->scrubber->scrub($text);

        self::assertStringNotContainsString('victim@example.com', $out);
        self::assertStringContainsString('[email]', $out);
        self::assertLessThanOrEqual(2001, mb_strlen($out));
    }

    /**
     * Regression: when a pattern cannot be evaluated — PCRE gives up on
     * pathological input by returning null — the old code kept the untouched
     * text, so an email went into the AI-facing feed verbatim. There is no
     * basis left for claiming the text is masked, so it must fail closed.
     */
    public function testFailsClosedWhenAPatternCannotBeEvaluated(): void
    {
        $previous = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');

        try {
            self::assertSame('[redacted]', $this->scrubber->scrub('User victim@example.com not found'));
        } finally {
            ini_set('pcre.backtrack_limit', false === $previous ? '1000000' : $previous);
        }
    }

    public function testKeepsHarmlessText(): void
    {
        $text = 'RuntimeException in ChatHandler at line 764: RAG context loading failed';
        self::assertSame($text, $this->scrubber->scrub($text));
    }
}
