<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Run;

use App\Service\Run\RunClaim;
use App\Service\Run\RunIds;
use App\Service\Run\RunKeyspace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class RunKeyspaceTest extends TestCase
{
    public function testIdsAreUlidsInTimeOrder(): void
    {
        $clock = new MockClock('2026-11-19 12:00:00');
        $first = RunIds::newId($clock);
        $clock->sleep(1);
        $second = RunIds::newId($clock);

        self::assertMatchesRegularExpression(RunIds::PATTERN, $first);
        self::assertMatchesRegularExpression(RunIds::PATTERN, $second);
        self::assertLessThan(0, strcmp($first, $second));
    }

    public function testKeysFollowTheContractLayout(): void
    {
        $keyspace = RunKeyspace::runs();
        $id = '01JD0000000000000000000000';

        self::assertSame('run:state:'.$id, $keyspace->state($id));
        self::assertSame('run:events:'.$id, $keyspace->events($id));
        self::assertSame('run:events:'.$id.':seq', $keyspace->eventSeq($id));
        self::assertSame('run:claim:'.$id.':3', $keyspace->claim($id, 3));
        self::assertSame('run:claims', $keyspace->claims());
        self::assertSame('run:active', $keyspace->active());
        self::assertSame('run:reap:'.$id, $keyspace->reap($id));
        self::assertSame('run:cancel:'.$id, $keyspace->cancel($id));
        self::assertSame('run:delivered:'.$id, $keyspace->delivered($id));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badIds(): iterable
    {
        yield 'empty' => [''];
        yield 'glob' => ['*'];
        yield 'separator' => ['01JD000000000000000000000:'];
        yield 'lowercase' => ['01jd0000000000000000000000'];
        yield 'excluded letter' => ['01JD000000000000000000000U'];
        yield 'overflow' => ['81JD0000000000000000000000'];
        yield 'trailing newline' => ["01JD0000000000000000000000\n"];
    }

    #[DataProvider('badIds')]
    public function testABadIdNeverBecomesAKey(string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RunKeyspace::runs()->state($id);
    }

    public function testTheIdempotencyKeyIsHashedAndOwned(): void
    {
        $keyspace = RunKeyspace::runs();
        $key = $keyspace->idempotency(7, 'a:b*c');

        self::assertSame('run:idem:7:'.hash('sha256', 'a:b*c'), $key);
        self::assertNotSame($key, $keyspace->idempotency(8, 'a:b*c'));

        $this->expectException(\InvalidArgumentException::class);
        $keyspace->idempotency(0, 'x');
    }

    public function testAClaimMemberRoundTripsAndForgeriesAreDropped(): void
    {
        $keyspace = RunKeyspace::runs();
        $id = '01JD0000000000000000000000';
        $token = RunClaim::newToken();

        self::assertSame(['runId' => $id, 'seq' => 2, 'token' => $token], $keyspace->parseClaimMember($keyspace->claimMember($id, 2, $token)));
        self::assertNull($keyspace->parseClaimMember($id.'|0|'.$token));
        self::assertNull($keyspace->parseClaimMember($id.'|2|short'));
        self::assertNull($keyspace->parseClaimMember('x|2|'.$token));
        self::assertNull($keyspace->parseClaimMember($id.'|2'));
    }

    public function testAPrefixMustBeShortAndPlain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RunKeyspace('Run:*', RunIds::PATTERN);
    }
}
