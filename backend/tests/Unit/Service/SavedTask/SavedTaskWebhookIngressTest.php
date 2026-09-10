<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Entity\SavedTask;
use App\Entity\User;
use App\Repository\SavedTaskRepository;
use App\Repository\UserRepository;
use App\Service\RateLimitService;
use App\Service\SavedTask\SavedTaskRunner;
use App\Service\SavedTask\SavedTaskWebhookIngress;
use App\Service\SavedTask\WorkflowsConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Response;

final class SavedTaskWebhookIngressTest extends TestCase
{
    private SavedTaskRepository&MockObject $tasks;
    private UserRepository&MockObject $users;
    private SavedTaskRunner&MockObject $runner;
    private WorkflowsConfig&MockObject $workflows;
    private RateLimitService&MockObject $rateLimits;
    private SavedTaskWebhookIngress $ingress;

    protected function setUp(): void
    {
        $this->tasks = $this->createMock(SavedTaskRepository::class);
        $this->users = $this->createMock(UserRepository::class);
        $this->runner = $this->createMock(SavedTaskRunner::class);
        $this->workflows = $this->createMock(WorkflowsConfig::class);
        $this->rateLimits = $this->createMock(RateLimitService::class);
        $this->ingress = new SavedTaskWebhookIngress(
            $this->tasks,
            $this->users,
            $this->runner,
            $this->workflows,
            $this->rateLimits,
            new ArrayAdapter(),
        );
    }

    public function testUnknownTokenIsNotFound(): void
    {
        $this->tasks->method('findByWebhookToken')->willReturn(null);

        $result = $this->ingress->handle('missing', '{}', null);

        self::assertSame(Response::HTTP_NOT_FOUND, $result['status']);
        $this->runner->expects(self::never())->method('run');
    }

    public function testDisabledOrFlagOffUsesTheSameNotFound(): void
    {
        $task = new SavedTask(9, 12, 'From n8n');
        $task->setEnabled(false);
        $task->setTrigger(SavedTask::TRIGGER_WEBHOOK, ['token' => 'tok']);
        $this->tasks->method('findByWebhookToken')->willReturn($task);

        $result = $this->ingress->handle('tok', '{}', null);

        self::assertSame(Response::HTTP_NOT_FOUND, $result['status']);
    }

    public function testBadHmacIsUnauthorized(): void
    {
        $task = $this->enabledWebhookTask(['token' => 'tok', 'hmacSecret' => 's3cret']);
        $this->tasks->method('findByWebhookToken')->willReturn($task);
        $this->workflows->method('isBuilderEnabled')->willReturn(true);

        $result = $this->ingress->handle('tok', '{"a":1}', 'sha256=deadbeef');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $result['status']);
        $this->runner->expects(self::never())->method('run');
    }

    public function testValidCallRunsAsOwner(): void
    {
        $task = $this->enabledWebhookTask(['token' => 'tok']);
        $this->tasks->method('findByWebhookToken')->willReturn($task);
        $this->workflows->method('isBuilderEnabled')->willReturn(true);
        $user = $this->createMock(User::class);
        $user->method('isActive')->willReturn(true);
        $this->users->expects(self::once())->method('find')->with(9)->willReturn($user);
        $this->rateLimits->method('checkLimit')->willReturn(['allowed' => true]);
        $this->runner->expects(self::once())->method('run')->with(9, 0, '', 'webhook', ['hello' => 'world']);

        $result = $this->ingress->handle('tok', '{"hello":"world"}', null);

        self::assertSame(Response::HTTP_ACCEPTED, $result['status']);
    }

    public function testSixtyFirstCallInAMinuteIsRateLimited(): void
    {
        $task = $this->enabledWebhookTask(['token' => 'tok']);
        $this->tasks->method('findByWebhookToken')->willReturn($task);
        $this->workflows->method('isBuilderEnabled')->willReturn(true);
        $user = $this->createMock(User::class);
        $user->method('isActive')->willReturn(true);
        $this->users->method('find')->willReturn($user);
        $this->rateLimits->method('checkLimit')->willReturn(['allowed' => true]);
        $this->runner->method('run')->willReturn([]);

        $last = ['status' => 0, 'body' => []];
        for ($i = 0; $i < 61; ++$i) {
            $last = $this->ingress->handle('tok', '{}', null);
        }

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $last['status']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function enabledWebhookTask(array $config): SavedTask
    {
        $task = new SavedTask(9, 12, 'From n8n');
        $task->setTrigger(SavedTask::TRIGGER_WEBHOOK, $config);

        return $task;
    }
}
