<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Run;

use App\Service\Run\RunDelivery;

final class RunDeliveryTest extends RunKernelCase
{
    public function testTheFirstCallerDeliversAndTheRestDoNot(): void
    {
        $delivery = new RunDelivery($this->keys, $this->keyspace);
        $run = $this->openRun();
        $posted = 0;
        $post = static function () use (&$posted): void {
            ++$posted;
        };

        self::assertFalse($delivery->wasDelivered($run->id));
        self::assertTrue($delivery->once($run->id, $post));
        self::assertFalse($delivery->once($run->id, $post));
        self::assertTrue($delivery->wasDelivered($run->id));
        self::assertSame(1, $posted);
    }

    public function testAFailedDeliveryIsTriedAgain(): void
    {
        $delivery = new RunDelivery($this->keys, $this->keyspace);
        $run = $this->openRun();

        try {
            $delivery->once($run->id, static fn () => throw new \RuntimeException('chat down'));
            self::fail('The failure must surface.');
        } catch (\RuntimeException) {
        }

        self::assertFalse($delivery->wasDelivered($run->id));
        self::assertTrue($delivery->once($run->id, static function (): void {}));
    }

    public function testTheMarkExpiresWithTheRun(): void
    {
        $delivery = new RunDelivery($this->keys, $this->keyspace, 60);
        $run = $this->openRun();
        $delivery->once($run->id, static function (): void {});

        self::assertSame(60_000, $this->keys->ttlMs($this->keyspace->delivered($run->id)));
    }

    public function testAMarkWithoutLifetimeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RunDelivery($this->keys, $this->keyspace, 0);
    }
}
