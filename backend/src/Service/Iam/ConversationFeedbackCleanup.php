<?php

declare(strict_types=1);

namespace App\Service\Iam;

use App\Entity\Chat;
use App\Repository\GroupMemberRepository;
use App\Repository\MessageRepository;
use App\Repository\ShareRepository;
use App\Repository\UserMemoryRepository;
use App\Repository\UserRepository;
use App\Service\Iam\ResourceKind\ConversationKind;
use App\Service\UserMemoryService;
use Psr\Log\LoggerInterface;

/**
 * Withdraws feedback derived from a conversation when a share is revoked.
 *
 * A recipient's "Not correct" / positive entry paraphrases the owner's answer
 * and is indexed under the recipient's account, so it steers their later chats.
 * That content must not outlive the grant (#2068) — but it must also survive
 * when the recipient keeps access through another grant (direct + group, or
 * group + everyone), so every affected user is re-checked against the live
 * share rows before anything is deleted.
 *
 * The re-check reads ShareRepository directly instead of going through
 * AccessGate: kinds are resolved through the registry, which AccessGate itself
 * needs for owner lookup, so a Kind -> AccessGate edge would be a circular
 * dependency. The reference semantics is AccessGate::highestGranted; the only
 * deliberate difference is that admin-manage (share/unshare/delete without
 * read) does not retain derived content.
 */
final readonly class ConversationFeedbackCleanup
{
    public function __construct(
        private MessageRepository $messageRepository,
        private UserMemoryRepository $memoryRepository,
        private UserRepository $userRepository,
        private UserMemoryService $memoryService,
        private ShareRepository $shareRepository,
        private GroupMemberRepository $groupMemberRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Deletes derived feedback of users who lost all access to the chat.
     *
     * @return int number of feedback entries withdrawn
     */
    public function withdrawDerivedFromChat(Chat $chat): int
    {
        $chatId = (int) $chat->getId();
        $ownerId = $chat->getUserId();

        $messageIds = $this->messageRepository->findIdsByChatId($chatId);
        if ([] === $messageIds) {
            return 0;
        }

        $candidates = $this->memoryRepository->findFeedbackReferencingMessages($messageIds, $ownerId);
        if ([] === $candidates) {
            return 0;
        }

        $byUser = [];
        foreach ($candidates as $memory) {
            $byUser[$memory->getUserId()][] = $memory;
        }

        $withdrawn = 0;
        foreach ($byUser as $userId => $memories) {
            if ($userId === $ownerId || $this->stillHasAccess($userId, (string) $chatId)) {
                continue;
            }
            $user = $this->userRepository->find($userId);
            if (null === $user) {
                continue;
            }
            foreach ($memories as $memory) {
                try {
                    $this->memoryService->deleteMemory($memory->getId(), $user);
                    ++$withdrawn;
                } catch (\InvalidArgumentException $e) {
                    // Already gone (concurrent delete) — the desired end state.
                    continue;
                }
            }
        }

        if ($withdrawn > 0) {
            $this->logger->info('Withdrew derived feedback after share revoke', [
                'chat_id' => $chatId,
                'withdrawn' => $withdrawn,
            ]);
        }

        return $withdrawn;
    }

    private function stillHasAccess(int $userId, string $chatId): bool
    {
        $groupIds = [];
        foreach ($this->groupMemberRepository->findByUserId($userId) as $member) {
            $groupIds[] = $member->getGroupId();
        }

        $shares = $this->shareRepository->findForSubjects($userId, $groupIds, ConversationKind::KEY, $chatId);
        foreach ($shares as $share) {
            $level = Permission::tryFrom($share->getPermission());
            if (null !== $level && $level->implies(Permission::Read)) {
                return true;
            }
        }

        return false;
    }
}
