<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\Ledger\PassageSplitter;
use App\Service\Research\Run\Ledger\PassageVerifier;
use App\Service\Research\Run\ResearchBudget;
use App\Service\Research\Run\ResearchBudgetConfig;
use App\Service\Research\Run\ResearchDepth;
use App\Service\Research\Run\ResearchEventLog;
use App\Service\Research\Run\ResearchPipeline;
use App\Service\Research\Run\ResearchRoles;
use App\Service\Research\Run\ResearchRunRef;
use App\Service\Research\Run\ResearchRunState;
use App\Service\Research\Run\ResearchStateStore;
use App\Service\Research\Run\ResearchUnitPlan;
use App\Service\Research\Run\Stage\AcceptedEvidence;
use App\Service\Research\Run\Stage\BriefStage;
use App\Service\Research\Run\Stage\DeliverStage;
use App\Service\Research\Run\Stage\GatherChoice;
use App\Service\Research\Run\Stage\GatherStage;
use App\Service\Research\Run\Stage\PlanStage;
use App\Service\Research\Run\Stage\ReadStage;
use App\Service\Research\Run\Stage\ResearchModelReply;
use App\Service\Research\Run\Stage\ResearchStop;
use App\Service\Research\Run\Stage\StageCall;
use App\Service\Research\Run\Stage\StageContext;
use App\Service\Research\Run\Stage\WriteStage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ResearchPipelineTest extends TestCase
{
    public function testBriefAndPlanAreOneStepAndASecondPassSkipsThem(): void
    {
        $created = new \DateTimeImmutable('2026-10-24T09:00:00+00:00');
        $clock = new MockClock($created);
        $keys = new MemoryResearchRunKeys($clock);
        $store = new ResearchStateStore($keys, $clock);
        $budget = ResearchBudget::for(ResearchDepth::Quick, ResearchBudgetConfig::defaults(ResearchDepth::Quick), $created);
        $budget->at($created);
        $state = ResearchRunState::open(
            ResearchRunRef::start(7, ResearchDepth::Quick, 'What does one unit cost?', null, $created),
            $budget,
        );
        $store->save($state);
        $caller = new ReplayCaller();
        $caller->script[] = $this->json([
            'goal' => 'What does one unit cost?',
            'output' => 'answer',
            'audience' => 'the person asking',
            'language' => 'en',
            'time_frame' => null,
            'region' => null,
            'must_use' => [],
            'must_avoid' => [],
            'ambiguity' => [],
        ]);
        $caller->script[] = $this->json([
            'points' => [[
                'id' => 'x',
                'title' => 'Unit price',
                'why' => 'The goal asks for it.',
                'queries' => [['q' => 'unit price 2026', 'language' => 'en']],
                'scope' => 'web',
            ]],
            'outline' => ['Summary', 'Not confirmed'],
            'estimate' => ['minutes' => 99, 'cost' => 9.99],
        ]);
        $calls = new StageCall($caller);
        $pipeline = new ResearchPipeline(
            new BriefStage($calls),
            new PlanStage($calls),
            new GatherStage($calls, new ReplaySearch(), new GatherChoice()),
            new ReadStage($calls, new ReplayPages(), new PassageSplitter(), new AcceptedEvidence(new PassageVerifier()), $store),
            new WriteStage($calls),
            new DeliverStage(),
        );
        $context = new StageContext(
            $state,
            new ResearchRoles(1, 2, 3, 4, 5, false, []),
            new ResearchEventLog($keys),
            $created,
            new ResearchStop(),
        );

        $result = $pipeline->run($context, ResearchUnitPlan::BRIEF_PLAN);
        $again = $pipeline->run($context, ResearchUnitPlan::BRIEF_PLAN);

        self::assertSame('gather', $result->next);
        self::assertSame('gather', $again->next);
        self::assertCount(2, $caller->seen);
        self::assertSame('en', $context->state->ledger->brief()?->language);
        self::assertSame('unit price 2026', $context->state->ledger->points()[0]->queries[0]['q'] ?? null);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): ResearchModelReply
    {
        return new ResearchModelReply(true, true, $data, (string) json_encode($data), 'test:replay', null);
    }
}
