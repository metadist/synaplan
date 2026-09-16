<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Compute;

use App\Entity\Message;
use App\Entity\User;
use App\Repository\ComputeRunRepository;
use App\Repository\FileRepository;
use App\Repository\UserRepository;
use App\Service\Compute\ComputeArtefactStore;
use App\Service\Compute\ComputeClient;
use App\Service\Compute\ComputeConfig;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\Runner\CodeRunRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\RateLimitService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ComputeRunQuotaTest extends TestCase
{
    public function testQuotaCopyIsTheCardSentence(): void
    {
        $this->assertSame(
            'You have used this week\'s file-work limit. Nothing new was saved.',
            CodeRunRunner::QUOTA_COPY,
        );
    }

    public function testRunnerReturnsQuotaCopyWhenLimitIsExceeded(): void
    {
        $config = $this->createStub(ComputeConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $user = $this->createStub(User::class);
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($user);
        $limits = $this->createStub(RateLimitService::class);
        $limits->method('checkLimit')->willReturn(['allowed' => false]);

        $runner = new CodeRunRunner(
            $config,
            $this->createStub(ComputeClient::class),
            $this->createStub(ComputeArtefactStore::class),
            $this->createStub(ComputeRunRepository::class),
            $this->createStub(FileRepository::class),
            $users,
            $limits,
            new NullLogger(),
            '/tmp',
        );
        $context = new NodeContext($this->createStub(Message::class), [], 7, []);

        $result = $runner->run(new TaskNode('n1', Capability::CodeRun, params: ['script' => 'print(1)']), $context);

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(CodeRunRunner::QUOTA_COPY, $result->error);
    }

    public function testRunnerReturnsQuotaCopyWhenDailyCpuIsExhausted(): void
    {
        $config = $this->createStub(ComputeConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('getRateLimitLevel')->willReturn('NEW');
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($user);
        $limits = $this->createStub(RateLimitService::class);
        $limits->method('checkLimit')->willReturn(['allowed' => true]);
        $limits->method('computeIntSetting')->willReturn(60);
        $runs = $this->createStub(ComputeRunRepository::class);
        $runs->method('sumDurationMsSince')->willReturn(60_000);
        $client = $this->createMock(ComputeClient::class);
        $client->expects($this->never())->method('submitRun');

        $runner = new CodeRunRunner(
            $config,
            $client,
            $this->createStub(ComputeArtefactStore::class),
            $runs,
            $this->createStub(FileRepository::class),
            $users,
            $limits,
            new NullLogger(),
            '/tmp',
        );
        $result = $runner->run(
            new TaskNode('n1', Capability::CodeRun, params: ['script' => 'print(1)']),
            new NodeContext($this->createStub(Message::class), [], 7, []),
        );

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(CodeRunRunner::QUOTA_COPY, $result->error);
    }

    public function testRunnerReturnsQuotaCopyWhenConcurrentCapWouldBeExceeded(): void
    {
        $config = $this->createStub(ComputeConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('clampLimits')->willReturn([
            'timeoutSec' => 10,
            'memoryMb' => 512,
            'cpu' => 1.0,
            'pids' => 128,
            'outputMb' => 50,
        ]);
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('getRateLimitLevel')->willReturn('NEW');
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($user);
        $limits = $this->createStub(RateLimitService::class);
        $limits->method('checkLimit')->willReturn(['allowed' => true]);
        $limits->method('computeIntSetting')->willReturnCallback(
            static fn (User $_user, string $setting, int $fallback): int => 'COMPUTE_CONCURRENT' === $setting ? 1 : 60,
        );
        $runs = $this->createStub(ComputeRunRepository::class);
        $runs->method('sumDurationMsSince')->willReturn(0);
        $runs->method('countActiveForUser')->willReturn(2);
        $client = $this->createMock(ComputeClient::class);
        $client->expects($this->never())->method('submitRun');

        $runner = new CodeRunRunner(
            $config,
            $client,
            $this->createStub(ComputeArtefactStore::class),
            $runs,
            $this->createStub(FileRepository::class),
            $users,
            $limits,
            new NullLogger(),
            '/tmp',
        );
        $result = $runner->run(
            new TaskNode('n1', Capability::CodeRun, params: ['script' => 'print(1)']),
            new NodeContext($this->createStub(Message::class), [], 7, []),
        );

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(CodeRunRunner::QUOTA_COPY, $result->error);
    }
}
