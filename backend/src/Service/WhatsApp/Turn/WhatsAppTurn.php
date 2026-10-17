<?php

namespace App\Service\WhatsApp\Turn;

use App\DTO\WhatsApp\IncomingMessageDto;
use App\Entity\User;

/**
 * One inbound WhatsApp message as a turn of three stages: the intake admits
 * it or answers early, the conversation stores it with its media, and the
 * reply answers. A text is answered with text, a voice note with a spoken
 * reply, an image with a description (or the answer to its caption), and a
 * video with an answer to its audio track.
 */
final readonly class WhatsAppTurn
{
    public function __construct(
        private WhatsAppIntake $intake,
        private WhatsAppConversation $conversation,
        private WhatsAppReply $reply,
    ) {
    }

    /**
     * @return array<string, mixed> the webhook's answer for this message
     */
    public function handle(IncomingMessageDto $dto, User $user, bool $isAnonymous): array
    {
        try {
            $turn = $this->intake->admit($dto, $user, $isAnonymous);

            return $this->reply->answer($turn, $this->conversation->store($turn));
        } catch (WhatsAppMessageAnswered $answered) {
            return $answered->answer;
        }
    }
}
