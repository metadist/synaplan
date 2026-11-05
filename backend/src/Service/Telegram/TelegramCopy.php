<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use App\Service\Disclosure\AiDisclosure;
use App\Service\Disclosure\ChannelReplyNotice;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every sentence and button label the bot writes, in the person's language.
 */
final readonly class TelegramCopy
{
    private const DOMAIN = 'telegram';

    public function __construct(
        private TranslatorInterface $translator,
        private ?ChannelReplyNotice $notices = null,
    ) {
    }

    /**
     * The AI sentence when this chat is due for one, or an empty string.
     * Call this before the outbound message is stored.
     */
    public function openingSentence(TelegramTurn $turn, int $chatId): string
    {
        if (null === $this->notices) {
            return '';
        }

        $name = trim($turn->bot->getBotUsername());
        $sample = $this->notices->prependIfDue(
            AiDisclosure::TELEGRAM,
            (int) $turn->owner->getId(),
            $chatId,
            $turn->tgChatId,
            $turn->locale,
            '' !== $name ? $name : 'assistant',
            '',
        );

        return $sample;
    }

    public function withOpening(TelegramTurn $turn, int $chatId, string $text): string
    {
        $opening = $this->openingSentence($turn, $chatId);
        if ('' === $opening) {
            return $text;
        }
        $text = trim($text);

        return '' === $text ? $opening : $opening."\n\n".$text;
    }

    /**
     * @param array<string, string|int> $params
     */
    public function say(string $locale, string $key, array $params = []): string
    {
        return $this->translator->trans('telegram.'.$key, $params, self::DOMAIN, $locale);
    }

    /**
     * @return callable(string): string
     */
    public function labels(string $locale): callable
    {
        return fn (string $key): string => $this->say($locale, $key);
    }

    /**
     * @return callable(string, array<string, string|int>): string
     */
    public function sayer(string $locale): callable
    {
        return fn (string $key, array $params = []): string => $this->say($locale, $key, $params);
    }
}
