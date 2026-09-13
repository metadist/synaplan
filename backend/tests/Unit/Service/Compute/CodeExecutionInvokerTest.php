<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Compute;

use App\AI\Messages\Tools\CodeExecutionInvoker;
use App\Entity\ComputeRun;
use App\Entity\File;
use App\Entity\User;
use App\Repository\ComputeRunRepository;
use App\Repository\FileRepository;
use App\Repository\UserRepository;
use App\Service\Compute\ComputeArtefactStore;
use App\Service\Compute\ComputeClient;
use App\Service\Compute\ComputeConfig;
use App\Service\Multitask\Execution\Runner\CodeRunRunner;
use App\Service\RateLimitService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CodeExecutionInvokerTest extends TestCase
{
    public function testOwnerIsKeyOwner(): void
    {
        $invoker = new CodeExecutionInvoker($this->runnerThatRefusesForeignFiles());
        $owner = $this->user(7);
        $result = $invoker->invoke($owner, [
            'language' => 'python',
            'code' => 'print(1)',
            'input_file_ids' => [99],
        ], ComputeRun::VIA_GATEWAY_ANTHROPIC);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('not yours', $result['text']);
    }

    public function testForeignFileIdsRefused(): void
    {
        $this->testOwnerIsKeyOwner();
    }

    public function testTimeoutClampedToCaps(): void
    {
        $invoker = new CodeExecutionInvoker($this->createMock(CodeRunRunner::class));
        $ref = new \ReflectionMethod($invoker, 'parseInput');
        $parsed = $ref->invoke($invoker, [
            'language' => 'python',
            'code' => 'print(1)',
            'timeout_sec' => 9999,
        ]);

        self::assertSame(300, $parsed['timeout_sec']);
        self::assertNull($parsed['error']);
    }

    public function testInvalidLanguageRejected(): void
    {
        $invoker = new CodeExecutionInvoker($this->createMock(CodeRunRunner::class));
        $result = $invoker->invoke($this->user(1), [
            'language' => 'ruby',
            'code' => 'puts 1',
        ], ComputeRun::VIA_GATEWAY_OPENAI);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('python or node', $result['text']);
    }

    private function runnerThatRefusesForeignFiles(): CodeRunRunner
    {
        $config = $this->createStub(ComputeConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('clampLimits')->willReturn([
            'timeoutSec' => 60,
            'memoryMb' => 512,
            'cpu' => 1.0,
            'pids' => 128,
            'outputMb' => 50,
        ]);
        $file = $this->createStub(File::class);
        $file->method('getUserId')->willReturn(99);
        $files = $this->createStub(FileRepository::class);
        $files->method('find')->willReturn($file);
        $user = $this->user(7);
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($user);
        $limits = $this->createStub(RateLimitService::class);
        $limits->method('checkLimit')->willReturn(['allowed' => true]);
        $runs = $this->createStub(ComputeRunRepository::class);
        $runs->method('countActiveForUser')->willReturn(0);

        return new CodeRunRunner(
            $config,
            $this->createStub(ComputeClient::class),
            $this->createStub(ComputeArtefactStore::class),
            $runs,
            $files,
            $users,
            $limits,
            new NullLogger(),
            '/tmp',
        );
    }

    private function user(int $id): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getRateLimitLevel')->willReturn('NEW');

        return $user;
    }
}
