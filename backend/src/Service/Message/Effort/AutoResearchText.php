<?php

declare(strict_types=1);

namespace App\Service\Message\Effort;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The sentences the chat says when it starts a research on its own, or
 * when a limit kept it from doing so. Written by the server in the
 * person's language, like the research outcome texts, so every client
 * shows the same words and a reload renders them unchanged.
 */
final readonly class AutoResearchText
{
    private const DOMAIN = 'research';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    /** "I am looking through several sources for this — about 6 minutes. The question has several parts." */
    public function notice(int $minutes, string $why, string $locale): string
    {
        $first = $this->translator->trans('research.auto.notice', ['%minutes%' => $minutes], self::DOMAIN, $locale);
        $reasonKey = 'research.auto.why.'.$why;
        $reason = $this->translator->trans($reasonKey, [], self::DOMAIN, $locale);

        return $reason === $reasonKey ? $first : $first.' '.$reason;
    }

    /** The sentence an answer begins with when research was held back. */
    public function limit(string $reason, string $locale): string
    {
        return $this->translator->trans('research.limit.'.$reason, [], self::DOMAIN, $locale);
    }
}
