<?php

declare(strict_types=1);

namespace App\Service\SavedTask;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\SavedTask;
use App\Repository\ChatRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Posts what the steps after a pause produced into the task's chat. The
 * person may have closed the window long ago, so the chat is where they find
 * it. Best effort: a failed post is logged and never undoes the run.
 */
final readonly class SavedTaskResumeReply
{
    public function __construct(
        private ChatRepository $chats,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $assembled     the resumed plan's result
     * @param string               $alreadyPosted text the chat already shows (the research report)
     */
    public function post(SavedTask $task, array $assembled, string $alreadyPosted): void
    {
        $content = is_string($assembled['content'] ?? null) ? trim($assembled['content']) : '';
        if ('' === $content || $content === trim($alreadyPosted)) {
            return;
        }
        $chatId = $task->getChatId();
        $chat = null !== $chatId ? $this->chats->find($chatId) : null;
        if (!$chat instanceof Chat || $chat->getUserId() !== $task->getOwnerId()) {
            return;
        }

        try {
            $now = time();
            $message = (new Message())
                ->setUserId($task->getOwnerId())
                ->setChat($chat)
                ->setTrackingId($now)
                ->setProviderIndex('WEB')
                ->setUnixTimestamp($now)
                ->setDateTime(date('YmdHis', $now))
                ->setMessageType('WEB')
                ->setFile(0)
                ->setTopic('CHAT')
                ->setText($content)
                ->setDirection('OUT')
                ->setStatus('complete');
            $this->em->persist($message);
            $chat->updateTimestamp();
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('Saved task result after research was not posted to the chat', [
                'task_id' => $task->getId(),
                'chat_id' => $chatId,
                'exception' => $e,
            ]);
        }
    }
}
