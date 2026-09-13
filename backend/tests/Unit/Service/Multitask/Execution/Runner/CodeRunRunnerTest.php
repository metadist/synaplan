<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ComputeRunRepository;
use App\Repository\FileRepository;
use App\Repository\UserRepository;
use App\Service\Compute\ComputeArtefactStore;
use App\Service\Compute\ComputeClient;
use App\Service\Compute\ComputeConfig;
use App\Service\Compute\Contract\ComputeRunRequest;
use App\Service\Compute\Contract\ComputeRunStatus;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\Runner\CodeRunRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\RateLimitService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CodeRunRunnerTest extends TestCase
{
    public function testRejectsForeignFileIds(): void
    {
        $foreign = $this->createStub(File::class);
        $foreign->method('getUserId')->willReturn(99);
        $files = $this->createStub(FileRepository::class);
        $files->method('find')->willReturn($foreign);

        $client = $this->createMock(ComputeClient::class);
        $client->expects($this->never())->method('submitRun');

        $result = $this->runner($client, $files)->run(
            new TaskNode('n1', Capability::CodeRun, params: [
                'script' => 'print(1)',
                'inputFileIds' => [55],
            ]),
            $this->context(),
        );

        $this->assertFalse($result->isSuccessful());
        $this->assertSame('A selected file is not yours. Nothing new was saved.', $result->error);
    }

    public function testClampsLimitsToCaps(): void
    {
        $captured = null;
        $client = $this->createMock(ComputeClient::class);
        $client->expects($this->once())
            ->method('submitRun')
            ->willReturnCallback(static function (ComputeRunRequest $request) use (&$captured): string {
                $captured = $request;

                return '01ARZ3NDEKTSV4RRFFQ69G5FAV';
            });
        $client->method('status')->willReturn(new ComputeRunStatus(
            runId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            status: 'succeeded',
            usage: ['wallMs' => 10, 'cpuSec' => 0.1, 'maxMemoryMb' => 32, 'bytesIn' => 1, 'bytesOut' => 1],
            truncated: ['stdout' => false, 'stderr' => false],
            exitCode: 0,
            durationMs: 10,
        ));
        $artefacts = $this->createStub(ComputeArtefactStore::class);
        $artefacts->method('ingest')->willReturn([]);

        $this->runner($client, $this->createStub(FileRepository::class), $artefacts)->run(
            new TaskNode('n1', Capability::CodeRun, params: [
                'script' => 'print(1)',
                'limits' => [
                    'timeoutSec' => 999,
                    'memoryMb' => 9999,
                    'cpu' => 8.0,
                    'pids' => 9999,
                    'outputMb' => 999,
                ],
            ]),
            $this->context(),
        );

        $this->assertInstanceOf(ComputeRunRequest::class, $captured);
        $this->assertSame(60, $captured->limits['timeoutSec']);
        $this->assertSame(512, $captured->limits['memoryMb']);
        $this->assertSame(1.0, $captured->limits['cpu']);
        $this->assertSame(128, $captured->limits['pids']);
        $this->assertSame(50, $captured->limits['outputMb']);
    }

    private function runner(
        ComputeClient $client,
        FileRepository $files,
        ?ComputeArtefactStore $artefacts = null,
    ): CodeRunRunner {
        $repo = $this->createStub(\App\Repository\ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => 'ENABLED' === $setting ? '1' : null,
        );
        $config = new ComputeConfig($repo, 'http://compute:8080', 'token-token-token-token-token-32b');

        $user = $this->createStub(User::class);
        $user->method('getRateLimitLevel')->willReturn('NEW');
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($user);

        $limits = $this->createStub(RateLimitService::class);
        $limits->method('checkLimit')->willReturn(['allowed' => true]);

        $runs = $this->createStub(ComputeRunRepository::class);
        $runs->method('countActiveForUser')->willReturn(0);

        return new CodeRunRunner(
            $config,
            $client,
            $artefacts ?? $this->createStub(ComputeArtefactStore::class),
            $runs,
            $files,
            $users,
            $limits,
            new NullLogger(),
            '/tmp',
        );
    }

    private function context(): NodeContext
    {
        $message = $this->createStub(Message::class);
        $message->method('getId')->willReturn(11);

        return new NodeContext($message, [], 7, []);
    }
}
