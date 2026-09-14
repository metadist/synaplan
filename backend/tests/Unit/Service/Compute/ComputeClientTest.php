<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Compute;

use App\Repository\ConfigRepository;
use App\Service\Compute\ComputeClient;
use App\Service\Compute\ComputeConfig;
use App\Service\Compute\ComputeRefusedException;
use App\Service\Compute\Contract\ComputeRunRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ComputeClientTest extends TestCase
{
    public function testSubmitAlwaysSetsOwner(): void
    {
        $captured = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? ''];

            return new MockResponse('{"runId":"01ARZ3NDEKTSV4RRFFQ69G5FAV"}', ['http_code' => 200]);
        });
        $client = new ComputeClient($http, $this->config());

        $runId = $client->submitRun($this->request('user:42'), [['name' => 'main.py', 'contents' => 'print(1)']]);

        $this->assertSame('01ARZ3NDEKTSV4RRFFQ69G5FAV', $runId);
        $this->assertSame('POST', $captured['method']);
        $this->assertStringContainsString('/v1/runs', $captured['url']);
        $body = self::bodyToString($captured['body'] ?? '');
        $this->assertStringContainsString('"owner":"user:42"', $body);
        $this->assertStringContainsString('name="request.json"', $body);
    }

    public function testSubmitRefusesDuplicateFileNames(): void
    {
        $client = new ComputeClient(new MockHttpClient(), $this->config());

        $this->expectException(ComputeRefusedException::class);
        $this->expectExceptionMessage('Duplicate file name');
        $client->submitRun($this->request('user:1'), [
            ['name' => 'data.csv', 'contents' => 'a'],
            ['name' => 'data.csv', 'contents' => 'b'],
        ]);
    }

    public function testSubmitRefusesEmptyOwner(): void
    {
        $client = new ComputeClient(new MockHttpClient(), $this->config());

        $this->expectException(ComputeRefusedException::class);
        $this->expectExceptionMessage('owner is required');
        $client->submitRun($this->request(''), []);
    }

    private static function bodyToString(mixed $body): string
    {
        if ($body instanceof \Closure) {
            $body = $body();
        }
        if (is_iterable($body)) {
            $chunks = [];
            foreach ($body as $chunk) {
                $chunks[] = is_scalar($chunk) ? (string) $chunk : '';
            }

            return implode('', $chunks);
        }

        return is_scalar($body) ? (string) $body : '';
    }

    private function config(): ComputeConfig
    {
        $repo = $this->createStub(ConfigRepository::class);
        $repo->method('getValue')->willReturn(null);

        return new ComputeConfig($repo, 'http://compute:8080', 'token-token-token-token-token-32b');
    }

    private function request(string $owner): ComputeRunRequest
    {
        return new ComputeRunRequest(
            protocol: 1,
            owner: $owner,
            workspace: ['kind' => 'run'],
            image: 'python',
            entry: ['program' => 'python', 'args' => ['main.py']],
            files: [['name' => 'main.py', 'role' => 'input']],
            limits: ['timeoutSec' => 60, 'memoryMb' => 512, 'cpu' => 1.0, 'pids' => 128, 'outputMb' => 50],
            egress: ['allow' => []],
        );
    }
}
