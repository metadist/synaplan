<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\Stage\DraftCheck;
use PHPUnit\Framework\TestCase;

/**
 * A seeded draft: six statements the sources support, six that should be
 * caught. Every planted error must be flagged with its reason, and no
 * supported statement may be flagged.
 */
final class DraftCheckTest extends TestCase
{
    public function testPlantedErrorsAreCaughtAndSupportedStatementsAreNot(): void
    {
        $issues = (new DraftCheck())->check($this->report(), $this->passages());

        self::assertSame([
            ['statement' => 'The price is 99 EUR.', 'reason' => DraftCheck::NUMBER],
            ['statement' => 'The rule was published on 1 January 2019.', 'reason' => DraftCheck::DATE],
            ['statement' => 'Brave Search is the engine.', 'reason' => DraftCheck::NAME],
            ['statement' => 'The price is 12.40 EUR.', 'reason' => DraftCheck::UNKNOWN_ID],
            ['statement' => 'The fee is 50 EUR.', 'reason' => DraftCheck::UNCITED],
            ['statement' => 'Brave Search costs nothing.', 'reason' => DraftCheck::UNCITED],
        ], $issues);
    }

    public function testARevisionThatAddsAnIssueIsRejected(): void
    {
        $check = new DraftCheck();
        $before = 'SearXNG is a metasearch engine. [e1] The price is 12.40 EUR. [e1]';

        self::assertTrue($check->revisionAddsIssue($before, $before.' The fee is 99 EUR.', $this->passages()));
        self::assertTrue($check->revisionAddsIssue($before, 'SearXNG is a metasearch engine. [e1] The price is 99 EUR. [e1]', $this->passages()));
        self::assertFalse($check->revisionAddsIssue($before.' The fee is 99 EUR.', 'SearXNG is a metasearch engine. [e1]', $this->passages()));
    }

    public function testAGermanNounWithoutACitationIsNotAName(): void
    {
        $check = new DraftCheck();

        self::assertSame([], $check->check('Die Gebühr steht im Gesetz.', $this->passages()));
        self::assertSame([], $check->check('Im Jahr danach gab es Streit.', $this->passages()));
    }

    public function testDatesAndListsDoNotBreakAStatement(): void
    {
        $draft = <<<'DRAFT'
1. Die Regel erschien am 12. März 2024. [e3]
2. The rule was published on Mar. 12, 2024. [e3]
- The rule is from 2024. [e3]
- Die Regel gilt z. B. seit 2024. [e3]
DRAFT;

        self::assertSame([], (new DraftCheck())->check($draft, $this->passages()));
    }

    public function testAWordThatOpensTheSentenceIsNotPartOfTheName(): void
    {
        $passages = ['e1' => 'In a statement, Google said the index grew.'];

        self::assertSame([], (new DraftCheck())->check('Today Google said the index grew. [e1]', $passages));
    }

    public function testATableHeaderAndRuleAreNotStatements(): void
    {
        $draft = <<<'DRAFT'
| Brave Search | SearXNG |
| --- | --- |
| 12.40 EUR | free [e1] |
DRAFT;

        self::assertSame([], (new DraftCheck())->check($draft, $this->passages()));
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
