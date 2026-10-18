<?php

namespace App\Service\WhatsApp\Turn;

use App\DTO\WhatsApp\IncomingMessageDto;
use App\Entity\User;

/**
 * One inbound WhatsApp message the intake admitted: the webhook message, the
 * sender's account, the account the turn's files and models run under, and
 * how the sender is answered.
 */
final readonly class InboundWhatsApp
{
    /**
     * @param int      $effectiveUserId the shared WhatsApp account for an anonymous sender, else the sender's
     * @param bool     $answersBySpeech a voice note or a video is answered with a spoken reply
     * @param int|null $agentId         the assistant bound to the sender's account
     */
    public function __construct(
        public IncomingMessageDto $dto,
        public User $user,
        public bool $isAnonymous,
        public int $effectiveUserId,
        public bool $answersBySpeech,
        public ?int $agentId,
    ) {
    }
}
