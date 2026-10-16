<?php

declare(strict_types=1);

namespace App\Realtime\Notifier;

use App\Entity\Chat;
use App\Entity\Share;
use App\Repository\GroupMemberRepository;
use App\Repository\ShareRepository;
use App\Repository\UserRepository;
use App\Service\Iam\IamConfig;
use App\Service\Iam\ResourceKind\ConversationKind;

/**
 * Publishes one chat.activity to the owner and to everyone the conversation is
 * shared with right now. A revoked share is absent from the live list, so the
 * token's remaining lifetime never keeps a watcher subscribed (#2057).
 *
 * The payload never includes message text. An everyone-share is paged into
 * broadcasts of {@see self::EVERYONE_PAGE_SIZE} so nobody past a cutoff is
 * dropped and no single gateway call carries the whole user table.
 * An everyone-share that grants nothing right now ({@see IamConfig::everyoneShareReaches()})
 * triggers no fan-out, so an inert grant never pages the whole user table (#2096).
 */
final readonly class ChatAudienceNotifier
{
    public const EVERYONE_PAGE_SIZE = 500;

    public function __construct(
        private ChatActivityNotifier $activity,
        private ShareRepository $shares,
        private GroupMemberRepository $members,
        private UserRepository $users,
        private IamConfig $iamConfig,
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
                } elseif (
                    Share::SUBJECT_EVERYONE === $share->getSubjectType()
                    && $this->iamConfig->everyoneShareReaches($share)
                ) {
                    $includeEveryone = true;
                }
            }
        }

        $recipients = [];
        foreach (array_unique($ids) as $id) {
            if ($id > 0) {
                $recipients[] = $id;
            }
        }

        if (!$includeEveryone) {
            $this->activity->publishToUsers($chat, $recipients, $direction);

            return;
        }

        $afterId = 0;
        $sentExplicit = false;
        while (true) {
            $page = $this->users->findIdsAfter($afterId, self::EVERYONE_PAGE_SIZE);
            $batch = $sentExplicit ? $page : array_merge($recipients, $page);
            $sentExplicit = true;
            if ([] !== $batch) {
                $this->activity->publishToUsers($chat, $batch, $direction);
            }
            if (count($page) < self::EVERYONE_PAGE_SIZE) {
                break;
            }
            $afterId = $page[array_key_last($page)];
        }
    }
}
