<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Recovery;

use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\Recovery\EmptyResultDetector;
use PHPUnit\Framework\TestCase;

final class EmptyResultDetectorTest extends TestCase
{
    private EmptyResultDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new EmptyResultDetector();
    }

    public function testANotionSearchWithNoHitsIsEmpty(): void
    {
        $text = '{"results":[],"type":"workspace_search"}';

        self::assertTrue($this->detector->isEmpty(NodeResult::ok($text)));
    }

    public function testAHitListIsNotEmpty(): void
    {
        $text = '{"results":[{"title":"Metadist"}]}';

        self::assertFalse($this->detector->isEmpty(NodeResult::ok($text)));
    }

    public function testAZeroCountWithoutHitsIsEmpty(): void
    {
        self::assertTrue($this->detector->isEmpty(NodeResult::ok('{"total":0}')));
        self::assertTrue($this->detector->isEmpty(NodeResult::ok('No emails matching "x" were found.', [], [
            'results_count' => 0,
        ])));
        self::assertTrue($this->detector->isEmpty(NodeResult::ok('formatted', [], [
            'search_results' => ['results' => []],
        ])));
    }

    public function testACountOfZeroDoesNotHideRealHits(): void
    {
        self::assertFalse($this->detector->isEmpty(NodeResult::ok('{"count":0,"results":[{"id":1}]}')));
    }

    public function testBlankTextAndTheNoContentFailureAreEmpty(): void
    {
        self::assertTrue($this->detector->isEmpty(NodeResult::ok('   ')));
        self::assertTrue($this->detector->isEmpty(NodeResult::reportableFailure('Notion returned no content.')));
    }

    public function testARealFailureIsNotEmpty(): void
    {
        self::assertFalse($this->detector->isEmpty(NodeResult::reportableFailure('Company CRM reported an error: NotFound')));
        self::assertFalse($this->detector->isEmpty(NodeResult::failed('mcp_fetch is disabled')));
    }

    public function testALongAnswerThatMentionsNoResultsIsNotEmpty(): void
    {
        $text = str_repeat('The report is ready. ', 20).'no results in the appendix.';

        self::assertFalse($this->detector->isEmpty(NodeResult::ok($text)));
    }

    public function testAShortNothingFoundPhraseIsEmpty(): void
    {
        self::assertTrue($this->detector->isEmpty(NodeResult::ok('Nothing found.')));
        self::assertTrue($this->detector->isEmpty(NodeResult::ok('Keine Ergebnisse.')));
    }
}
