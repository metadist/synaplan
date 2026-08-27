<?php

declare(strict_types=1);

namespace App\Tests\Unit\Observability;

use App\Observability\RequestIdGenerator;
use PHPUnit\Framework\TestCase;

final class RequestIdGeneratorTest extends TestCase
{
    private RequestIdGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new RequestIdGenerator();
    }

    public function testGenerateReturnsThirtyTwoHexChars(): void
    {
        $id = $this->generator->generate();

        self::assertSame(32, \strlen($id));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
    }

    public function testGenerateIsUnique(): void
    {
        self::assertNotSame($this->generator->generate(), $this->generator->generate());
    }

    public function testSanitizeKeepsAValidUpstreamId(): void
    {
        self::assertSame('trace-abc_123.4', $this->generator->sanitize('trace-abc_123.4'));
    }

    public function testSanitizeGeneratesWhenNull(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $this->generator->sanitize(null));
    }

    public function testSanitizeRejectsEmptyAndWhitespace(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $this->generator->sanitize('   '));
    }

    public function testSanitizeRejectsIllegalCharacters(): void
    {
        // A space, slash, or interpolated PII must never survive into logs.
        $dirty = 'user foo@bar.com';
        self::assertNotSame($dirty, $this->generator->sanitize($dirty));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $this->generator->sanitize($dirty));
    }

    public function testSanitizeRejectsOverlongInput(): void
    {
        $long = str_repeat('a', 129);
        self::assertNotSame($long, $this->generator->sanitize($long));
    }
}
