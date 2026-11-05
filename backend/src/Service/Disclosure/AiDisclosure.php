<?php

declare(strict_types=1);

namespace App\Service\Disclosure;

use App\Exception\EmptyAiNoticeException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The one sentence that tells a person they are talking to an AI.
 * Mail, WhatsApp and Telegram share it. The widget keeps its own notice.
 */
final readonly class AiDisclosure
{
    public const MAIL = 'mail';
    public const WHATSAPP = 'whatsapp';
    public const TELEGRAM = 'telegram';

    public const CHANNELS = [self::MAIL, self::WHATSAPP, self::TELEGRAM];

    /** Silence after which a chat channel repeats the sentence. */
    public const SILENCE_SECONDS = 86400;

    private const LOCALES = ['en', 'de', 'es', 'fr', 'tr'];

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function sentence(string $channel, string $locale, string $name, ?string $custom = null): string
    {
        $custom = null === $custom ? '' : trim($custom);
        if ('' !== $custom) {
            return $custom;
        }

        $locale = $this->locale($locale);
        $safeName = '' !== trim($name) ? trim($name) : 'Synaplan';
        $key = self::MAIL === $channel ? 'disclosure.mail' : 'disclosure.chat';

        return $this->translator->trans($key, ['%sender%' => $safeName, '%name%' => $safeName], 'disclosure', $locale);
    }

    public function emptyMessage(string $locale): string
    {
        return $this->translator->trans('disclosure.empty', [], 'disclosure', $this->locale($locale));
    }

    public static function rejectEmpty(string $text): void
    {
        if ('' === trim($text)) {
            throw new EmptyAiNoticeException();
        }
    }

    public function locale(string $locale): string
    {
        $locale = strtolower(substr(trim($locale), 0, 2));

        return in_array($locale, self::LOCALES, true) ? $locale : 'en';
    }
}
