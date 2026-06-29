<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Message\AdvanceMediaJobCommand;
use App\Service\Media\MediaJobDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The dispatcher is the single seam between the chat path and Messenger. It
 * must NEVER throw — a Redis outage there used to surface as a 500 to the
 * user; instead the caller gets a boolean and can fail the job synchronously
 * with a clean localized error.
 */
final class MediaJobDispatcherTest extends TestCase
{
    public function testReturnsTrueOnSuccessfulDispatch(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(AdvanceMediaJobCommand::class))
            ->willReturn(new Envelope(new AdvanceMediaJobCommand('k')));

        $dispatcher = new MediaJobDispatcher($bus, new NullLogger());

        self::assertTrue($dispatcher->dispatchKey('k'));
    }

    public function testReturnsFalseAndDoesNotThrowOnTransportFailure(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new TransportException('redis: connection refused'));

        $dispatcher = new MediaJobDispatcher($bus, new NullLogger());

        self::assertFalse($dispatcher->dispatchKey('k'));
    }

    public function testReturnsFalseOnGenericThrowable(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('serializer blew up'));

        $dispatcher = new MediaJobDispatcher($bus, new NullLogger());

        self::assertFalse($dispatcher->dispatchKey('k'));
    }
}
