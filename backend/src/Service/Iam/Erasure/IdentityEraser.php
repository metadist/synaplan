<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\ExternalIdentity;
use App\Entity\GroupMember;
use App\Entity\TelegramBot;
use App\Repository\ExternalIdentityRepository;
use App\Repository\GroupMemberRepository;
use App\Service\Telegram\TelegramConnectionService;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Group memberships, linked sign-in identities and the user's Telegram bot;
 * the bot's webhook is dropped at Telegram after commit.
 */
#[AsTaggedItem(priority: 60)]
final readonly class IdentityEraser implements UserDataEraserInterface
{
    public function __construct(
        private GroupMemberRepository $groupMembers,
        private ExternalIdentityRepository $externalIdentities,
        private TelegramConnectionService $telegramConnections,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        $this->groupMembers->deleteByUserId($erasure->userId);
        $this->externalIdentities->deleteByUserId($erasure->userId);

        $telegramToken = $this->telegramConnections->removeForOwner($erasure->userId);
        if (null !== $telegramToken) {
            $erasure->afterCommit(fn () => $this->telegramConnections->dropWebhookForToken($telegramToken));
        }
    }

    public static function covers(): array
    {
        return [GroupMember::class, ExternalIdentity::class, TelegramBot::class];
    }
}
