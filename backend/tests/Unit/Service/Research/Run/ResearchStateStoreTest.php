<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\ResearchBudget;
use App\Service\Research\Run\ResearchBudgetConfig;
use App\Service\Research\Run\ResearchDepth;
use App\Service\Research\Run\ResearchEventLog;
use App\Service\Research\Run\ResearchKey;
use App\Service\Research\Run\ResearchOutcome;
use App\Service\Research\Run\ResearchRunNotFound;
use App\Service\Research\Run\ResearchRunRef;
use App\Service\Research\Run\ResearchRunState;
use App\Service\Research\Run\ResearchStateStore;
use App\Service\Research\Run\ResearchStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ResearchStateStoreTest extends TestCase
{
    private MockClock $clock;
    private MemoryResearchRunKeys $keys;
    private ResearchStateStore $store;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-24 10:00:00');
        $this->keys = new MemoryResearchRunKeys($this->clock);
        $this->store = new ResearchStateStore($this->keys, $this->clock);
    }

    public function testASecondClaimWaitsAndALostLeaseCannotOverwrite(): void
    {
        $state = $this->open();
        $this->store->save($state);
        $token = $this->store->claim($state->ref->id, 1, 1000);

        self::assertNotNull($token);
        self::assertNull($this->store->claim($state->ref->id, 1, 1000));

        $this->clock->sleep(1.001);
        self::assertFalse($this->store->checkpoint($state, (string) $token));
        self::assertSame(1, $this->store->expectedSeq($state->ref->id));
        self::assertSame($state->ref->id, $this->store->expiredClaims()[0]['runId']);

        $next = $this->store->claim($state->ref->id, 1, 1000);
        self::assertNotNull($next);
        self::assertNotSame($token, $next);
        self::assertTrue($this->store->checkpoint($state, $next));
        self::assertSame(2, $state->expectedSeq);
        self::assertSame(2, $this->store->load($state->ref->id)?->expectedSeq);
        self::assertSame([], $this->store->expiredClaims());
    }

    public function testACheckpointReleasesItsClaim(): void
    {
        $state = $this->open();
        $this->store->save($state);
        $token = (string) $this->store->claim($state->ref->id, 1, 5000);

        self::assertTrue($this->store->checkpoint($state, $token));
        self::assertNull($this->keys->get(ResearchKey::claim($state->ref->id, 1)));
    }

    public function testAWorkerCannotBringBackADeletedRun(): void
    {
        $state = $this->open();
        $this->store->save($state);
        $token = (string) $this->store->claim($state->ref->id, 1, 5000);
        $events = new ResearchEventLog($this->keys);
        $events->append($state->ref->id, 'run', ['status' => 'briefing']);

        $this->store->delete($state->ref->id);

        self::assertFalse($this->store->checkpoint($state, $token));
        self::assertNull($this->store->load($state->ref->id));
        try {
            $this->store->putSourceText($state->ref->id, 's1', 'The price is 12.40 EUR.');
            self::fail('A deleted run should not keep new source text.');
        } catch (ResearchRunNotFound) {
        }
        self::assertNull($this->store->sourceText($state->ref->id, 's1'));
        $this->expectException(ResearchRunNotFound::class);
        $events->append($state->ref->id, 'source', ['id' => 's1']);
    }

    public function testReleasingTheClaimLetsTheNextWorkerTakeIt(): void
    {
        $state = $this->open();
        $this->store->save($state);
        $token = (string) $this->store->claim($state->ref->id, 1, 5000);
        $this->store->release($state->ref->id, 1, 'not-the-token');
        self::assertNull($this->store->claim($state->ref->id, 1, 5000));

        $this->store->release($state->ref->id, 1, $token);
        self::assertNotNull($this->store->claim($state->ref->id, 1, 5000));
    }

    public function testAFinishedRunLeavesTheActiveListAndStillLoads(): void
    {
        $running = $this->open();
        $other = $this->open(ResearchDepth::Thorough, 7);
        $this->store->save($running);
        $this->store->save($other);

        self::assertSame(1, $this->store->activeCount(7, ResearchDepth::Thorough));
        self::assertSame(2, $this->store->activeTotal());

        $running->status = ResearchStatus::Stopped;
        $running->outcome = new ResearchOutcome(ResearchOutcome::STOPPED_BY_USER);
        $this->store->save($running);

        self::assertSame(0, $this->store->activeCount($running->ref->userId));
        $loaded = $this->store->load($running->ref->id);
        self::assertInstanceOf(ResearchRunState::class, $loaded);
        self::assertInstanceOf(ResearchOutcome::class, $loaded->outcome);
        self::assertStringContainsString('No new work was started', $loaded->outcome->text());
    }

    public function testDeleteRemovesOnePersonsRunsAndKeepsTheOther(): void
    {
        $mine = $this->open(ResearchDepth::Standard, 3);
        $theirs = $this->open(ResearchDepth::Standard, 4);
        $this->store->save($mine);
        $this->store->save($theirs);
        $this->store->putSourceText($mine->ref->id, 's1', 'The price is 12.40 EUR.');

        self::assertSame(1, $this->store->deleteUser(3));
        self::assertNull($this->store->load($mine->ref->id));
        self::assertNull($this->store->sourceText($mine->ref->id, 's1'));
        self::assertNotNull($this->store->load($theirs->ref->id));
    }

    public function testStateExpiresAndAMissingRunSaysSoInPlainWords(): void
    {
        $state = $this->open();
        $this->store->save($state);
        $this->clock->sleep(48 * 3600 + 1);

        self::assertNull($this->store->load($state->ref->id));
        self::assertSame(0, $this->store->activeCount($state->ref->userId));
        try {
            $this->store->expectedSeq($state->ref->id);
            self::fail('A missing run should say it is gone.');
        } catch (ResearchRunNotFound $missing) {
            self::assertSame('This research is no longer on the server. Start it again.', $missing->getMessage());
        }
    }

    public function testARunningRunCannotBeSavedWithoutASentenceAndAFinishedOneMustHaveOne(): void
    {
        $state = $this->open();
        $state->status = ResearchStatus::Complete;

        $this->expectException(\InvalidArgumentException::class);
        $this->store->save($state);
    }

    public function testASourceThatIsTooLargeIsRefused(): void
    {
        $state = $this->open();
        $this->store->save($state);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That source is too large to keep for this research.');
        $this->store->putSourceText($state->ref->id, 's1', str_repeat('a', 4 * 1024 * 1024 + 1));
    }

    private function open(ResearchDepth $depth = ResearchDepth::Standard, int $userId = 5): ResearchRunState
    {
        $ref = new ResearchRunRef(
            'rr_'.bin2hex(random_bytes(8)),
            $userId,
            $depth,
            'What does the official page say about the price?',
            11,
            new \DateTimeImmutable('2026-10-24T10:00:00+00:00'),
        );

        return ResearchRunState::open(
            $ref,
            ResearchBudget::for($depth, ResearchBudgetConfig::defaults($depth), $ref->createdAt),
        );
    }
}
