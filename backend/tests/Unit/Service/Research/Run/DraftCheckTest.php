<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\Stage\DraftCheck;
use PHPUnit\Framework\TestCase;

/**
 * A seeded draft: six statements the sources support, six that should be
 * caught. Detection is caught / planted. A false alarm is a supported
 * statement that was flagged.
 */
final class DraftCheckTest extends TestCase
{
    private const PLANTED = 6;

    private const SUPPORTED = 6;

    public function testPlantedErrorsAreCaughtAndSupportedStatementsAreNot(): void
    {
        $issues = (new DraftCheck())->check($this->report(), $this->passages());
        $reasons = array_column($issues, 'reason');

        self::assertSame(['number', 'date', 'name', 'unknown_id', 'uncited', 'uncited'], $reasons);
        self::assertEqualsWithDelta(1.0, count($issues) / self::PLANTED, 0.001);
        self::assertEqualsWithDelta(0.0, (count($issues) - self::PLANTED) / self::SUPPORTED, 0.001);
    }

    public function testARevisionThatAddsAnUncitedFactIsRejected(): void
    {
        $check = new DraftCheck();
        $before = 'SearXNG is a metasearch engine. [e1]';
        $added = $before.' The fee is 99 EUR.';
        $shortened = 'SearXNG is a metasearch engine. [e1]';

        self::assertTrue($check->revisionAddsFact($before, $added, $this->passages()));
        self::assertFalse($check->revisionAddsFact($before.' The fee is 99 EUR.', $shortened, $this->passages()));
    }

    public function testAGermanNounWithoutACitationIsNotAName(): void
    {
        $issues = (new DraftCheck())->check('Die Gebühr steht im Gesetz.', $this->passages());

        self::assertSame([], $issues);
    }

    /**
     * @return array<string, string>
     */
    private function passages(): array
    {
        return [
            'e1' => 'SearXNG is a free metasearch engine. The price is 12.40 EUR.',
            'e2' => 'A supplier sold 1,234.5 units.',
            'e3' => 'The rule was published on 12 March 2024.',
        ];
    }

    private function report(): string
    {
        return <<<'REPORT'
# Findings

The price is 12,40 EUR. [e1]
A supplier sold 1.234,5 units. [e2]
The rule was published on 12.03.2024. [e3]
SearXNG is a metasearch engine. [e1]
The comparison follows.
The price is 99 EUR. [e1]
The rule was published on 1 January 2019. [e3]
Brave Search is the engine. [e1]
The price is 12.40 EUR. [e9]
The fee is 50 EUR.
Brave Search costs nothing.

# Sources

[e1] The price page.
REPORT;
    }
}
