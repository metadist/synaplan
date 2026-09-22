<?php

declare(strict_types=1);

namespace App\Realtime\Notifier;

use App\Entity\Chat;
use App\Entity\Share;
use App\Repository\GroupMemberRepository;
use App\Repository\ShareRepository;
use App\Repository\UserRepository;
use App\Service\Iam\ResourceKind\ConversationKind;
use Psr\Log\LoggerInterface;

/**
 * Publishes one chat.activity to the owner and to everyone the conversation is
 * shared with right now. A revoked share is absent from the live list, so the
 * token's remaining lifetime never keeps a watcher subscribed (#2057).
 *
 * The payload never includes message text. One gateway broadcast covers the
 * whole audience, including an everyone-share, up to {@see self::EVERYONE_FANOUT_LIMIT}.
 */
final readonly class ChatAudienceNotifier
{
    /**
     * Cap for an everyone-share. One broadcast request stays cheap at this
     * size; a larger instance logs and skips the overflow rather than
     * scanning without a bound.
     */
    public const EVERYONE_FANOUT_LIMIT = 1000;

    public function __construct(
        private ChatActivityNotifier $activity,
        private ShareRepository $shares,
        private GroupMemberRepository $members,
        private UserRepository $users,
        private LoggerInterface $logger,
    ) {
    }

    public function publish(Chat $chat, string $direction): void
    {
        $ids = [(int) $chat->getUserId()];
        $chatId = $chat->getId();
        $includeEveryone = false;
        if (null !== $chatId) {
            foreach ($this->shares->findForResource(ConversationKind::KEY, (string) $chatId) as $share) {
                if (Share::SUBJECT_USER === $share->getSubjectType()) {
                    $ids[] = $share->getSubjectId();
                } elseif (Share::SUBJECT_GROUP === $share->getSubjectType()) {
                    foreach ($this->members->findByGroupId($share->getSubjectId()) as $member) {
                        $ids[] = $member->getUserId();
                    }
                } elseif (Share::SUBJECT_EVERYONE === $share->getSubjectType()) {
                    $includeEveryone = true;
                }
            }
        }

        if ($includeEveryone) {
            $everyoneIds = $this->users->findIds(self::EVERYONE_FANOUT_LIMIT + 1);
            if (count($everyoneIds) > self::EVERYONE_FANOUT_LIMIT) {
                $this->logger->warning('Chat audience fan-out stopped at the everyone-share cap', [
                    'chat_id' => $chatId,
                    'cap' => self::EVERYONE_FANOUT_LIMIT,
                ]);
                $everyoneIds = array_slice($everyoneIds, 0, self::EVERYONE_FANOUT_LIMIT);
            }
            array_push($ids, ...$everyoneIds);
        }

        $recipients = [];
        foreach (array_unique($ids) as $id) {
            if ($id > 0) {
                $recipients[] = $id;
            }
        }

        $this->activity->publishToUsers($chat, $recipients, $direction);
    }
}
