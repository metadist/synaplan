<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Effort;

use App\Service\Message\Effort\Effort;
use App\Service\Message\Effort\EffortReason;
use App\Service\Message\Effort\EffortVerdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EffortVerdictTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, Effort, EffortReason, bool}>
     */
    public static function sorterAnswers(): iterable
    {
        yield 'research with its reason' => [['BEFFORT' => 'research', 'BEFFORT_WHY' => 'comparison'], Effort::Research, EffortReason::Comparison, true];
        yield 'lookup, upper case, padded' => [['BEFFORT' => ' Lookup ', 'BEFFORT_WHY' => 'CURRENT_FACT'], Effort::Lookup, EffortReason::CurrentFact, true];
        yield 'unknown reason falls to the default for the effort' => [['BEFFORT' => 'research', 'BEFFORT_WHY' => 'because'], Effort::Research, EffortReason::SeveralParts, true];
        yield 'missing reason, answer' => [['BEFFORT' => 'answer'], Effort::Answer, EffortReason::Simple, true];
        yield 'missing effort is no vote' => [['BTOPIC' => 'general'], Effort::Answer, EffortReason::Simple, false];
        yield 'unknown effort is no vote' => [['BEFFORT' => 'deep', 'BEFFORT_WHY' => 'procedure'], Effort::Answer, EffortReason::Simple, false];
        yield 'a number is no vote' => [['BEFFORT' => 2], Effort::Answer, EffortReason::Simple, false];
        yield 'null is no vote' => [['BEFFORT' => null, 'BEFFORT_WHY' => null], Effort::Answer, EffortReason::Simple, false];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('sorterAnswers')]
    public function testItReadsTheSorterJson(array $data, Effort $effort, EffortReason $reason, bool $voted): void
    {
        $verdict = EffortVerdict::fromSorterJson($data);

        self::assertSame([$effort, $reason, $voted], [$verdict->effort, $verdict->reason, $verdict->voted]);
    }

    public function testItReadsTheRawReplyWithFencesAndProse(): void
    {
        $raw = "Sure:\n```json\n{\"BTOPIC\":\"general\",\"BEFFORT\":\"lookup\",\"BEFFORT_WHY\":\"follow_up\"}\n```";

        $verdict = EffortVerdict::fromRawResponse($raw);

        self::assertSame([Effort::Lookup, EffortReason::FollowUp, true], [$verdict->effort, $verdict->reason, $verdict->voted]);
    }

    public function testAnEmptyOrBrokenReplyIsNoVote(): void
    {
        self::assertFalse(EffortVerdict::fromRawResponse(null)->voted);
        self::assertFalse(EffortVerdict::fromRawResponse('')->voted);
        self::assertFalse(EffortVerdict::fromRawResponse('{"BEFFORT": ')->voted);
    }

    public function testResearchWithoutAWebVoteIsKept(): void
    {
        $verdict = EffortVerdict::fromSorterJson(['BWEBSEARCH' => false, 'BEFFORT' => 'research', 'BEFFORT_WHY' => 'several_parts']);

        self::assertSame(Effort::Research, $verdict->effort);
    }

    public function testTheClassificationFieldsRoundTrip(): void
    {
        $fields = (new EffortVerdict(Effort::Research, EffortReason::Procedure, true))->toClassificationFields();

        self::assertSame(['effort' => 'research', 'effort_why' => 'procedure', 'effort_voted' => true], $fields);
        $back = EffortVerdict::fromClassification($fields);
        self::assertSame([Effort::Research, EffortReason::Procedure, true], [$back->effort, $back->reason, $back->voted]);
        self::assertSame([Effort::Answer, false], [EffortVerdict::fromClassification([])->effort, EffortVerdict::fromClassification([])->voted]);
    }
}
