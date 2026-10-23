<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\StreamedResponseConnectionRelease;
use App\Service\Infrastructure\DatabaseConnectionRelease;
use App\Tests\Support\StubConnectionRegistry;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class StreamedResponseConnectionReleaseTest extends TestCase
{
    public function testAWorkerGivesItsConnectionBackAfterAStreamedResponse(): void
    {
        $this->terminate(new StreamedResponse(static function (): void {}), expectClose: true);
    }

    public function testAShortRequestKeepsItsConnection(): void
    {
        $this->terminate(new JsonResponse(['ok' => true]), expectClose: false);
        $this->terminate(new Response('ok'), expectClose: false);
    }

    public function testItRunsLastOnTerminate(): void
    {
        $attributes = (new \ReflectionClass(StreamedResponseConnectionRelease::class))->getAttributes(AsEventListener::class);

        self::assertCount(1, $attributes);
        $listener = $attributes[0]->newInstance();
        self::assertSame(KernelEvents::TERMINATE, $listener->event);
        self::assertLessThanOrEqual(-1024, $listener->priority);
    }

    private function terminate(Response $response, bool $expectClose): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects($expectClose ? self::once() : self::never())->method('close');

        $subscriber = new StreamedResponseConnectionRelease(
            new DatabaseConnectionRelease(new StubConnectionRegistry(['default' => $connection]), new NullLogger()),
        );
        $subscriber(new TerminateEvent($this->createStub(HttpKernelInterface::class), new Request(), $response));
    }
}
