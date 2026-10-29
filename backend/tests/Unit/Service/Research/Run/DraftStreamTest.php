<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\ResearchBudget;
use App\Service\Research\Run\ResearchBudgetConfig;
use App\Service\Research\Run\ResearchDepth;
use App\Service\Research\Run\ResearchEventLog;
use App\Service\Research\Run\ResearchKey;
use App\Service\Research\Run\ResearchRoles;
use App\Service\Research\Run\ResearchRunRef;
use App\Service\Research\Run\ResearchRunState;
use App\Service\Research\Run\ResearchStateStore;
use App\Service\Research\Run\Stage\DraftStream;
use App\Service\Research\Run\Stage\ResearchStop;
use App\Service\Research\Run\Stage\StageContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class DraftStreamTest extends TestCase
{
    public function testPiecesAreGatheredIntoALineOrAMomentAndTheRestFollowsAtTheEnd(): void
    {
        [$ctx] = $this->open();
        $now = 100.0;
        $stream = new DraftStream($ctx, static function () use (&$now): float {
            return $now;
        });

        $stream->add('The ');
        $stream->add('unit price ');
        self::assertSame([], $this->drafts($ctx));

        $stream->add(str_repeat('x', 160));
        self::assertSame(['The unit price '.str_repeat('x', 160)], $this->drafts($ctx));

        $stream->add('is ');
        $now += 0.5;
        $stream->add('12.40 ');
        self::assertCount(1, $this->drafts($ctx));

        $now += 0.3;
        $stream->add('EUR');
        self::assertSame('is 12.40 EUR', $this->drafts($ctx)[1]);

        $stream->add(' net.');
        $stream->finish();
        $stream->finish();
        self::assertSame(' net.', $this->drafts($ctx)[2]);
        self::assertCount(3, $this->drafts($ctx));
        self::assertSame('The unit price '.str_repeat('x', 160).'is 12.40 EUR net.', implode('', $this->drafts($ctx)));
    }

    public function testAnExpiredRunSwallowsTheRestWithoutFailingTheWriter(): void
    {
        [$ctx, $keys] = $this->open();
        $stream = DraftStream::for($ctx);
        $keys->delete(ResearchKey::run($ctx->state->ref->id));

        $stream->add(str_repeat('y', 200));
        $stream->add(str_repeat('z', 200));
        $stream->finish();

        self::assertSame([], $this->drafts($ctx));
    }

    /**
     * @return list<string>
     */
    private function drafts(StageContext $ctx): array
    {
        $texts = [];
        foreach ($ctx->events->replay($ctx->state->ref->id, 0) as $event) {
            if ('draft' === $event['event']) {
                $texts[] = (string) $event['data']['text'];
            }
        }

        return $texts;
    }

    /**
     * @return array{0: StageContext, 1: MemoryResearchRunKeys}
     */
    private function open(): array
    {
        $created = new \DateTimeImmutable('2026-10-24T09:00:00+00:00');
        $clock = new MockClock($created);
        $keys = new MemoryResearchRunKeys($clock);
        $store = new ResearchStateStore($keys, $clock);
        $depth = ResearchDepth::Quick;
        $state = ResearchRunState::open(
            ResearchRunRef::start(7, $depth, 'What does one unit cost?', null, $created),
            ResearchBudget::for($depth, ResearchBudgetConfig::defaults($depth), $created),
        );
        $store->save($state);

        return [new StageContext(
            $state,
            new ResearchRoles(1, 2, 3, 4, 5, false, []),
            new ResearchEventLog($keys),
            $created,
            new ResearchStop(),
        ), $keys];
    }
}
