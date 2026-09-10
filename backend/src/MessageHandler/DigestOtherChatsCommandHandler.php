<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\DigestOtherChatsCommand;
use App\Repository\UserRepository;
use App\Service\Digest\MessageDigestRunner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for {@see DigestOtherChatsCommand}.
 */
#[AsMessageHandler]
final readonly class DigestOtherChatsCommandHandler
{
    public function __construct(
        private MessageDigestRunner $digestRunner,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DigestOtherChatsCommand $command): void
    {
        $userId = $command->getUserId();
        $liveChatId = $command->getLiveChatId();

        $user = $this->userRepository->find($userId);
        if (null === $user || !$user->isMemoriesEnabled()) {
            return;
        }

        try {
            $result = $this->digestRunner->runForOtherChats($user, $liveChatId);
            $this->logger->info('DigestOtherChatsCommand: finished', [
                'user_id' => $userId,
                'live_chat_id' => $liveChatId,
                'batches' => $result['batches'],
                'created' => $result['created'],
                'scanned' => $result['scanned'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('DigestOtherChatsCommand: failed', [
                'user_id' => $userId,
                'live_chat_id' => $liveChatId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
