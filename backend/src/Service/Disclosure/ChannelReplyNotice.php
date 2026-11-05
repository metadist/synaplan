<?php

declare(strict_types=1);

namespace App\Service\Disclosure;

use App\Entity\ChannelNotice;
use App\Entity\InboundEmailHandler;
use App\Repository\ChannelNoticeRepository;
use App\Repository\InboundEmailHandlerRepository;
use App\Repository\OutboundSilenceQuery;

/**
 * Puts the AI sentence on a reply. Mail carries it every time.
 * WhatsApp and Telegram carry it on the first reply and again after a day of silence.
 */
final readonly class ChannelReplyNotice
{
    public function __construct(
        private AiDisclosure $disclosure,
        private ChannelNoticeRepository $notices,
        private OutboundSilenceQuery $silence,
        private InboundEmailHandlerRepository $handlers,
    ) {
    }

    public function mailSentence(int $ownerId, string $locale, string $senderName, ?string $mailbox = null): string
    {
        return $this->disclosure->sentence(
            AiDisclosure::MAIL,
            $locale,
            $senderName,
            $this->mailCustom($ownerId, $mailbox),
        );
    }

    public function prependIfDue(
        string $channel,
        int $ownerId,
        int $chatId,
        ?string $peerKey,
        string $locale,
        string $name,
        string $text,
        ?int $now = null,
    ): string {
        $now ??= time();
        if (!$this->silence->isDue($chatId, $peerKey, AiDisclosure::SILENCE_SECONDS, $now)) {
            return $text;
        }

        $sentence = $this->disclosure->sentence($channel, $locale, $name, $this->custom($ownerId, $channel));
        $text = trim($text);

        return '' === $text ? $sentence : $sentence."\n\n".$text;
    }

    private function custom(int $ownerId, string $channel): ?string
    {
        $row = $this->notices->findOne($ownerId, $channel, 0);

        return $row instanceof ChannelNotice ? $row->getText() : null;
    }

    private function mailCustom(int $ownerId, ?string $mailbox): ?string
    {
        $rows = $this->notices->forOwner($ownerId, AiDisclosure::MAIL);
        if ([] === $rows) {
            return null;
        }

        $mailbox = null === $mailbox ? '' : strtolower(trim($mailbox));
        if ('' !== $mailbox) {
            foreach ($this->handlers->findByUser($ownerId) as $handler) {
                if (!$handler instanceof InboundEmailHandler) {
                    continue;
                }
                if (strtolower(trim($handler->getUsername())) !== $mailbox) {
                    continue;
                }
                $matched = $this->notices->findOne($ownerId, AiDisclosure::MAIL, (int) $handler->getId());
                if ($matched instanceof ChannelNotice) {
                    return $matched->getText();
                }
            }
        }

        if (1 === count($rows)) {
            return $rows[0]->getText();
        }

        return null;
    }
}
