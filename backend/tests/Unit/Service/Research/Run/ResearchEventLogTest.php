<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\ResearchEventLog;
use App\Service\Research\Run\ResearchIds;
use App\Service\Research\Run\ResearchKey;
use App\Service\Research\Run\ResearchRunNotFound;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ResearchEventLogTest extends TestCase
{
    public function testReplayContinuesAfterTheLastIdTheClientSaw(): void
    {
        $keys = new MemoryResearchRunKeys(new MockClock());
        $log = new ResearchEventLog($keys);
        $runId = $this->runIn($keys);

        self::assertSame(1, $log->append($runId, 'run', ['id' => $runId, 'status' => 'briefing']));
        self::assertSame(2, $log->append($runId, 'source', ['id' => 's1', 'status' => 'read']));

        $replay = $log->replay($runId, 1);
        self::assertCount(1, $replay);
        self::assertSame(2, $replay[0]['seq']);
        self::assertSame('source', $replay[0]['event']);
        self::assertSame('s1', $replay[0]['data']['id']);
    }

    public function testAnUnknownEventIsRefused(): void
    {
        $keys = new MemoryResearchRunKeys(new MockClock());
        $log = new ResearchEventLog($keys);

        $this->expectException(\InvalidArgumentException::class);
        $log->append($this->runIn($keys), 'debug', []);
    }

    public function testAGoneRunGetsNoNewHistory(): void
    {
        $keys = new MemoryResearchRunKeys(new MockClock());
        $log = new ResearchEventLog($keys);
        $runId = ResearchIds::newId();

        try {
            $log->append($runId, 'source', ['id' => 's1']);
            self::fail('An event for a gone run should be refused.');
        } catch (ResearchRunNotFound) {
        }
        self::assertSame([], $log->replay($runId, 0));
    }

    public function testEventsExpireWithTheRun(): void
    {
        $clock = new MockClock();
        $keys = new MemoryResearchRunKeys($clock);
        $log = new ResearchEventLog($keys);
        $runId = $this->runIn($keys, 60);
        $log->append($runId, 'run', ['id' => $runId]);

        $clock->sleep(61);

        self::assertSame([], $log->replay($runId, 0));
    }

    private function runIn(MemoryResearchRunKeys $keys, int $ttlSeconds = 3600): string
    {
        $runId = ResearchIds::newId();
        $keys->set(ResearchKey::run($runId), '{}', $ttlSeconds);

        return $runId;
    }
}
