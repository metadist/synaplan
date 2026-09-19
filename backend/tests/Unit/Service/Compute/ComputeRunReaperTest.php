<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Compute;

use App\Entity\ComputeRun;
use App\Repository\ComputeRunRepository;
use App\Repository\ConfigRepository;
use App\Service\Compute\ComputeClient;
use App\Service\Compute\ComputeConfig;
use App\Service\Compute\ComputeRunReaper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ComputeRunReaperTest extends TestCase
{
    public function testIdleWhenDisabled(): void
    {
        $runs = $this->createMock(ComputeRunRepository::class);
        $runs->expects(self::never())->method('findStaleOpenRuns');
        $reaper = new ComputeRunReaper($this->config(enabled: false), $this->client([]), $runs, new NullLogger());

        self::assertSame(['closed' => 0, 'purged' => 0, 'skipped' => 0], $reaper->reap());
    }

    public function testLostRunClosed(): void
    {
        $stale = $this->makeRun('run-stale', ComputeRun::STATUS_RUNNING);
        $runs = $this->createMock(ComputeRunRepository::class);
        $runs->method('findStaleOpenRuns')->willReturn([$stale]);
        $runs->method('findFinalRunsBefore')->willReturn([]);
        $runs->expects(self::once())->method('save')->with(self::callback(static function (ComputeRun $run): bool {
            return ComputeRun::STATUS_FAILED === $run->getStatus();
        }));

        $seen = [];
        $client = new ComputeClient(new MockHttpClient(static function (string $method, string $url) use (&$seen): MockResponse {
            $seen[] = $method.' '.$url;
            if (str_ends_with($url, '/v1/runs/run-stale')) {
                return new MockResponse('{"code":"not_found","message":"no such run","details":null}', ['http_code' => 404]);
            }

            return new MockResponse('', ['http_code' => 204]);
        }), $this->config(enabled: true));

        $reaper = new ComputeRunReaper($this->config(enabled: true), $client, $runs, new NullLogger());
        $result = $reaper->reap();

        self::assertSame(['closed' => 1, 'purged' => 0, 'skipped' => 0], $result);
        self::assertSame(ComputeRun::STATUS_FAILED, $stale->getStatus());
        self::assertContains('DELETE http://compute:8080/v1/runs/run-stale', $seen);
    }

    public function testStillOpenRunLeftAlone(): void
    {
        $open = $this->makeRun('run-open', ComputeRun::STATUS_RUNNING);
        $runs = $this->createMock(ComputeRunRepository::class);
        $runs->method('findStaleOpenRuns')->willReturn([$open]);
        $runs->method('findFinalRunsBefore')->willReturn([]);
        $runs->expects(self::never())->method('save');

        $client = new ComputeClient(new MockHttpClient(static fn (): MockResponse => new MockResponse((string) json_encode([
            'runId' => 'run-open',
            'status' => 'running',
            'usage' => ['wallMs' => 1, 'cpuSec' => 0.1, 'maxMemoryMb' => 8, 'bytesIn' => 0, 'bytesOut' => 0],
            'truncated' => ['stdout' => false, 'stderr' => false],
        ]), ['http_code' => 200])), $this->config(enabled: true));

        $reaper = new ComputeRunReaper($this->config(enabled: true), $client, $runs, new NullLogger());

        self::assertSame(['closed' => 0, 'purged' => 0, 'skipped' => 0], $reaper->reap());
        self::assertSame(ComputeRun::STATUS_RUNNING, $open->getStatus());
    }

    public function testFinalRunsPurged(): void
    {
        $done = $this->makeRun('run-done', ComputeRun::STATUS_SUCCEEDED);
        $runs = $this->createMock(ComputeRunRepository::class);
        $runs->method('findStaleOpenRuns')->willReturn([]);
        $runs->method('findFinalRunsBefore')->willReturn([$done]);

        $seen = [];
        $client = new ComputeClient(new MockHttpClient(static function (string $method, string $url) use (&$seen): MockResponse {
            $seen[] = $method.' '.$url;

            return new MockResponse('', ['http_code' => 204]);
        }), $this->config(enabled: true));

        $reaper = new ComputeRunReaper($this->config(enabled: true), $client, $runs, new NullLogger());

        self::assertSame(['closed' => 0, 'purged' => 1, 'skipped' => 0], $reaper->reap());
        self::assertContains('DELETE http://compute:8080/v1/runs/run-done', $seen);
    }

    public function testMetadataOnly(): void
    {
        // C5: the reaper reconciles rows; it never opens artefacts.
        $stale = $this->makeRun('run-stale', ComputeRun::STATUS_RUNNING);
        $runs = $this->createMock(ComputeRunRepository::class);
        $runs->method('findStaleOpenRuns')->willReturn([$stale]);
        $runs->method('findFinalRunsBefore')->willReturn([]);

        $seen = [];
        $client = new ComputeClient(new MockHttpClient(static function (string $method, string $url) use (&$seen): MockResponse {
            $seen[] = $url;
            if ('GET' === $method) {
                return new MockResponse('{"code":"not_found","message":"no such run","details":null}', ['http_code' => 404]);
            }

            return new MockResponse('', ['http_code' => 204]);
        }), $this->config(enabled: true));

        $reaper = new ComputeRunReaper($this->config(enabled: true), $client, $runs, new NullLogger());
        $reaper->reap();

        foreach ($seen as $url) {
            self::assertStringNotContainsString('/artefacts', $url);
            self::assertStringNotContainsString('/files', $url);
        }
        self::assertNotEmpty($seen);
    }

    private function makeRun(string $runId, string $status): ComputeRun
    {
        $run = new ComputeRun(7, $runId, 'chat', 'python', 'main.py', []);
        $run->setStatus($status);

        return $run;
    }

    private function config(bool $enabled): ComputeConfig
    {
        $repo = $this->createStub(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static fn (int $ownerId, string $group, string $setting): ?string => 'ENABLED' === $setting && $enabled ? '1' : null
        );

        return new ComputeConfig($repo, 'http://compute:8080', 'token-token-token-token-token-32b');
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses): ComputeClient
    {
        return new ComputeClient(new MockHttpClient($responses), $this->config(enabled: true));
    }
}
