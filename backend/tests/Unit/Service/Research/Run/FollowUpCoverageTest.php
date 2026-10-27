<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\FollowUpCoverage;
use PHPUnit\Framework\TestCase;

final class FollowUpCoverageTest extends TestCase
{
    public function testAQuestionOnTheSameTopicIsCovered(): void
    {
        self::assertTrue(FollowUpCoverage::covered('What does the warranty cover?', 'The warranty is two years and covers parts.'));
        self::assertTrue(FollowUpCoverage::covered('Und wie hoch sind die Preise?', 'Der Preis liegt bei 12 EUR.'));
    }

    public function testAQuestionOnSomethingElseIsNotCovered(): void
    {
        self::assertFalse(FollowUpCoverage::covered('Und in Österreich?', 'In Deutschland liefern drei Händler.'));
        self::assertFalse(FollowUpCoverage::covered('What about 2025?', 'The price in 2024 was 12 EUR.'));
    }

    public function testEmptyNotesCoverNothing(): void
    {
        self::assertFalse(FollowUpCoverage::covered('What is the warranty?', '  '));
    }

    public function testAQuestionOfOnlyQuestionWordsIsCoveredByAnyNotes(): void
    {
        self::assertTrue(FollowUpCoverage::covered('And what about that?', 'The price is 12.'));
    }
}
