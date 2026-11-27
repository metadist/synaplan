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

    public function testEveryReadNamesTheOwner(): void
    {
        $seen = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $headers = $options['normalized_headers'] ?? [];
            $seen[] = [$method, (string) parse_url($url, \PHP_URL_PATH), $headers['x-compute-owner'][0] ?? null];

            return new MockResponse(str_contains($url, '/usage') ? '{"usedMb":0,"quotaMb":1,"fileCount":0,"lastUsedAt":""}' : '[]', ['http_code' => 200]);
        });
        $client = new ComputeClient($http, $this->config());

        $client->listArtefacts('user:42', 'run1');
        $client->downloadArtefact('user:42', 'run1', 'out.txt');
        $client->workspaceUsage('user:42', 'ws1');
        $client->listWorkspaceFiles('user:42', 'ws1');
        $client->downloadWorkspaceFile('user:42', 'ws1', 'a/b.txt');
        $client->collectLogs('user:42', 'run1');

        $this->assertCount(6, $seen);
        foreach ($seen as [$method, $path, $owner]) {
            $this->assertSame('GET', $method);
            $this->assertSame('X-Compute-Owner: user:42', $owner, $path);
        }
    }

    public function testAReadWithoutAnOwnerNeverLeavesTheBackend(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            self::fail('No request may be sent without an owner.');
        });

        $this->expectException(ComputeRefusedException::class);
        $this->expectExceptionMessage('owner is required');
        (new ComputeClient($http, $this->config()))->status('', 'run1');
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
