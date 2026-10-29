<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\Ledger\EvidenceCard;
use App\Service\Research\Run\Ledger\QuoteMatch;
use App\Service\Research\Run\Ledger\Source;
use App\Service\Research\Run\ResearchBudget;
use App\Service\Research\Run\ResearchBudgetConfig;
use App\Service\Research\Run\ResearchDepth;
use App\Service\Research\Run\ResearchEventLog;
use App\Service\Research\Run\ResearchInputBudget;
use App\Service\Research\Run\ResearchOutcome;
use App\Service\Research\Run\ResearchRoles;
use App\Service\Research\Run\ResearchRunRef;
use App\Service\Research\Run\ResearchRunState;
use App\Service\Research\Run\ResearchStateStore;
use App\Service\Research\Run\ResearchStatus;
use App\Service\Research\Run\Stage\CheckBatches;
use App\Service\Research\Run\Stage\CheckStage;
use App\Service\Research\Run\Stage\DeliverStage;
use App\Service\Research\Run\Stage\DraftCheck;
use App\Service\Research\Run\Stage\ResearchModelReply;
use App\Service\Research\Run\Stage\ResearchStop;
use App\Service\Research\Run\Stage\StageCall;
use App\Service\Research\Run\Stage\StageContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class CheckStageTest extends TestCase
{
    public function testOnlyASupportedStatementStaysAndTheCountMatches(): void
    {
        [$ctx, $caller] = $this->open(false);
        $this->card($ctx, 'The unit price is 12.40 EUR.');
        $this->card($ctx, 'The warranty is two years.');
        $this->card($ctx, 'The shop is in Berlin.');
        $this->card($ctx, 'The case is black.');
        $ctx->state->ledger->setReport(<<<'REPORT'
The price is 12.40 EUR. [e1]
The warranty is five years. [e2]
The shop is in Paris. [e3]
The colour is blue. [e4]
The fee is 50 EUR.
REPORT);
        $caller->script[] = $this->verdicts([
            ['c1', 'supported'],
            ['c2', 'contradicted'],
            ['c3', 'partial'],
            ['c4', 'unsupported'],
        ]);

        $result = $this->stage($caller)->run($ctx);

        self::assertSame('deliver', $result->next);
        $report = (string) $ctx->state->ledger->report();
        $body = strstr($report, '## Not confirmed', true);
        self::assertIsString($body);
        self::assertStringContainsString('The price is 12.40 EUR.', $body);
        self::assertStringNotContainsString('five years', $body);
        self::assertStringNotContainsString('Paris', $body);
        self::assertStringNotContainsString('blue', $body);
        self::assertStringNotContainsString('50 EUR', $body);
        self::assertStringContainsString('## Not confirmed', $report);
        self::assertStringContainsString('1 of 5 statements are backed by a source. 4 were removed', $report);
        self::assertStringNotContainsString('same provider', $report);
        self::assertSame(['statements' => 5, 'supported' => 1, 'removed' => 4], $ctx->state->ledger->check());
        self::assertCount(1, $caller->seen);
        self::assertStringNotContainsString('50 EUR', $caller->seen[0]['user']);
        self::assertStringContainsString('c1', $caller->seen[0]['user']);

        (new DeliverStage())->run($ctx);

        self::assertNotNull($ctx->state->outcome);
        self::assertSame(ResearchOutcome::COMPLETE, $ctx->state->outcome->code);
        self::assertSame('Done: 5 statements, 1 backed by a source.', $ctx->state->outcome->text());
    }

    public function testThePersonReadsWhichStatementsChangedAndWhy(): void
    {
        [$ctx, $caller] = $this->open(false);
        $this->card($ctx, 'The unit price is 12.40 EUR.');
        $this->card($ctx, 'The warranty is two years.');
        $this->card($ctx, 'The shop is in Berlin.');
        $ctx->state->ledger->setReport(<<<'REPORT'
The price is 12.40 EUR. [e1]
The warranty is five years. [e2]
The shop is in Paris. [e3]
The fee is 50 EUR.
REPORT);
        $caller->script[] = $this->verdicts([
            ['c1', 'supported', null, 'The passage states the price.'],
            ['c2', 'contradicted', null, 'The source says two years.'],
            ['c3', 'partial', 'the city', 'The source names the shop, not the city.'],
        ]);

        $this->stage($caller)->run($ctx);

        $verdicts = [];
        foreach ($ctx->events->replay($ctx->state->ref->id, 0) as $event) {
            if ('verdict' === $event['event']) {
                $verdicts[] = $event['data'];
            }
        }
        self::assertSame([
            ['statement' => 'The fee is 50 EUR.', 'verdict' => 'removed', 'reason' => 'uncited'],
            ['statement' => 'The warranty is five years.', 'verdict' => 'contradicted', 'note' => 'The source says two years.'],
            ['statement' => 'The shop is in Paris.', 'verdict' => 'partial', 'note' => 'The source names the shop, not the city.', 'part' => 'the city'],
        ], $verdicts);
    }

    public function testAnAnswerWithoutTheVerdictEnvelopeStopsTheRun(): void
    {
        [$ctx, $caller] = $this->open(false);
        $this->card($ctx, 'The unit price is 12.40 EUR.');
        $ctx->state->ledger->setReport('The price is 12.40 EUR. [e1]');
        $caller->script[] = new ResearchModelReply(true, true, ['ok' => true], '{}', 'test:checker', null);

        $result = $this->stage($caller)->run($ctx);

        self::assertSame('done', $result->next);
        self::assertSame(ResearchStatus::Failed, $ctx->state->status);
        self::assertNotNull($ctx->state->outcome);
        self::assertSame(ResearchOutcome::FAILED_MODEL_ERROR, $ctx->state->outcome->code);
    }

    public function testStatementsAreCheckedInBatches(): void
    {
        [$ctx, $caller] = $this->open(false);
        $this->card($ctx, 'Alpha is one.');
        $this->card($ctx, 'Beta is two.');
        $ctx->state->ledger->setReport("Alpha is one. [e1]\nBeta is two. [e2]");
        $caller->script[] = $this->verdicts([['c1', 'supported']]);
        $caller->script[] = $this->verdicts([['c2', 'supported']]);

        $this->stage($caller, 20)->run($ctx);

        self::assertCount(2, $caller->seen);
        self::assertSame(['statements' => 2, 'supported' => 2, 'removed' => 0], $ctx->state->ledger->check());
        self::assertStringContainsString('2 of 2 statements are backed by a source.', (string) $ctx->state->ledger->report());
        self::assertStringNotContainsString('were removed', (string) $ctx->state->ledger->report());
    }

    public function testRemovedStatementsJoinTheWritersNotConfirmedSection(): void
    {
        [$ctx, $caller] = $this->open(false);
        $this->card($ctx, 'The unit price is 12.40 EUR.');
        $this->card($ctx, 'The warranty is two years.');
        $ctx->state->ledger->setReport(<<<'REPORT'
## Prices

- The price is 12.40 EUR. [e1]
- The warranty is five years. [e2]

## Not confirmed

The delivery time in Hamburg Altona is not published.
REPORT);
        $caller->script[] = $this->verdicts([['c1', 'supported'], ['c2', 'unsupported']]);

        $this->stage($caller)->run($ctx);

        $report = (string) $ctx->state->ledger->report();
        self::assertSame(1, substr_count($report, 'Not confirmed'));
        self::assertStringNotContainsString("\n-\n", $report);
        self::assertStringContainsString("Hamburg Altona is not published.\n\n- The warranty is five years.", $report);
        self::assertSame(['statements' => 2, 'supported' => 1, 'removed' => 1], $ctx->state->ledger->check());
        self::assertStringNotContainsString('Hamburg', $caller->seen[0]['user']);
    }

    public function testAVerdictForAStatementOfAnotherBatchIsIgnored(): void
    {
        [$ctx, $caller] = $this->open(false);
        $this->card($ctx, 'Alpha is one.');
        $this->card($ctx, 'Beta is two.');
        $ctx->state->ledger->setReport("Alpha is one. [e1]\nBeta is two. [e2]");
        $caller->script[] = $this->verdicts([['c1', 'supported'], ['c2', 'supported']]);
        $caller->script[] = $this->verdicts([['c2', 'contradicted']]);

        $this->stage($caller, 20)->run($ctx);

        self::assertSame(['statements' => 2, 'supported' => 1, 'removed' => 1], $ctx->state->ledger->check());
    }

    public function testStatementsLeftWhenTheBudgetRunsOutAreNotConfirmedAndTheReportIsKept(): void
    {
        [$ctx, $caller] = $this->open(false);
        $this->card($ctx, 'Alpha is one.');
        $this->card($ctx, 'Beta is two.');
        $ctx->state->ledger->setReport("Alpha is one. [e1]\nBeta is two. [e2]");
        while ($ctx->state->budget->callsLeft() > 1) {
            $ctx->state->budget->spend('calls');
        }
        $caller->script[] = $this->verdicts([['c1', 'supported']]);

        $result = $this->stage($caller, 20)->run($ctx);

        self::assertSame('deliver', $result->next);
        self::assertCount(1, $caller->seen);
        self::assertSame(['statements' => 2, 'supported' => 1, 'removed' => 1], $ctx->state->ledger->check());
        self::assertStringContainsString("## Not confirmed\n\n- Beta is two.", (string) $ctx->state->ledger->report());
    }

    public function testAnUnusableRowCountsAsUnsupportedWithoutEndingTheRun(): void
    {
        [$ctx, $caller] = $this->open(false);
        $this->card($ctx, 'Alpha is one.');
        $this->card($ctx, 'Beta is two.');
        $ctx->state->ledger->setReport("Alpha is one. [e1]\nBeta is two. [e2]");
        $caller->script[] = new ResearchModelReply(true, true, ['verdicts' => [
            ['statement' => 'c1', 'verdict' => 'supported', 'unsupported_part' => null, 'note' => ''],
            ['statement' => 'c2', 'verdict' => 'maybe', 'unsupported_part' => null, 'note' => ''],
        ]], '{}', 'test:checker', null);

        $result = $this->stage($caller)->run($ctx);

        self::assertSame('deliver', $result->next);
        self::assertSame(['statements' => 2, 'supported' => 1, 'removed' => 1], $ctx->state->ledger->check());
    }

    public function testABatchHoldsAtMostTheStatementCap(): void
    {
        $items = [];
        for ($i = 1; $i <= 7; ++$i) {
            $items[] = ['id' => 'c'.$i, 'sentence' => 's', 'bare' => 's', 'passages' => 'p'];
        }

        $groups = (new CheckBatches())->groups($items, 100000, 3);

        self::assertSame([3, 3, 1], array_map('count', $groups));
    }

    public function testAReportWithNothingToCheckGetsNoCountLine(): void
    {
        [$ctx, $caller] = $this->open(false);
        $this->card($ctx, 'Alpha is one.');
        $ctx->state->ledger->setReport('This report gives an overview.');

        $this->stage($caller)->run($ctx);

        self::assertSame('This report gives an overview.', $ctx->state->ledger->report());
        self::assertCount(0, $caller->seen);
    }

    public function testTheSameProviderIsNamed(): void
    {
        [$ctx, $caller] = $this->open(true);
        $this->card($ctx, 'The unit price is 12.40 EUR.');
        $ctx->state->ledger->setReport('The price is 12.40 EUR. [e1]');
        $caller->script[] = $this->verdicts([['c1', 'supported']]);

        $this->stage($caller)->run($ctx);

        self::assertStringContainsString('A model of the same provider did the fact-check.', (string) $ctx->state->ledger->report());
    }

    public function testAThoroughRevisionKeepsTheSupportedPartOfAPartialStatement(): void
    {
        [$ctx, $caller] = $this->open(false, ResearchDepth::Thorough);
        $this->card($ctx, 'The unit price is 12.40 EUR.');
        $this->card($ctx, 'The warranty is two years.');
        $this->card($ctx, 'The shop is in Berlin.');
        $this->card($ctx, 'The case is black.');
        $ctx->state->ledger->setReport(<<<'REPORT'
The price is 12.40 EUR. [e1]
The warranty is five years. [e2]
The shop is in Paris. [e3]
The colour is blue. [e4]
The fee is 50 EUR.
REPORT);
        $caller->script[] = $this->verdicts([
            ['c1', 'supported'],
            ['c2', 'contradicted'],
            ['c3', 'partial', 'in Paris'],
            ['c4', 'unsupported'],
        ]);
        $caller->script[] = new ResearchModelReply(true, true, null, <<<'REPORT'
The price is 12.40 EUR. [e1]
The shop is listed. [e3]

## Not confirmed

- The warranty is five years.
- The colour is blue.
- The fee is 50 EUR.
REPORT, 'test:writer', null);

        $this->stage($caller)->run($ctx);

        $report = (string) $ctx->state->ledger->report();
        $body = strstr($report, '## Not confirmed', true);
        self::assertIsString($body);
        self::assertStringContainsString('The price is 12.40 EUR.', $body);
        self::assertStringContainsString('The shop is listed. [e3]', $body);
        self::assertStringNotContainsString('in Paris', $body);
        self::assertStringNotContainsString('five years', $body);
        self::assertStringNotContainsString('[e2]', $report);
        self::assertSame(['statements' => 5, 'supported' => 2, 'removed' => 3], $ctx->state->ledger->check());
        self::assertStringContainsString('2 of 5 statements are backed by a source. 1 were shortened to the part a source backs. 3 were removed', $report);
        self::assertSame(1, $this->checkEvent($ctx)['softened']);
        self::assertSame(1, $this->checkEvent($ctx)['supported']);
        self::assertCount(2, $caller->seen);
        self::assertStringContainsString('Add no new', $caller->seen[1]['system']);
        self::assertStringContainsString('unsupported part: in Paris', $caller->seen[1]['system']);
    }

    public function testARevisionMayListARejectedFactWithoutItsCitation(): void
    {
        [$ctx, $caller] = $this->open(false, ResearchDepth::Thorough);
        $this->card($ctx, 'The warranty is 2 years from purchase.');
        $this->card($ctx, 'The shop opened in 2019.');
        $ctx->state->ledger->setReport("The warranty ends after 2 years. [e1]\nThe shop in Paris opened in 2019. [e2]");
        $caller->script[] = $this->verdicts([['c1', 'contradicted'], ['c2', 'partial', 'in Paris']]);
        $caller->script[] = new ResearchModelReply(true, true, null, <<<'REPORT'
The shop opened in 2019. [e2]

## Not confirmed

- The warranty ends after 2 years.
REPORT, 'test:writer', null);

        $this->stage($caller)->run($ctx);

        self::assertStringContainsString('The shop opened in 2019. [e2]', (string) $ctx->state->ledger->report());
        self::assertSame(1, $this->checkEvent($ctx)['softened']);
    }

    public function testADroppedPartialIsNotSoftenedByACitationOnAnotherStatement(): void
    {
        [$ctx, $caller] = $this->open(false, ResearchDepth::Thorough);
        $this->card($ctx, 'The unit price is 12.40 EUR.');
        $ctx->state->ledger->setReport("The price is 12.40 EUR. [e1]\nThe shop is in Paris. [e1]");
        $caller->script[] = $this->verdicts([['c1', 'supported'], ['c2', 'partial', 'in Paris']]);
        $caller->script[] = new ResearchModelReply(true, true, null, 'The price is 12.40 EUR. [e1]', 'test:writer', null);

        $this->stage($caller)->run($ctx);

        self::assertSame(['statements' => 2, 'supported' => 1, 'removed' => 1], $ctx->state->ledger->check());
        self::assertSame(0, $this->checkEvent($ctx)['softened']);
    }

    public function testARevisionThatAddsAFactIsRejectedAndTheRemovalStands(): void
    {
        [$ctx, $caller] = $this->open(false, ResearchDepth::Thorough);
        $this->card($ctx, 'The unit price is 12.40 EUR.');
        $this->card($ctx, 'The shop is in Berlin.');
        $ctx->state->ledger->setReport("The price is 12.40 EUR. [e1]\nThe shop is in Paris. [e2]");
        $caller->script[] = $this->verdicts([['c1', 'supported'], ['c2', 'partial', 'in Paris']]);
        $caller->script[] = new ResearchModelReply(true, true, null, "The price is 12.40 EUR. [e1]\nThe shop is listed. [e2]\nThe deposit is 99 EUR.", 'test:writer', null);

        $this->stage($caller)->run($ctx);

        $report = (string) $ctx->state->ledger->report();
        self::assertStringNotContainsString('99 EUR', $report);
        self::assertStringNotContainsString('Paris', strstr($report, '## Not confirmed', true) ?: '');
        self::assertSame(['statements' => 2, 'supported' => 1, 'removed' => 1], $ctx->state->ledger->check());
        self::assertSame(0, $this->checkEvent($ctx)['softened']);
    }

    public function testAContradictedStatementKeptAsASecondVersionIsRejected(): void
    {
        [$ctx, $caller] = $this->open(false, ResearchDepth::Thorough);
        $this->card($ctx, 'The warranty is two years.');
        $this->card($ctx, 'The shop is in Berlin.');
        $ctx->state->ledger->setReport("The warranty is five years. [e1]\nThe shop is in Paris. [e2]");
        $caller->script[] = $this->verdicts([['c1', 'contradicted'], ['c2', 'partial', 'in Paris']]);
        $caller->script[] = new ResearchModelReply(true, true, null, <<<'REPORT'
The shop is listed. [e2]

## Not confirmed

- The warranty is five years. [e1, e2]
REPORT, 'test:writer', null);

        $this->stage($caller)->run($ctx);

        $report = (string) $ctx->state->ledger->report();
        self::assertStringNotContainsString('[e1', $report);
        self::assertStringNotContainsString('The shop is listed.', $report);
        self::assertSame(0, $this->checkEvent($ctx)['softened']);
    }

    public function testARewordedUnsupportedStatementThatStaysCitedIsRejected(): void
    {
        [$ctx, $caller] = $this->open(false, ResearchDepth::Thorough);
        $this->card($ctx, 'The shop is in Berlin.');
        $this->card($ctx, 'The case is black.');
        $ctx->state->ledger->setReport("The shop is in Paris. [e1]\nThe colour is blue. [e2]");
        $caller->script[] = $this->verdicts([['c1', 'partial', 'in Paris'], ['c2', 'unsupported']]);
        $caller->script[] = new ResearchModelReply(true, true, null, "The shop is listed. [e1]\nThe case colour is blue. [e2]", 'test:writer', null);

        $this->stage($caller)->run($ctx);

        $report = (string) $ctx->state->ledger->report();
        self::assertStringNotContainsString('[e2]', $report);
        self::assertStringNotContainsString('The shop is listed.', $report);
        self::assertSame(0, $this->checkEvent($ctx)['softened']);
    }

    public function testAThoroughRunWithoutAPartialStatementDoesNotRevise(): void
    {
        [$ctx, $caller] = $this->open(false, ResearchDepth::Thorough);
        $this->card($ctx, 'The unit price is 12.40 EUR.');
        $ctx->state->ledger->setReport("The price is 12.40 EUR. [e1]\nThe colour is blue. [e1]");
        $caller->script[] = $this->verdicts([['c1', 'supported'], ['c2', 'unsupported']]);

        $this->stage($caller)->run($ctx);

        self::assertCount(1, $caller->seen);
        self::assertSame(['statements' => 2, 'supported' => 1, 'removed' => 1], $ctx->state->ledger->check());
    }

    public function testAThoroughRunWithNoCallLeftRemovesInsteadOfRevising(): void
    {
        [$ctx, $caller] = $this->open(false, ResearchDepth::Thorough);
        $this->card($ctx, 'The shop is in Berlin.');
        $ctx->state->ledger->setReport('The shop is in Paris. [e1]');
        while ($ctx->state->budget->callsLeft() > 1) {
            $ctx->state->budget->spend('calls');
        }
        $caller->script[] = $this->verdicts([['c1', 'partial', 'in Paris']]);

        $this->stage($caller)->run($ctx);

        self::assertCount(1, $caller->seen);
        self::assertStringNotContainsString('in Paris', strstr((string) $ctx->state->ledger->report(), '## Not confirmed', true) ?: '');
        self::assertSame(['statements' => 1, 'supported' => 0, 'removed' => 1], $ctx->state->ledger->check());
    }

    public function testARevisionChecksOnlyTheSentenceThatChanged(): void
    {
        [$ctx, $caller] = $this->open(false);
        $this->card($ctx, 'The unit price is 12.40 EUR.');
        $this->card($ctx, 'The warranty is two years.');
        $ctx->state->followUp = 'revise';
        $ctx->state->priorReport = "The price is 12.40 EUR. [e1]\nThe warranty is two years. [e2]";
        $ctx->state->ledger->setReport("The price is 12.40 EUR. [e1]\nThe warranty is five years. [e2]");
        $caller->script[] = $this->verdicts([['c2', 'contradicted']]);

        $this->stage($caller)->run($ctx);

        self::assertCount(1, $caller->seen);
        self::assertStringContainsString('five years', $caller->seen[0]['user']);
        self::assertStringNotContainsString('12.40', $caller->seen[0]['user']);
        $report = (string) $ctx->state->ledger->report();
        self::assertStringContainsString('The price is 12.40 EUR. [e1]', $report);
        self::assertStringNotContainsString('five years', strstr($report, '## Not confirmed', true) ?: '');
    }

    public function testARevisionChecksASentenceThatNowCitesOtherEvidence(): void
    {
        [$ctx, $caller] = $this->open(false);
        $this->card($ctx, 'The shop is in Berlin.');
        $this->card($ctx, 'The shop opens on Sundays.');
        $ctx->state->followUp = 'revise';
        $ctx->state->priorReport = 'The shop is in Berlin. [e1]';
        $ctx->state->ledger->setReport('The shop is in Berlin. [e2]');
        $caller->script[] = $this->verdicts([['c1', 'unsupported']]);

        $this->stage($caller)->run($ctx);

        self::assertCount(1, $caller->seen);
        self::assertSame(['statements' => 1, 'supported' => 0, 'removed' => 1], $ctx->state->ledger->check());
    }

    public function testARevisionChecksASentenceTakenBackFromNotConfirmed(): void
    {
        [$ctx, $caller] = $this->open(false);
        $this->card($ctx, 'The unit price is 12.40 EUR.');
        $ctx->state->followUp = 'revise';
        $ctx->state->priorReport = "The shop is open.\n\n## Not confirmed\n\n- The price is 12.40 EUR. [e1]";
        $ctx->state->ledger->setReport('The price is 12.40 EUR. [e1]');
        $caller->script[] = $this->verdicts([['c1', 'supported']]);

        $this->stage($caller)->run($ctx);

        self::assertCount(1, $caller->seen);
        self::assertStringContainsString('12.40', $caller->seen[0]['user']);
    }

    /**
     * @return array{0: StageContext, 1: ReplayCaller}
     */
    private function open(bool $sameProvider, ResearchDepth $depth = ResearchDepth::Standard): array
    {
        $created = new \DateTimeImmutable('2026-10-26T09:00:00+00:00');
        $clock = new MockClock($created);
        $budget = ResearchBudget::for($depth, ResearchBudgetConfig::defaults($depth), $created);
        $budget->at($created);
        $state = ResearchRunState::open(
            ResearchRunRef::start(7, $depth, 'What does one unit cost?', null, $created),
            $budget,
        );
        $caller = new ReplayCaller();
        $keys = new MemoryResearchRunKeys($clock);
        (new ResearchStateStore($keys, $clock))->save($state);
        $context = new StageContext(
            $state,
            new ResearchRoles(1, 2, 3, 4, 5, $sameProvider, []),
            new ResearchEventLog($keys),
            $created,
            new ResearchStop(),
        );

        return [$context, $caller];
    }

    private function card(StageContext $ctx, string $quote): void
    {
        $ledger = $ctx->state->ledger;
        if ([] === $ledger->points()) {
            $ledger->addPoint('Unit price');
        }
        $source = $ledger->addSource(Source::web('https://maker.example/'.count($ledger->sources()), 'Page'));
        $ledger->addEvidence(new EvidenceCard(
            'x',
            $source,
            'p1',
            $quote,
            ['§1'],
            [new QuoteMatch($quote, '§1', true)],
        ));
    }

    private function stage(ReplayCaller $caller, int $chars = 0): CheckStage
    {
        return new CheckStage(new StageCall($caller), new DraftCheck(), new ResearchInputBudget(), new CheckBatches(), $chars);
    }

    /**
     * @param list<array{0: string, 1: string, 2?: string|null, 3?: string}> $rows
     */
    private function verdicts(array $rows): ResearchModelReply
    {
        $verdicts = [];
        foreach ($rows as $row) {
            $verdict = $row[1];
            $verdicts[] = [
                'statement' => $row[0],
                'verdict' => $verdict,
                'unsupported_part' => $row[2] ?? ('partial' === $verdict ? 'the place' : null),
                'note' => $row[3] ?? '',
            ];
        }

        return new ResearchModelReply(true, true, ['verdicts' => $verdicts], '{}', 'test:checker', null);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkEvent(StageContext $ctx): array
    {
        foreach ($ctx->events->replay($ctx->state->ref->id, 0) as $event) {
            if ('check' === $event['event']) {
                return $event['data'];
            }
        }
        self::fail('The check event was not recorded.');
    }
}
