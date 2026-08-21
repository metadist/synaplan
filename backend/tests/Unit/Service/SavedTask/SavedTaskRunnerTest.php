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
use App\Service\Media\GeneratedFileRegistrar;
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
            $this->createStub(GeneratedFileRegistrar::class),
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
        $capturedOptions = null;
        $processor = $this->createMock(MessageProcessor::class);
        $processor->method('process')->willReturnCallback(function (Message $message, array $options) use (&$captured, &$capturedOptions): array {
            $captured = $message;
            $capturedOptions = $options;

            return [
                'success' => true,
                'response' => [
                    'content' => 'Bild erstellt und in Nextcloud gespeichert.',
                    'metadata' => [
                        'provider' => 'google',
                        'model' => 'imagen',
                        'file' => ['path' => '/api/v1/files/uploads/01/cat.png', 'type' => 'image'],
                    ],
                ],
                'classification' => ['language' => 'de'],
            ];
        });

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        // Like the real flush: assign ids to new messages, because setMeta()
        // copies the message id into MessageMeta (non-nullable int).
        $em->method('flush')->willReturnCallback(function () use (&$persisted): void {
            foreach ($persisted as $i => $entity) {
                if ($entity instanceof Message && null === $entity->getId()) {
                    $ref = new \ReflectionProperty(Message::class, 'id');
                    $ref->setValue($entity, 1000 + $i);
                }
            }
        });

        // The run's output file must be registered as a generated BFILES row
        // so it appears in the file manager's Generated gallery — scheduled
        // and manual runs bypass the SSE channel that normally does this.
        $registrar = $this->createMock(GeneratedFileRegistrar::class);
        $registrar->expects($this->once())
            ->method('register')
            ->with(9, '/api/v1/files/uploads/01/cat.png', 'image')
            ->willReturn(null);

        $runner = new SavedTaskRunner(
            $config,
            $tasks,
            $this->createStub(SavedTaskRunRepository::class),
            $prompts,
            $users,
            $chats,
            $em,
            $processor,
            $rateLimits,
            $this->createStub(TaskPlanStore::class),
            $registrar,
            $this->createStub(InternalEmailService::class),
            $this->createStub(LoggerInterface::class),
        );

        $result = $runner->run(9, 11, '', 'manual');

        $this->assertSame('completed', $result['run']->getStatus(), 'run error: '.(string) $result['run']->getError());
        $this->assertInstanceOf(Message::class, $captured);
        $this->assertSame('Erstelle ein realistisches Bild einer Katze', $captured->getText());
        // The classification source must mark this as a Saved Task run so
        // TaskPlanExecutor still plans multi-step instructions.
        $this->assertTrue($capturedOptions['saved_task'] ?? false);
        // The incoming message must not stay stuck in "processing".
        $this->assertSame('complete', $captured->getStatus());

        // The assistant reply is persisted into the task's chat (text + file).
        $replies = array_values(array_filter(
            $persisted,
            static fn (object $e): bool => $e instanceof Message && 'OUT' === $e->getDirection(),
        ));
        $this->assertCount(1, $replies);
        $this->assertSame('Bild erstellt und in Nextcloud gespeichert.', $replies[0]->getText());
        $this->assertSame('/api/v1/files/uploads/01/cat.png', $replies[0]->getFilePath());
        $this->assertSame('de', $replies[0]->getLanguage());
    }

    private function setId(SavedTask $task, int $id): void
    {
        $ref = new \ReflectionProperty(SavedTask::class, 'id');
        $ref->setValue($task, $id);
    }
}
