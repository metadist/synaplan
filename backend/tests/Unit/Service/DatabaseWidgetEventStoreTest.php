<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\WidgetEvent;
use App\Repository\WidgetEventRepository;
use App\Service\DatabaseWidgetEventStore;
use PHPUnit\Framework\TestCase;

class DatabaseWidgetEventStoreTest extends TestCase
{
    public function testGetNewEventsWithoutGracePassesStrictCursor(): void
    {
        $repo = $this->createMock(WidgetEventRepository::class);
        $repo->expects($this->once())
            ->method('findStreamEventsSince')
            ->with('w1', 's1', 42, $this->isInt(), 0)
            ->willReturn([]);

        $store = new DatabaseWidgetEventStore($repo);

        $this->assertSame([], $store->getNewEvents('w1', 's1', 42));
    }

    public function testGetNewEventsWithGraceComputesCutoffFromNow(): void
    {
        $graceSeconds = 15;
        $before = time();

        $repo = $this->createMock(WidgetEventRepository::class);
        $repo->expects($this->once())
            ->method('findStreamEventsSince')
            ->with(
                'w1',
                's1',
                42,
                $this->callback(static fn (int $now): bool => $now >= $before),
                // Grace cutoff must be (now - graceSeconds); allow a small clock
                // tolerance since the store reads time() internally.
                $this->callback(static function (int $cutoff) use ($before, $graceSeconds): bool {
                    return $cutoff >= $before - $graceSeconds - 2
                        && $cutoff <= time() - $graceSeconds + 2;
                }),
            )
            ->willReturn([]);

        $store = new DatabaseWidgetEventStore($repo);

        $store->getNewEvents('w1', 's1', 42, $graceSeconds);
    }

    public function testGetNewEventsMapsEntitiesToWireShape(): void
    {
        $event = new WidgetEvent('w1', 's1', 'message', ['text' => 'hi'], time() + 600);
        $this->setEntityId($event, 7);

        $repo = $this->createStub(WidgetEventRepository::class);
        $repo->method('findStreamEventsSince')->willReturn([$event]);

        $store = new DatabaseWidgetEventStore($repo);

        $mapped = $store->getNewEvents('w1', 's1');

        $this->assertCount(1, $mapped);
        $this->assertSame(7, $mapped[0]['id']);
        $this->assertSame('message', $mapped[0]['type']);
        $this->assertSame($event->getCreated(), $mapped[0]['timestamp']);
        $this->assertSame(['text' => 'hi'], $mapped[0]['payload']);
    }

    public function testGetNewNotificationsKeepsStrictCursor(): void
    {
        $repo = $this->createMock(WidgetEventRepository::class);
        // Notifications must never use the grace window (strict id semantics):
        // the fifth argument (grace cutoff) is always 0.
        $repo->expects($this->once())
            ->method('findStreamEventsSince')
            ->with('w1', 'notifications', 5, $this->isInt(), 0)
            ->willReturn([]);

        $store = new DatabaseWidgetEventStore($repo);

        $store->getNewNotifications('w1', 5);
    }

    private function setEntityId(WidgetEvent $event, int $id): void
    {
        $ref = new \ReflectionProperty(WidgetEvent::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($event, $id);
    }
}
