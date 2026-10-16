<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every sentence and button label the bot writes, in the person's language.
 */
final readonly class TelegramCopy
{
    private const DOMAIN = 'telegram';

    public function __construct(private TranslatorInterface $translator)
    {
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
