<?php

declare(strict_types=1);

namespace App\Service\SavedTask;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\Prompt;
use App\Entity\SavedTask;
use App\Entity\SavedTaskRun;
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
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Executes a Saved Task as the owner. Constructor has no Security / OIDC
 * dependency so cron and webhook runs share this path.
 */
final readonly class SavedTaskRunner
{
    public function __construct(
        private SavedTaskConfig $config,
        private SavedTaskRepository $tasks,
        private SavedTaskRunRepository $runs,
        private PromptRepository $prompts,
        private UserRepository $users,
        private ChatRepository $chats,
        private EntityManagerInterface $em,
        private MessageProcessor $processor,
        private RateLimitService $rateLimits,
        private TaskPlanStore $planStore,
        private InternalEmailService $mail,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Runs the task as its owner. `$messageText` is an OPTIONAL extra
     * instruction for this one run — when blank, the task's stored instruction
     * (the pinned prompt body) is used, so "Run now" and scheduled runs never
     * need a synthetic message.
     *
     * @return array{run: SavedTaskRun, task: SavedTask}
     */
    public function run(int $ownerId, int $taskId, string $messageText = '', string $trigger = 'manual'): array
    {
        if (!$this->config->isEnabled($ownerId)) {
            throw new SavedTaskDisabledException();
        }

        $task = $this->tasks->findByIdAndOwner($taskId, $ownerId);
        if (null === $task) {
            throw new SavedTaskNotFoundException();
        }

        $user = $this->users->find($ownerId);
        if (!$user instanceof User || !$user->isActive()) {
            $task->setEnabled(false);
            $this->tasks->save($task);
            throw new SavedTaskDisabledException('This account cannot run Saved Tasks');
        }

        $run = new SavedTaskRun((int) $task->getId(), $trigger);
        $this->runs->save($run);
        $run->markRunning();
        $this->runs->save($run);

        $limit = $this->rateLimits->checkLimit($user, 'MESSAGES');
        if (empty($limit['allowed'])) {
            return $this->fail($task, $run, 'Your usage limit was reached, so this run was skipped.');
        }

        $prompt = $this->prompts->find($task->getPromptId());
        if (!$prompt instanceof Prompt || !$prompt->isEnabled()) {
            return $this->fail($task, $run, 'The AI instruction for this task is missing or turned off.');
        }

        $text = trim($messageText);
        if ('' === $text) {
            $text = trim($prompt->getPrompt());
        }
        if ('' === $text) {
            return $this->fail($task, $run, 'The AI instruction for this task is empty.');
        }

        try {
            $chat = $this->ensureChat($task, $user);
            $message = new Message();
            $message->setUserId($ownerId);
            $message->setChat($chat);
            $message->setTrackingId(time());
            $message->setProviderIndex('WEB');
            $message->setMessageType('WEB');
            $message->setTopic('CHAT');
            $message->setText($text);
            $message->setDirection('IN');
            $message->setStatus('processing');
            $this->em->persist($message);
            $this->em->flush();

            $result = $this->processor->process($message, [
                'fixed_task_prompt' => $prompt->getTopic(),
            ]);

            $ok = !empty($result['success']);
            $messageId = $message->getId();
            $snapshot = null !== $messageId ? $this->planStore->loadCards($messageId) : [];

            if (!$ok) {
                $reason = is_string($result['error'] ?? null)
                    ? $result['error']
                    : 'The AI step could not complete. Nothing was sent or saved.';

                return $this->fail($task, $run, $reason, $messageId, $snapshot);
            }

            $this->rateLimits->recordUsage($user, 'MESSAGES', [
                'source' => 'SAVED_TASK',
                'chat_id' => $chat->getId(),
                'input_text' => $text,
            ]);

            $run->markCompleted($messageId, [] !== $snapshot ? ['cards' => $snapshot] : null);
            $task->recordSuccess();
            $this->runs->save($run);
            $this->tasks->save($task);
            if (null !== $task->getId()) {
                $this->runs->prune($task->getId(), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            }

            return ['run' => $run, 'task' => $task];
        } catch (\Throwable $e) {
            $this->logger->warning('SavedTaskRunner: run failed', [
                'task_id' => $task->getId(),
                'owner_id' => $ownerId,
                'error' => $e->getMessage(),
            ]);

            return $this->fail($task, $run, 'The AI step could not complete. Nothing was sent or saved.');
        }
    }

    /**
     * @param list<array<string, mixed>>|array<string, mixed>|null $snapshot
     *
     * @return array{run: SavedTaskRun, task: SavedTask}
     */
    private function fail(SavedTask $task, SavedTaskRun $run, string $error, ?int $messageId = null, ?array $snapshot = null): array
    {
        $run->markFailed($error, $messageId, null !== $snapshot ? ['cards' => $snapshot] : null);
        $task->recordFailure();
        $this->runs->save($run);
        $this->tasks->save($task);

        if ($task->isAutoPaused()) {
            $this->notifyPaused($task, $error);
        }

        return ['run' => $run, 'task' => $task];
    }

    private function ensureChat(SavedTask $task, User $user): Chat
    {
        if (null !== $task->getChatId()) {
            $existing = $this->chats->find($task->getChatId());
            if ($existing instanceof Chat && $existing->getUserId() === $user->getId()) {
                return $existing;
            }
        }

        $chat = new Chat();
        $chat->setUserId((int) $user->getId());
        $chat->setTitle($task->getName());
        $chat->setSource('web');
        $this->em->persist($chat);
        $this->em->flush();
        $chatId = $chat->getId();
        if (null === $chatId) {
            throw new \RuntimeException('Chat persist did not assign an id');
        }
        $task->setChatId($chatId);
        $this->tasks->save($task);

        return $chat;
    }

    private function notifyPaused(SavedTask $task, string $reason): void
    {
        $user = $this->users->find($task->getOwnerId());
        if (!$user instanceof User) {
            return;
        }
        $address = trim($user->getMail());
        if ('' === $address || str_ends_with(strtolower($address), '@synaplan.local')) {
            return;
        }

        try {
            $this->mail->sendTaskResultEmail(
                $address,
                'Saved Task paused: '.$task->getName(),
                "The Saved Task “{$task->getName()}” was paused automatically after repeated failures.\n\nReason: {$reason}\n\nOpen AI Instructions to resume it.",
            );
        } catch (\Throwable $e) {
            $this->logger->warning('SavedTaskRunner: pause notice failed', [
                'task_id' => $task->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
