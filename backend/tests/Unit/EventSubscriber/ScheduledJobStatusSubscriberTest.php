<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\ScheduledJobStatusSubscriber;
use App\Service\Scheduler\ScheduledJobStatusStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class ScheduledJobStatusSubscriberTest extends TestCase
{
    private const NOW = 1_700_000_000;

    public function testStartRecordsOnlyRegistryCommandsAndKeepsThePreviousFinish(): void
    {
        $previous = [
            'lastStartedAt' => 10,
            'lastFinishedAt' => 20,
            'lastExitCode' => 5,
            'host' => 'previous-host',
        ];
        $written = null;
        $redis = $this->redis($previous, $written);
        $subscriber = $this->subscriber($redis, $this->createStub(LoggerInterface::class));

        $subscriber->onCommand($this->commandEvent('app:media:reap-jobs'));
        $subscriber->onCommand($this->commandEvent('app:model:list'));
        $subscriber->onCommand($this->commandEvent('app:scheduler:claim'));

        self::assertIsArray($written);
        self::assertSame(ScheduledJobStatusStore::key('app:media:reap-jobs'), $written['key']);
        self::assertSame(8 * 86400, $written['ttl']);
        self::assertSame(8 * 86400, ScheduledJobStatusStore::TTL_SECONDS);
        $payload = json_decode($written['value'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['lastStartedAt', 'lastFinishedAt', 'lastExitCode', 'host'], array_keys($payload));
        self::assertSame(self::NOW, $payload['lastStartedAt']);
        self::assertSame(20, $payload['lastFinishedAt']);
        self::assertSame(5, $payload['lastExitCode']);
        self::assertSame($this->expectedHost(), $payload['host']);
    }

    public function testStartWithNoHistoryStoresNullFinish(): void
    {
        $written = null;
        $redis = $this->redis(null, $written);
        $subscriber = $this->subscriber($redis, $this->createStub(LoggerInterface::class));

        $subscriber->onCommand($this->commandEvent('app:process-emails'));

        self::assertIsArray($written);
        $payload = json_decode($written['value'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(self::NOW, $payload['lastStartedAt']);
        self::assertNull($payload['lastFinishedAt']);
        self::assertNull($payload['lastExitCode']);
        self::assertSame($this->expectedHost(), $payload['host']);
    }

    public function testFinishKeepsStartAndHostAndSetsTheExitCode(): void
    {
        $previous = [
            'lastStartedAt' => 111,
            'lastFinishedAt' => 222,
            'lastExitCode' => 0,
            'host' => 'web-a',
        ];
        $written = null;
        $redis = $this->redis($previous, $written);
        $subscriber = $this->subscriber($redis, $this->createStub(LoggerInterface::class));
        $event = $this->terminateEvent('app:digest:run', 4);

        $subscriber->onTerminate($event);

        self::assertSame(4, $event->getExitCode());
        self::assertIsArray($written);
        self::assertSame(ScheduledJobStatusStore::key('app:digest:run'), $written['key']);
        $payload = json_decode($written['value'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(111, $payload['lastStartedAt']);
        self::assertSame(self::NOW, $payload['lastFinishedAt']);
        self::assertSame(4, $payload['lastExitCode']);
        self::assertSame('web-a', $payload['host']);
    }

    public function testRedisErrorsAreLoggedAndDoNotChangeTheExitCode(): void
    {
        $redis = $this->createStub(\App\Service\Infrastructure\RedisService::class);
        $redis->method('get')->willThrowException(new \RuntimeException('redis down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')
            ->with(self::stringContains('app:media:reap-jobs'), self::anything());
        $subscriber = $this->subscriber($redis, $logger);
        $event = $this->terminateEvent('app:media:reap-jobs', 7);

        $subscriber->onTerminate($event);
        $subscriber->onTerminate($this->terminateEvent('app:scheduler:status', 7));

        self::assertSame(7, $event->getExitCode());
    }

    public function testARejectedWriteAndAFailingLoggerAreSwallowed(): void
    {
        $redis = $this->createStub(\App\Service\Infrastructure\RedisService::class);
        $redis->method('get')->willReturn(null);
        $redis->method('set')->willReturn(false);
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willThrowException(new \RuntimeException('log down'));
        $subscriber = $this->subscriber($redis, $logger);
        $event = $this->commandEvent('app:chat:reap-stuck');

        $subscriber->onCommand($event);

        self::assertSame('app:chat:reap-stuck', $event->getCommand()?->getName());
    }

    /**
     * @param array<string, mixed>|null                        $previous
     * @param array{key: string, value: string, ttl: int}|null $written
     */
    private function redis(?array $previous, ?array &$written): \App\Service\Infrastructure\RedisService
    {
        $redis = $this->createStub(\App\Service\Infrastructure\RedisService::class);
        $redis->method('get')->willReturn(null === $previous ? null : json_encode($previous, \JSON_THROW_ON_ERROR));
        $redis->method('set')->willReturnCallback(function (string $key, string $value, ?int $ttl) use (&$written): bool {
            $written = ['key' => $key, 'value' => $value, 'ttl' => $ttl ?? 0];

            return true;
        });

        return $redis;
    }

    private function subscriber(\App\Service\Infrastructure\RedisService $redis, LoggerInterface $logger): ScheduledJobStatusSubscriber
    {
        return new ScheduledJobStatusSubscriber(
            new ScheduledJobStatusStore($redis),
            $logger,
            new MockClock(new \DateTimeImmutable('@'.self::NOW)),
        );
    }

    private function commandEvent(string $name): ConsoleCommandEvent
    {
        return new ConsoleCommandEvent(new Command($name), new ArrayInput([]), new NullOutput());
    }

    private function terminateEvent(string $name, int $exitCode): ConsoleTerminateEvent
    {
        return new ConsoleTerminateEvent(new Command($name), new ArrayInput([]), new NullOutput(), $exitCode);
    }

    private function expectedHost(): string
    {
        $hostname = gethostname();
        if (!is_string($hostname) || '' === $hostname) {
            return 'unknown';
        }

        return $hostname;
    }
}
