<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\ResearchEventLog;
use App\Service\Research\Run\ResearchIds;
use PHPUnit\Framework\TestCase;

final class ResearchEventLogTest extends TestCase
{
    public function testReplayContinuesAfterTheLastIdTheClientSaw(): void
    {
        $clock = new AdjustableClock();
        $keys = new MemoryResearchRunKeys($clock);
        $log = new ResearchEventLog($keys);
        $runId = ResearchIds::newId();

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
        $log = new ResearchEventLog(new MemoryResearchRunKeys(new AdjustableClock()));

        $this->expectException(\InvalidArgumentException::class);
        $log->append(ResearchIds::newId(), 'debug', []);
    }
}
