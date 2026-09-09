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
                        'multitask' => true,
                        'task_plan_render' => [
                            'reply_node' => 'n2',
                            'cards' => [
                                ['nodeId' => 'n1', 'capability' => 'image_generation', 'kind' => 'image', 'state' => 'done'],
                                ['nodeId' => 'n2', 'capability' => 'save_to_folder', 'kind' => 'text', 'state' => 'done'],
                            ],
                        ],
                        'task_plan_definition' => [
                            'version' => 1,
                            'reply_node' => 'n2',
                            'tasks' => [
                                ['id' => 'n1', 'capability' => 'image_generation', 'depends_on' => [], 'inputs' => [], 'params' => []],
                                ['id' => 'n2', 'capability' => 'save_to_folder', 'depends_on' => ['n1'], 'inputs' => [], 'params' => []],
                            ],
                        ],
                    ],
                ],
                'classification' => ['language' => 'de', 'topic' => 'general', 'sorting_model_name' => 'sorter-x'],
                'search_results' => [
                    'query' => 'realistische katze',
                    'results' => [['url' => 'https://a.example'], ['url' => 'https://b.example']],
                ],
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
        // A chat-saved instruction (`saved-*` prompt) reruns like the turn the
        // user typed: through the AI sorter (web search vote, memories,
        // language), with the task id so the executor replays the pinned steps.
        // Pinning the prompt as a fixed topic skipped the sorter and lost the
        // tool calls the manual request made.
        $this->assertSame(11, $capturedOptions['saved_task_id'] ?? null);
        $this->assertArrayNotHasKey('fixed_task_prompt', $capturedOptions);
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
        // The IN row records the sorter's verdict, both rows carry the Sources
        // metas the chat reads — the rerun looks like the manual turn.
        $this->assertSame('general', $captured->getTopic());
        $this->assertSame('de', $captured->getLanguage());
        $this->assertSame('sorter-x', $replies[0]->getMeta('ai_sorting_model'));
        foreach ([$captured, $replies[0]] as $row) {
            $this->assertSame('realistische katze', $row->getMeta('web_search_query'));
            $this->assertSame('2', $row->getMeta('web_search_results_count'));
        }

        // The task chat must show the executed steps like the live chat does,
        // otherwise a replayed DAG is indistinguishable from a bare chat reply.
        $this->assertSame('1', $replies[0]->getMeta('multitask'));
        $taskPlan = json_decode((string) $replies[0]->getMeta('task_plan'), true);
        $this->assertIsArray($taskPlan);
        $this->assertCount(2, $taskPlan['cards']);
        $definition = json_decode((string) $replies[0]->getMeta('task_plan_definition'), true);
        $this->assertIsArray($definition);
        $this->assertSame('n2', $definition['reply_node']);

        // Both rows must carry a real timestamp — BUNIXTIMES defaults to 0 and
        // the task chat rendered every run under "01.01.1970" without these.
        $now = time();
        foreach ([$captured, $replies[0]] as $row) {
            $this->assertGreaterThan($now - 60, $row->getUnixTimestamp());
            $this->assertLessThanOrEqual($now, $row->getUnixTimestamp());
            $this->assertMatchesRegularExpression('/^\d{14}$/', $row->getDateTime());
        }
    }

    public function testTaskPromptRunKeepsTheAssistantPinned(): void
    {
        $task = new SavedTask(9, 4, 'Meeting requests');
        $this->setId($task, 12);
        $task->setChatId(77);

        $user = $this->createMock(User::class);
        $user->method('isActive')->willReturn(true);
        $user->method('getId')->willReturn(9);
        $user->method('getMail')->willReturn('demo@synaplan.com');

        // A real Task Prompt (an assistant the user authored) — not a chat
        // instruction saved by "Schedule this".
        $prompt = $this->createMock(Prompt::class);
        $prompt->method('isEnabled')->willReturn(true);
        $prompt->method('getTopic')->willReturn('meetings');
        $prompt->method('getPrompt')->willReturn('You triage meeting requests.');

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

        $capturedOptions = null;
        $processor = $this->createMock(MessageProcessor::class);
        $processor->method('process')->willReturnCallback(function (Message $message, array $options) use (&$capturedOptions): array {
            $capturedOptions = $options;

            return ['success' => true, 'response' => ['content' => 'ok', 'metadata' => []], 'classification' => []];
        });

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $em->method('flush')->willReturnCallback(function () use (&$persisted): void {
            foreach ($persisted as $i => $entity) {
                if ($entity instanceof Message && null === $entity->getId()) {
                    $ref = new \ReflectionProperty(Message::class, 'id');
                    $ref->setValue($entity, 2000 + $i);
                }
            }
        });

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
            $this->createStub(GeneratedFileRegistrar::class),
            $this->createStub(InternalEmailService::class),
            $this->createStub(LoggerInterface::class),
        );

        $result = $runner->run(9, 12, 'Look into my mail', 'manual');

        $this->assertSame('completed', $result['run']->getStatus(), 'run error: '.(string) $result['run']->getError());
        $this->assertSame('meetings', $capturedOptions['fixed_task_prompt'] ?? null);
        $this->assertTrue($capturedOptions['saved_task'] ?? false);
        $this->assertSame(12, $capturedOptions['saved_task_id'] ?? null);
    }

    private function setId(SavedTask $task, int $id): void
    {
        $ref = new \ReflectionProperty(SavedTask::class, 'id');
        $ref->setValue($task, $id);
    }
}
