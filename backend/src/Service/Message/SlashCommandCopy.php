<?php

declare(strict_types=1);

namespace App\Service\Message;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Localized one-liners for bare slash commands and empty `/docs` results.
 */
final readonly class SlashCommandCopy
{
    private const DOMAIN = 'slash';

    /** @var list<string> */
    private const EXAMPLE_COMMANDS = ['pic', 'vid', 'tts', 'search', 'docs'];

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function needsArgument(string $command, string $locale): string
    {
        $command = strtolower($command);
        $locale = $this->normalizeLocale($locale);
        $example = $this->exampleFor($command, $locale);

        return $this->translator->trans(
            'needs_argument',
            [
                '%command%' => $command,
                '%example%' => $example,
            ],
            self::DOMAIN,
            $locale,
        );
    }

    public function docsNotFound(string $locale): string
    {
        return $this->translator->trans(
            'docs_not_found',
            [],
            self::DOMAIN,
            $this->normalizeLocale($locale),
        );
    }

    private function exampleFor(string $command, string $locale): string
    {
        if (!in_array($command, self::EXAMPLE_COMMANDS, true)) {
            return '…';
        }

        return $this->translator->trans(
            'example_'.$command,
            [],
            self::DOMAIN,
            $locale,
        );
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = strtolower(substr($locale, 0, 2));

        return in_array($locale, ['de', 'en', 'es', 'fr', 'tr'], true) ? $locale : 'en';
    }
}
