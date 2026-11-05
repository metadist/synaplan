<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\AiLiteracyAck;
use App\Entity\ChannelNotice;
use App\Repository\AiLiteracyAckRepository;
use App\Repository\ChannelNoticeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The AI-notice sentences and the literacy confirmation leave with the account.
 */
#[AsTaggedItem(priority: 85)]
final readonly class DisclosureEraser implements UserDataEraserInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private ChannelNoticeRepository $notices,
        private AiLiteracyAckRepository $acks,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        foreach ($this->notices->findBy(['ownerId' => $erasure->userId]) as $notice) {
            $this->em->remove($notice);
        }
        foreach ($this->acks->findBy(['userId' => $erasure->userId]) as $ack) {
            $this->em->remove($ack);
        }
    }

    public static function covers(): array
    {
        return [ChannelNotice::class, AiLiteracyAck::class];
    }
}
