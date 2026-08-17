<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\Prompt;
use App\Entity\SavedTask;
use App\Entity\User;
use App\Repository\ChatRepository;
use App\Repository\PromptRepository;
use App\Repository\SavedTaskRepository;
use App\Repository\SavedTaskRunRepository;
use App\Repository\UserRepository;
use App\Service\InternalEmailService;
use App\Service\Message\MessageProcessor;
use App\Service\Multitask\TaskPlanStore;
use App\Service\RateLimitService;
use App\Service\SavedTask\SavedTaskConfig;
use App\Service\SavedTask\SavedTaskRunner;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

final class SavedTaskRunnerTest extends TestCase
{
    public function testConstructorDoesNotDependOnSecurity(): void
    {
        $params = (new \ReflectionClass(SavedTaskRunner::class))->getConstructor()?->getParameters() ?? [];
        foreach ($params as $param) {
            $type = $param->getType();
            $this->assertFalse(
                $type instanceof \ReflectionNamedType && Security::class === $type->getName(),
                'SavedTaskRunner must not take Security'
            );
        }
    }

    public function testRateLimitedRunIsFailedWithReadableReason(): void
    {
        $task = new SavedTask(9, 4, 'Meeting requests');
        $this->setId($task, 11);

        $user = $this->createMock(User::class);
        $user->method('isActive')->willReturn(true);
        $user->method('getId')->willReturn(9);
        $user->method('getMail')->willReturn('demo@synaplan.com');

        $prompt = $this->createMock(Prompt::class);
        $prompt->method('isEnabled')->willReturn(true);
        $prompt->method('getTopic')->willReturn('meetings');

        $tasks = $this->createMock(SavedTaskRepository::class);
        $tasks->method('findByIdAndOwner')->willReturn($task);
        $tasks->expects($this->atLeastOnce())->method('save');

        $runs = $this->createMock(SavedTaskRunRepository::class);
        $runs->expects($this->atLeastOnce())->method('save');

        $config = $this->createMock(SavedTaskConfig::class);
        $config->method('isEnabled')->willReturn(true);

        $users = $this->createMock(UserRepository::class);
        $users->method('find')->willReturn($user);

        $prompts = $this->createMock(PromptRepository::class);
        $prompts->method('find')->willReturn($prompt);

        $rateLimits = $this->createMock(RateLimitService::class);
        $rateLimits->method('checkLimit')->willReturn(['allowed' => false]);
        $rateLimits->expects($this->never())->method('recordUsage');

        $processor = $this->createMock(MessageProcessor::class);
        $processor->expects($this->never())->method('process');

        $runner = new SavedTaskRunner(
            $config,
            $tasks,
            $runs,
            $prompts,
            $users,
            $this->createStub(ChatRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $processor,
            $rateLimits,
            $this->createStub(TaskPlanStore::class),
            $this->createStub(InternalEmailService::class),
            $this->createStub(LoggerInterface::class),
        );

        $result = $runner->run(9, 11, 'Look into my mail', 'manual');
        $this->assertSame('failed', $result['run']->getStatus());
        $this->assertSame('Your usage limit was reached, so this run was skipped.', $result['run']->getError());
        $this->assertSame(1, $result['task']->getConsecutiveFailures());
    }

    public function testBlankMessageFallsBackToTheStoredInstruction(): void
    {
        $task = new SavedTask(9, 4, 'Katzenbild');
        $this->setId($task, 11);
        $task->setChatId(77);

        $user = $this->createMock(User::class);
        $user->method('isActive')->willReturn(true);
        $user->method('getId')->willReturn(9);
        $user->method('getMail')->willReturn('demo@synaplan.com');

        $prompt = $this->createMock(Prompt::class);
        $prompt->method('isEnabled')->willReturn(true);
        $prompt->method('getTopic')->willReturn('saved-1');
        $prompt->method('getPrompt')->willReturn('Erstelle ein realistisches Bild einer Katze');

        $chat = $this->createMock(Chat::class);
        $chat->method('getUserId')->willReturn(9);
        $chat->method('getId')->willReturn(77);

        $chats = $this->createMock(ChatRepository::class);
        $chats->method('find')->willReturn($chat);

        $tasks = $this->createMock(SavedTaskRepository::class);
        $tasks->method('findByIdAndOwner')->willReturn($task);

        $config = $this->createMock(SavedTaskConfig::class);
        $config->method('isEnabled')->willReturn(true);

        $users = $this->createMock(UserRepository::class);
        $users->method('find')->willReturn($user);

        $prompts = $this->createMock(PromptRepository::class);
        $prompts->method('find')->willReturn($prompt);

        $rateLimits = $this->createMock(RateLimitService::class);
        $rateLimits->method('checkLimit')->willReturn(['allowed' => true]);

        $captured = null;
        $processor = $this->createMock(MessageProcessor::class);
        $processor->method('process')->willReturnCallback(function (Message $message) use (&$captured): array {
            $captured = $message;

            return ['success' => true];
        });

        $runner = new SavedTaskRunner(
            $config,
            $tasks,
            $this->createStub(SavedTaskRunRepository::class),
            $prompts,
            $users,
            $chats,
            $this->createStub(EntityManagerInterface::class),
            $processor,
            $rateLimits,
            $this->createStub(TaskPlanStore::class),
            $this->createStub(InternalEmailService::class),
            $this->createStub(LoggerInterface::class),
        );

        $result = $runner->run(9, 11, '', 'manual');

        $this->assertSame('completed', $result['run']->getStatus());
        $this->assertInstanceOf(Message::class, $captured);
        $this->assertSame('Erstelle ein realistisches Bild einer Katze', $captured->getText());
    }

    private function setId(SavedTask $task, int $id): void
    {
        $ref = new \ReflectionProperty(SavedTask::class, 'id');
        $ref->setValue($task, $id);
    }
}
