<?php

declare(strict_types=1);

namespace App\Service\Disclosure;

use App\Entity\ChannelNotice;
use App\Entity\InboundEmailHandler;
use App\Entity\User;
use App\Repository\ChannelNoticeRepository;
use App\Repository\InboundEmailHandlerRepository;
use App\Repository\OutboundSilenceQuery;
use App\Repository\UserRepository;

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
        private UserRepository $users,
    ) {
    }

    public function mailSentence(int $ownerId, string $locale, string $senderName, ?string $mailbox = null): string
    {
        $handlers = $this->ownerHandlers($ownerId);
        $matched = $this->matchedHandler($handlers, $mailbox);
        $named = $matched ?? (1 === count($handlers) ? $handlers[0] : null);

        return $this->disclosure->sentence(
            AiDisclosure::MAIL,
            $locale,
            $this->sentenceName($ownerId, $named, $senderName),
            $this->mailCustom($ownerId, $matched),
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

    private function mailCustom(int $ownerId, ?InboundEmailHandler $matched): ?string
    {
        $rows = $this->notices->forOwner($ownerId, AiDisclosure::MAIL);
        if ([] === $rows) {
            return null;
        }

        if ($matched instanceof InboundEmailHandler) {
            $bindingId = $matched->getId();
            if (null !== $bindingId && $bindingId > 0) {
                $row = $this->notices->findOne($ownerId, AiDisclosure::MAIL, $bindingId);
                if ($row instanceof ChannelNotice) {
                    return $row->getText();
                }
            }

            return null;
        }

        if (1 === count($rows)) {
            return $rows[0]->getText();
        }

        return null;
    }

    /**
     * The address on the reply is the one the person wrote to. A handler
     * matches that address, its department mailboxes, or its sending address.
     * The IMAP login is only one of those identities.
     *
     * @param list<InboundEmailHandler> $handlers
     */
    private function matchedHandler(array $handlers, ?string $mailbox): ?InboundEmailHandler
    {
        $mailbox = strtolower(trim((string) $mailbox));
        if ('' === $mailbox || [] === $handlers) {
            return null;
        }

        foreach ($handlers as $handler) {
            if (in_array($mailbox, $this->mailboxAddresses($handler), true)) {
                return $handler;
            }
        }

        $targets = $this->addressCandidates($mailbox);
        foreach ($handlers as $handler) {
            foreach ($this->identities($handler) as $identity) {
                if (in_array($identity, $targets, true)) {
                    return $handler;
                }
            }
        }

        return null;
    }

    /**
     * @return list<InboundEmailHandler>
     */
    private function ownerHandlers(int $ownerId): array
    {
        $handlers = [];
        foreach ($this->handlers->findByUser($ownerId) as $handler) {
            if ($handler instanceof InboundEmailHandler) {
                $handlers[] = $handler;
            }
        }

        return $handlers;
    }

    /**
     * @return list<string>
     */
    private function mailboxAddresses(InboundEmailHandler $handler): array
    {
        return array_values(array_filter(
            $this->identities($handler),
            static fn (string $identity): bool => str_contains($identity, '@'),
        ));
    }

    /**
     * @return list<string>
     */
    private function identities(InboundEmailHandler $handler): array
    {
        $values = [$handler->getUsername(), $handler->getName()];
        $config = $handler->getConfig();
        $smtp = is_array($config) ? ($config['smtp'] ?? null) : null;
        $smtpUsername = is_array($smtp) ? ($smtp['username'] ?? null) : null;
        if (is_string($smtpUsername)) {
            $values[] = $smtpUsername;
        }
        foreach ($handler->getDepartments() as $department) {
            $email = is_array($department) ? ($department['email'] ?? null) : null;
            if (is_string($email)) {
                $values[] = $email;
            }
        }

        $identities = [];
        foreach ($values as $value) {
            $value = strtolower(trim($value));
            if ('' === $value) {
                continue;
            }
            $identities[] = $value;
            $local = $this->localPart($value);
            if ('' !== $local) {
                $identities[] = $local;
            }
        }

        return array_values(array_unique($identities));
    }

    /**
     * @return list<string>
     */
    private function addressCandidates(string $mailbox): array
    {
        if (1 === preg_match('/<([^>]+)>/', $mailbox, $wrapped)) {
            $mailbox = strtolower(trim($wrapped[1]));
        }
        $candidates = [$mailbox];
        $local = $this->localPart($mailbox);
        if ('' !== $local) {
            $candidates[] = $local;
        }
        if (1 === preg_match('/\+([a-z0-9._-]+)(?:@|$)/', $mailbox, $tag)) {
            $candidates[] = $tag[1];
        }

        return array_values(array_unique($candidates));
    }

    private function localPart(string $address): string
    {
        $at = strpos($address, '@');

        return false === $at ? '' : substr($address, 0, $at);
    }

    /**
     * Same name the mail-handler form shows in its default sentence:
     * the handler name, otherwise the owner's mailbox name.
     */
    private function sentenceName(int $ownerId, ?InboundEmailHandler $handler, string $fallback): string
    {
        if ($handler instanceof InboundEmailHandler) {
            $name = trim($handler->getName());
            if ('' !== $name) {
                return $name;
            }
        }

        $owner = $this->users->find($ownerId);
        if ($owner instanceof User) {
            $mail = $owner->getMail();
            $at = strpos($mail, '@');

            return false === $at ? 'Synaplan' : substr($mail, 0, $at);
        }

        $fallback = trim($fallback);

        return '' !== $fallback ? $fallback : 'Synaplan';
    }
}
