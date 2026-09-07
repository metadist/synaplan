<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Agent;

use App\Service\Agent\AgentSlugger;
use PHPUnit\Framework\TestCase;

final class AgentSluggerTest extends TestCase
{
    public function testFromName(): void
    {
        self::assertSame('contract-review', AgentSlugger::from('Contract review'));
    }

    public function testStripsNonAlphanumeric(): void
    {
        self::assertSame('hello-world', AgentSlugger::from('Hello, World!'));
    }

    public function testEmptyFallsBackToAssistant(): void
    {
        self::assertSame('assistant', AgentSlugger::from('!!!'));
    }

    public function testCapsAtTopicFitLength(): void
    {
        $long = str_repeat('a', 80);
        $slug = AgentSlugger::from($long);

        self::assertSame(AgentSlugger::MAX_LENGTH, strlen($slug));
        self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);
    }

    public function testShortNameIsPadded(): void
    {
        $slug = AgentSlugger::from('AI');
        self::assertGreaterThanOrEqual(AgentSlugger::MIN_LENGTH, strlen($slug));
    }
}
