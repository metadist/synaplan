<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Eval;

use App\Service\Message\Effort\Effort;
use App\Service\Message\Effort\EffortReason;
use App\Service\Message\Effort\EffortVerdict;
use App\Service\Research\Eval\RouterEval;
use PHPUnit\Framework\TestCase;

final class RouterEvalTest extends TestCase
{
    public function testItScoresAgreementEscalationsReasonsAndNoVotes(): void
    {
        $items = [
            ['id' => 'a1', 'text' => 'hi', 'label' => 'answer', 'why' => 'simple'],
            ['id' => 'a2', 'text' => 'code', 'label' => 'answer', 'why' => 'simple'],
            ['id' => 'l1', 'text' => 'price', 'label' => 'lookup', 'why' => 'current_fact'],
            ['id' => 'r1', 'text' => 'marry', 'label' => 'research', 'why' => 'procedure'],
            ['id' => 'r2', 'text' => 'compare', 'label' => 'research', 'why' => 'comparison'],
        ];
        $scripted = [
            'a1' => new EffortVerdict(Effort::Answer, EffortReason::Simple, true),
            'a2' => new EffortVerdict(Effort::Research, EffortReason::SeveralParts, true),
            'l1' => new EffortVerdict(Effort::Lookup, EffortReason::FollowUp, true),
            'r1' => new EffortVerdict(Effort::Research, EffortReason::Procedure, true),
            'r2' => EffortVerdict::none(),
        ];

        $report = (new RouterEval())->run($items, static fn (array $item): EffortVerdict => $scripted[$item['id']]);

        self::assertSame(0.6, $report->agreement);
        self::assertSame(0.5, $report->overEscalation);
        self::assertSame(0.5, $report->underEscalation);
        self::assertSame(round(2 / 3, 4), $report->reasonAgreement);
        self::assertSame(0.2, $report->noVote);
        self::assertSame(['answer' => 1, 'lookup' => 0, 'research' => 1, 'none' => 0], $report->confusion['answer']);
        self::assertSame(['answer' => 0, 'lookup' => 0, 'research' => 1, 'none' => 1], $report->confusion['research']);
        self::assertSame(['a2', 'r2'], array_map(static fn ($row): string => $row->id, $report->disagreements));
        self::assertFalse($report->meetsGate());
    }

    public function testRepeatsTakeTheMajorityVote(): void
    {
        $calls = 0;
        $votes = [
            new EffortVerdict(Effort::Research, EffortReason::Procedure, true),
            new EffortVerdict(Effort::Lookup, EffortReason::CurrentFact, true),
            new EffortVerdict(Effort::Research, EffortReason::Procedure, true),
        ];

        $report = (new RouterEval())->run(
            [['id' => 'r1', 'text' => 'marry', 'label' => 'research', 'why' => 'procedure']],
            static function () use (&$calls, $votes): EffortVerdict {
                return $votes[$calls++ % 3];
            },
            3,
        );

        self::assertSame(3, $calls);
        self::assertSame(1.0, $report->agreement);
        self::assertTrue($report->meetsGate());
    }

    public function testTheShippedCorpusIsWellFormedAndBalanced(): void
    {
        $decoded = json_decode((string) file_get_contents(__DIR__.'/../../../../Eval/research/router.json'), true);
        self::assertIsArray($decoded);
        $items = $decoded['items'];
        self::assertGreaterThanOrEqual(60, count($items));

        $ids = [];
        $perLabel = ['answer' => [], 'lookup' => [], 'research' => []];
        foreach ($items as $item) {
            self::assertNotContains($item['id'], $ids, 'duplicate id '.$item['id']);
            $ids[] = $item['id'];
            self::assertNotNull(Effort::tryFrom($item['label']), $item['id'].' has an unknown label');
            self::assertNotNull(EffortReason::tryFrom($item['why']), $item['id'].' has an unknown reason');
            self::assertContains($item['lang'], ['en', 'de', 'es', 'fr', 'tr'], $item['id']);
            $perLabel[$item['label']][$item['lang']] = true;
        }
        foreach ($perLabel as $label => $langs) {
            self::assertGreaterThanOrEqual(3, count($langs), $label.' appears in fewer than three locales');
        }
    }
}
