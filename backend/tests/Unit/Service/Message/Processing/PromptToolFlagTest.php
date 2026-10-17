<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Processing;

use App\Service\Message\Processing\PromptToolFlag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PromptToolFlagTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: ?bool}>
     */
    public static function flags(): array
    {
        return [
            'true' => [true, true],
            'false' => [false, false],
            'stored on' => ['1', true],
            'stored off' => ['0', false],
            'empty string' => ['', false],
            'integer on' => [1, true],
            'integer off' => [0, false],
            'no preference' => [null, null],
        ];
    }

    #[DataProvider('flags')]
    public function testAFlagReadsAsPhpConvertsItToABoolean(mixed $stored, ?bool $expected): void
    {
        self::assertSame($expected, PromptToolFlag::read(['tool_internet' => $stored], 'tool_internet'));
    }

    public function testAMissingFlagIsNoPreference(): void
    {
        self::assertNull(PromptToolFlag::read(['aiModel' => 5], 'tool_internet'));
    }

    public function testAValueThatIsNoFlagIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prompt tool flag "tool_internet" must be a boolean or a scalar, array given.');

        PromptToolFlag::read(['tool_internet' => ['on']], 'tool_internet');
    }
}
