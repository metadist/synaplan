<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\AdminSchedulerController;
use App\Service\Infrastructure\RedisService;
use App\Service\Scheduler\ScheduledJobStatusReader;
use App\Service\Scheduler\ScheduledJobStatusStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Container;

/**
 * The /status payload is the contract the admin card is built against.
 */
final class AdminSchedulerControllerTest extends TestCase
{
    private const NOW = 1_759_310_400;

    public function testStatusGroupsJobsByLaneWithFailuresAndUnfinishedRuns(): void
    {
        $controller = $this->controller([
            'app:media:reap-jobs' => [self::NOW - 21, self::NOW - 20, 0],
            'app:chat:reap-stuck' => [self::NOW - 20, self::NOW - 19, 0],
            'app:updates:check' => [self::NOW - 3600, self::NOW - 3590, 1],
            'app:digest:run' => [self::NOW - 600, self::NOW - 90_000, 0],
        ]);

        $response = $controller->status();
        $payload = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['state', 'maxAgeSeconds', 'checkedAt', 'lastRunAt', 'lanes'], array_keys($payload));
        self::assertSame(ScheduledJobStatusReader::STATE_RUNNING, $payload['state']);
        self::assertSame(600, $payload['maxAgeSeconds']);
        self::assertSame(self::NOW - 19, $payload['lastRunAt']);
        self::assertSame(['tick', 'tasks', 'hourly', 'daily', 'health'], array_column($payload['lanes'], 'lane'));

        $daily = $payload['lanes'][3];
        self::assertSame([
            'lane' => 'daily',
            'lastStartedAt' => self::NOW - 600,
            'lastFinishedAt' => self::NOW - 3590,
            'failedJobs' => ['app:updates:check'],
            'unfinishedJobs' => ['app:digest:run'],
        ], $daily);

        $tasks = $payload['lanes'][1];
        self::assertSame(['lane' => 'tasks', 'lastStartedAt' => null, 'lastFinishedAt' => null, 'failedJobs' => [], 'unfinishedJobs' => []], $tasks);
    }

    public function testNeverWhenNoEveryMinuteJobFinished(): void
    {
        $payload = json_decode((string) $this->controller([])->status()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(ScheduledJobStatusReader::STATE_NEVER, $payload['state']);
        self::assertNull($payload['lastRunAt']);
    }

    public function testUnreadableStatusIsAServiceUnavailableAnswer(): void
    {
        $redis = $this->createStub(RedisService::class);
        $redis->method('isAvailable')->willReturn(false);

        $response = $this->wire(new ScheduledJobStatusReader(new ScheduledJobStatusStore($redis)))->status();

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['error' => 'Background job status is unavailable'], json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, array{0: int, 1: int, 2: int}> $runs command => [startedAt, finishedAt, exitCode]
     */
    private function controller(array $runs): AdminSchedulerController
    {
        $redis = $this->createStub(RedisService::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('get')->willReturnCallback(static function (string $key) use ($runs): ?string {
            foreach ($runs as $command => [$started, $finished, $exitCode]) {
                if (ScheduledJobStatusStore::key($command) === $key) {
                    return json_encode([
                        'lastStartedAt' => $started,
                        'lastFinishedAt' => $finished,
                        'lastExitCode' => $exitCode,
                        'host' => 'web-1',
                    ], \JSON_THROW_ON_ERROR);
                }
            }

            return null;
        });

        return $this->wire(new ScheduledJobStatusReader(
            new ScheduledJobStatusStore($redis),
            new MockClock(new \DateTimeImmutable('@'.self::NOW)),
        ));
    }

    private function wire(ScheduledJobStatusReader $reader): AdminSchedulerController
    {
        $controller = new AdminSchedulerController($reader, new NullLogger());
        $container = new Container();
        $container->set('serializer', new class {
            public function serialize(mixed $data, string $format): string
            {
                return json_encode($data, \JSON_THROW_ON_ERROR);
            }
        });
        $controller->setContainer($container);

        return $controller;
    }
}
