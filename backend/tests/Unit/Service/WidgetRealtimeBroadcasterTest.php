<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Realtime\Channel\ChannelInterface;
use App\Realtime\Channel\WidgetOperatorsChannel;
use App\Realtime\Channel\WidgetSessionChannel;
use App\Realtime\Publisher\RealtimePublisherInterface;
use App\Service\WidgetRealtimeBroadcaster;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
final class WidgetRealtimeBroadcasterTest extends TestCase
{
    private RealtimePublisherInterface&MockObject $publisher;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->publisher = $this->createMock(RealtimePublisherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testPublishSessionEventDispatchesOnSessionChannel(): void
    {
        $this->publisher->expects($this->once())
            ->method('publish')
            ->with(
                $this->callback(static fn (ChannelInterface $c): bool => $c instanceof WidgetSessionChannel
                    && 'widget:session.w.s' === $c->name()),
                'message.received',
                ['x' => 1],
            );

        $this->buildBroadcaster()->publishSessionEvent('w', 's', 'message.received', ['x' => 1]);
    }

    public function testPublishSessionEventSwallowsPublisherErrors(): void
    {
        $this->publisher->expects($this->once())
            ->method('publish')
            ->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('publishSessionEvent failed'), $this->anything());

        $this->buildBroadcaster()->publishSessionEvent('w', 's', 'event', []);
    }

    public function testPublishOperatorNotificationFansOutOnOperatorsChannel(): void
    {
        $this->publisher->expects($this->once())
            ->method('publish')
            ->with(
                $this->callback(static fn (ChannelInterface $c): bool => $c instanceof WidgetOperatorsChannel
                    && 'widget:operators.w' === $c->name()),
                'notification',
                ['kind' => 'new_message'],
            );

        $this->buildBroadcaster()->publishOperatorNotification('w', ['kind' => 'new_message']);
    }

    public function testPublisherErrorOnNotificationDoesNotPropagate(): void
    {
        $this->publisher->expects($this->once())
            ->method('publish')
            ->willThrowException(new \RuntimeException('down'));
        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('publishOperatorNotification failed'), $this->anything());

        $this->buildBroadcaster()->publishOperatorNotification('w', []);
    }

    private function buildBroadcaster(): WidgetRealtimeBroadcaster
    {
        return new WidgetRealtimeBroadcaster(
            publisher: $this->publisher,
            logger: $this->logger,
        );
    }
}
