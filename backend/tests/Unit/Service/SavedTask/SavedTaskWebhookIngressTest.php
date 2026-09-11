<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Entity\SavedTask;
use App\Entity\User;
use App\Message\RunSavedTaskCommand;
use App\Repository\SavedTaskRepository;
use App\Repository\UserRepository;
use App\Service\RateLimitService;
use App\Service\SavedTask\SavedTaskWebhookIngress;
use App\Service\SavedTask\WorkflowsConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class SavedTaskWebhookIngressTest extends TestCase
{
    private SavedTaskRepository&MockObject $tasks;
    private UserRepository&MockObject $users;
    private MessageBusInterface&MockObject $bus;
    private WorkflowsConfig&MockObject $workflows;
    private RateLimitService&MockObject $rateLimits;
    private SavedTaskWebhookIngress $ingress;

    protected function setUp(): void
    {
        $this->tasks = $this->createMock(SavedTaskRepository::class);
        $this->users = $this->createMock(UserRepository::class);
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->workflows = $this->createMock(WorkflowsConfig::class);
        $this->rateLimits = $this->createMock(RateLimitService::class);
        $this->ingress = new SavedTaskWebhookIngress(
            $this->tasks,
            $this->users,
            $this->bus,
            $this->workflows,
            $this->rateLimits,
            new RateLimiterFactory(
                ['id' => 'saved_task_webhook', 'policy' => 'sliding_window', 'limit' => 60, 'interval' => '1 minute'],
                new InMemoryStorage(),
            ),
        );
    }

    public function testUnknownTokenIsNotFound(): void
    {
        $this->tasks->method('findByWebhookToken')->willReturn(null);
        $this->bus->expects(self::never())->method('dispatch');

        $result = $this->ingress->handle('missing', '{}', null);

        self::assertSame(Response::HTTP_NOT_FOUND, $result['status']);
    }

    public function testDisabledOrFlagOffUsesTheSameNotFound(): void
    {
        $task = new SavedTask(9, 12, 'From n8n');
        $task->setEnabled(false);
        $task->setTrigger(SavedTask::TRIGGER_WEBHOOK, ['token' => 'tok']);
        $this->tasks->method('findByWebhookToken')->willReturn($task);
        $this->bus->expects(self::never())->method('dispatch');

        $result = $this->ingress->handle('tok', '{}', null);

        self::assertSame(Response::HTTP_NOT_FOUND, $result['status']);
    }

    public function testBadHmacIsUnauthorized(): void
    {
        $task = $this->enabledWebhookTask(['token' => 'tok', 'hmacSecret' => 's3cret']);
        $this->tasks->method('findByWebhookToken')->willReturn($task);
        $this->workflows->method('isBuilderEnabled')->willReturn(true);
        $this->bus->expects(self::never())->method('dispatch');

        $result = $this->ingress->handle('tok', '{"a":1}', 'sha256=deadbeef');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $result['status']);
    }

    public function testValidCallQueuesARunAsTheOwner(): void
    {
        $task = $this->enabledWebhookTask(['token' => 'tok']);
        $this->tasks->method('findByWebhookToken')->willReturn($task);
        $this->workflows->method('isBuilderEnabled')->willReturn(true);
        $user = $this->createMock(User::class);
        $user->method('isActive')->willReturn(true);
        $this->users->expects(self::once())->method('find')->with(9)->willReturn($user);
        $this->rateLimits->method('checkLimit')->willReturn(['allowed' => true]);
        $this->bus->expects(self::once())->method('dispatch')
            ->with(self::callback(static function (RunSavedTaskCommand $command): bool {
                return 9 === $command->ownerId
                    && 0 === $command->taskId
                    && 'webhook' === $command->trigger
                    && ['hello' => 'world'] === $command->triggerPayload;
            }))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

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
        $this->bus->expects(self::exactly(60))->method('dispatch')
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

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
