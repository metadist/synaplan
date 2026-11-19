<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\ResearchKey;
use PHPUnit\Framework\TestCase;

/**
 * Research names some keys itself and the run kernel names the same keys
 * through the research keyspace. Both must address one key, or a stop, a
 * claim or a checkpoint written by one side is never seen by the other.
 */
final class ResearchKeyTest extends TestCase
{
    private const RUN_ID = 'rr_0123456789abcdef';
    private const TOKEN = '0123456789abcdef0123456789abcdef';

    public function testTheKernelAndResearchNameTheSameKeys(): void
    {
        $keyspace = ResearchKey::keyspace();

        self::assertSame(ResearchKey::run(self::RUN_ID), $keyspace->state(self::RUN_ID));
        self::assertSame(ResearchKey::stop(self::RUN_ID), $keyspace->cancel(self::RUN_ID));
        self::assertSame(ResearchKey::events(self::RUN_ID), $keyspace->events(self::RUN_ID));
        self::assertSame(ResearchKey::eventSeq(self::RUN_ID), $keyspace->eventSeq(self::RUN_ID));
        self::assertSame(ResearchKey::claim(self::RUN_ID, 3), $keyspace->claim(self::RUN_ID, 3));
        self::assertSame(ResearchKey::claimMember(self::RUN_ID, 3, self::TOKEN), $keyspace->claimMember(self::RUN_ID, 3, self::TOKEN));
        self::assertSame(ResearchKey::CLAIMS, $keyspace->claims());
        self::assertSame(ResearchKey::ACTIVE, $keyspace->active());
        self::assertSame(ResearchKey::delivered(self::RUN_ID), $keyspace->delivered(self::RUN_ID));
        self::assertSame(ResearchKey::idempotency(7, 'start-1'), $keyspace->idempotency(7, 'start-1'));
    }

    public function testTheKernelReapMarkDoesNotCollideWithTheOldPerUnitMark(): void
    {
        $keyspace = ResearchKey::keyspace();

        self::assertNotSame(ResearchKey::reap(self::RUN_ID, 1), $keyspace->reap(self::RUN_ID));
        self::assertSame('research:reap:'.self::RUN_ID, $keyspace->reap(self::RUN_ID));
    }

    public function testAKeyspaceThatAcceptsAResearchIdRefusesAnyOther(): void
    {
        self::assertTrue(ResearchKey::keyspace()->isValid(self::RUN_ID));
        self::assertFalse(ResearchKey::keyspace()->isValid(self::RUN_ID."\n"));
        self::assertFalse(ResearchKey::keyspace()->isValid('01JD0000000000000000000000'));
    }
}
