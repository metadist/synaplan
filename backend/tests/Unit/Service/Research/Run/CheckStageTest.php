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

    public function testTheSameProviderIsNamed(): void
    {
        [$ctx, $caller] = $this->open(true);
        $this->card($ctx, 'The unit price is 12.40 EUR.');
        $ctx->state->ledger->setReport('The price is 12.40 EUR. [e1]');
        $caller->script[] = $this->verdicts([['c1', 'supported']]);

        $this->stage($caller)->run($ctx);

        self::assertStringContainsString('Fact-check by a model of the same provider', (string) $ctx->state->ledger->report());
    }

    /**
     * @return array{0: StageContext, 1: ReplayCaller}
     */
    private function open(bool $sameProvider): array
    {
        $created = new \DateTimeImmutable('2026-10-26T09:00:00+00:00');
        $clock = new MockClock($created);
        $budget = ResearchBudget::for(ResearchDepth::Standard, ResearchBudgetConfig::defaults(ResearchDepth::Standard), $created);
        $budget->at($created);
        $state = ResearchRunState::open(
            ResearchRunRef::start(7, ResearchDepth::Standard, 'What does one unit cost?', null, $created),
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
     * @param list<array{0: string, 1: string}> $rows
     */
    private function verdicts(array $rows): ResearchModelReply
    {
        $verdicts = [];
        foreach ($rows as [$id, $verdict]) {
            $verdicts[] = [
                'statement' => $id,
                'verdict' => $verdict,
                'unsupported_part' => 'partial' === $verdict ? 'the place' : null,
                'note' => '',
            ];
        }

        return new ResearchModelReply(true, true, ['verdicts' => $verdicts], '{}', 'test:checker', null);
    }
}
