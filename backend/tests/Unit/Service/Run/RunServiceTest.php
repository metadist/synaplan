<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Run;

use App\Service\Run\RunService;
use App\Service\Run\RunStoreUnavailable;

final class RunServiceTest extends RunKernelCase
{
    public function testAStartOpensTheRunAndQueuesItsFirstUnit(): void
    {
        $start = $this->service()->start(7, null, 'step', $this->opener());

        self::assertTrue($start->opened);
        self::assertSame('running', $this->stored($start->runId)->status);
        self::assertSame([['runId' => $start->runId, 'unit' => 'step', 'seq' => 1, 'delayMs' => 0]], $this->bus->queued);
    }

    public function testTheSameKeyReturnsTheFirstRunWithoutPayingAgain(): void
    {
        $opened = 0;
        $open = function (string $id) use (&$opened): TestRunState {
            ++$opened;

            return new TestRunState($id);
        };

        $first = $this->service()->start(7, 'click-1', 'step', $open);
        $second = $this->service()->start(7, 'click-1', 'step', $open);
        $otherUser = $this->service()->start(8, 'click-1', 'step', $open);

        self::assertSame($first->runId, $second->runId);
        self::assertFalse($second->opened);
        self::assertNotSame($first->runId, $otherUser->runId);
        self::assertSame(2, $opened);
        self::assertCount(2, $this->bus->queued);
    }

    public function testTheKeyIsForgottenAfterADay(): void
    {
        $first = $this->service()->start(7, 'click-1', 'step', $this->opener());
        $this->clock->sleep(RunService::IDEMPOTENCY_SECONDS);

        $again = $this->service()->start(7, 'click-1', 'step', $this->opener());

        self::assertTrue($again->opened);
        self::assertNotSame($first->runId, $again->runId);
    }

    public function testAFailedOpenFreesTheKeyForTheRetry(): void
    {
        try {
            $this->service()->start(7, 'click-1', 'step', static fn (): TestRunState => throw new \RuntimeException('boom'));
            self::fail('The failed open must surface.');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame([], $this->bus->queued);

        $retry = $this->service()->start(7, 'click-1', 'step', $this->opener());

        self::assertTrue($retry->opened);
    }

    public function testARunOpenedUnderAnotherIdIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->service()->start(7, null, 'step', static fn (): TestRunState => new TestRunState('01JD0000000000000000000000'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', RunService::MAX_IDEMPOTENCY_KEY_LENGTH + 1)];
        yield 'space' => ['a b'];
        yield 'newline' => ["a\nb"];
        yield 'not ascii' => ['ä'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badKeys')]
    public function testABadKeyIsRefusedBeforeAnythingIsStored(string $key): void
    {
        try {
            $this->service()->start(7, $key, 'step', $this->opener());
            self::fail('A bad key must be refused.');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame([], $this->keys->keys());
        self::assertSame([], $this->bus->queued);
    }

    public function testAKeyWhoseRunCannotBeReadBackIsAnOutage(): void
    {
        $this->keys->set($this->keyspace->idempotency(7, 'click-1'), 'garbage', 60);

        $this->expectException(RunStoreUnavailable::class);
        $this->service()->start(7, 'click-1', 'step', $this->opener());
    }

    public function testStoppingTwiceIsTheSameAsOnce(): void
    {
        $start = $this->service()->start(7, null, 'step', $this->opener());

        $this->service()->stop($start->runId);
        $this->service()->stop($start->runId);

        self::assertTrue($this->store->cancelRequested($start->runId));
    }

    private function service(): RunService
    {
        return new RunService($this->keys, $this->store, $this->bus, $this->clock);
    }

    /** @return \Closure(string): TestRunState */
    private function opener(): \Closure
    {
        return static fn (string $id): TestRunState => new TestRunState($id);
    }
}
