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

        $result = $runner->run(new TaskNode('n1', Capability::CodeRun), $context);

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(CodeRunRunner::QUOTA_COPY, $result->error);
    }
}
