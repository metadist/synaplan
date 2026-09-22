<?php

declare(strict_types=1);

namespace App\Realtime\Notifier;

use App\Entity\Chat;
use App\Entity\Share;
use App\Repository\GroupMemberRepository;
use App\Repository\ShareRepository;
use App\Service\Iam\ResourceKind\ConversationKind;

/**
 * Publishes one chat.activity to the owner and to everyone the conversation is
 * shared with right now. A revoked share is absent from the live list, so the
 * token's remaining lifetime never keeps a watcher subscribed (#2057).
 */
final readonly class ChatAudienceNotifier
{
    public function __construct(
        private ChatActivityNotifier $activity,
        private ShareRepository $shares,
        private GroupMemberRepository $members,
    ) {
    }

    public function publish(Chat $chat, string $direction, ?string $preview): void
    {
        $ids = [(int) $chat->getUserId()];
        $chatId = $chat->getId();
        if (null !== $chatId) {
            foreach ($this->shares->findForResource(ConversationKind::KEY, (string) $chatId) as $share) {
                if (Share::SUBJECT_USER === $share->getSubjectType()) {
                    $ids[] = $share->getSubjectId();
                } elseif (Share::SUBJECT_GROUP === $share->getSubjectType()) {
                    foreach ($this->members->findByGroupId($share->getSubjectId()) as $member) {
                        $ids[] = $member->getUserId();
                    }
                }
            }
        }

        foreach (array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)) as $id) {
            $this->activity->publishActivity($chat, $id, $direction, $preview);
        }
    }
}
