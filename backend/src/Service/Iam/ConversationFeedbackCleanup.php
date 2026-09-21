<?php

declare(strict_types=1);

namespace App\Service\Iam;

use App\Entity\Chat;
use App\Entity\UserMemory;
use App\Repository\GroupMemberRepository;
use App\Repository\MessageRepository;
use App\Repository\ShareRepository;
use App\Repository\UserMemoryRepository;
use App\Repository\UserRepository;
use App\Service\Iam\ResourceKind\ConversationKind;
use App\Service\UserMemoryService;
use Psr\Log\LoggerInterface;

/**
 * Withdraws feedback derived from a conversation when access to it ends.
 *
 * A recipient's "Not correct" / positive entry paraphrases the owner's answer
 * and is indexed under the recipient's account, so it steers their later chats.
 * That content must not outlive the grant (#2068) — but on revoke it must also
 * survive when the recipient keeps access through another grant (direct +
 * group, or group + everyone), so every affected user is re-checked against
 * the live share rows before anything is deleted.
 *
 * The re-check reads ShareRepository directly instead of going through
 * AccessGate: kinds are resolved through the registry, which AccessGate itself
 * needs for owner lookup, so a Kind -> AccessGate edge would be a circular
 * dependency. The reference semantics is AccessGate::highestGranted — owner
 * first, then the sharing flag, then the share rows. The only deliberate
 * difference is that admin-manage (share/unshare/delete without read) does
 * not retain derived content.
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
        private IamConfig $iamConfig,
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
            $withdrawn += $this->deleteMemories($memories);
        }

        if ($withdrawn > 0) {
            $this->logger->info('Withdrew derived feedback after share revoke', [
                'chat_id' => $chatId,
                'withdrawn' => $withdrawn,
            ]);
        }

        return $withdrawn;
    }

    /**
     * Deletes all derived feedback for messages that are being destroyed with
     * their conversation. Unlike the revoke path there is no access to
     * re-check — the shares are deleted together with the chat — so every
     * non-owner entry referencing the messages is withdrawn.
     *
     * MUST run before the messages are purged; afterwards there is nothing
     * left to resolve the entries from.
     *
     * @param list<int> $messageIds
     *
     * @return int number of feedback entries withdrawn
     */
    public function withdrawAllDerivedFromMessages(array $messageIds, int $ownerId): int
    {
        if ([] === $messageIds) {
            return 0;
        }

        $candidates = $this->memoryRepository->findFeedbackReferencingMessages($messageIds, $ownerId);
        if ([] === $candidates) {
            return 0;
        }

        $withdrawn = $this->deleteMemories($candidates);
        if ($withdrawn > 0) {
            $this->logger->info('Withdrew derived feedback with a deleted conversation', [
                'withdrawn' => $withdrawn,
            ]);
        }

        return $withdrawn;
    }

    /**
     * @param list<UserMemory> $memories
     */
    private function deleteMemories(array $memories): int
    {
        if ([] === $memories) {
            return 0;
        }
        $withdrawn = 0;
        $users = [];
        foreach ($memories as $memory) {
            $userId = $memory->getUserId();
            if (!array_key_exists($userId, $users)) {
                $users[$userId] = $this->userRepository->find($userId);
            }
            $user = $users[$userId];
            if (null === $user) {
                continue;
            }
            try {
                $this->memoryService->deleteMemory($memory->getId(), $user);
                ++$withdrawn;
            } catch (\InvalidArgumentException $e) {
                // Already gone (concurrent delete) — the desired end state.
                continue;
            }
        }

        return $withdrawn;
    }

    private function stillHasAccess(int $userId, string $chatId): bool
    {
        if (!$this->iamConfig->isSharingEnabled($userId)) {
            return false;
        }

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
