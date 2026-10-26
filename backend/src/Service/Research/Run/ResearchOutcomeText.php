<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The outcome sentence in the person's language, for the chat message the
 * server writes. API clients translate `code` and `params` themselves.
 */
final readonly class ResearchOutcomeText
{
    private const DOMAIN = 'research';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function sentence(?ResearchOutcome $outcome, string $locale): string
    {
        if (null === $outcome) {
            return $this->translator->trans('research.outcome.unknown', [], self::DOMAIN, $locale);
        }
        $values = $outcome->placeholders();
        $key = 'research.outcome.'.$outcome->code;
        $sentence = $this->translator->trans($key, [
            '%statements%' => $values['statements'],
            '%supported%' => $values['supported'],
            '%open%' => $values['open'],
            '%points%' => $values['points'],
            '%covered%' => $values['covered'],
            '%role%' => $this->translator->trans('research.role.'.$values['role'], [], self::DOMAIN, $locale),
        ], self::DOMAIN, $locale);

        return $sentence === $key ? $outcome->text() : $sentence;
    }
}
