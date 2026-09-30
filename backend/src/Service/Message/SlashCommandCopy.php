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

    /** @var array<string, string> */
    private const EXAMPLES = [
        'pic' => 'a dog on the beach',
        'vid' => 'a drone shot of mountains',
        'tts' => 'hello, how are you',
        'search' => 'weather in Berlin today',
        'docs' => 'invoice March',
    ];

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function needsArgument(string $command, string $locale): string
    {
        $command = strtolower($command);
        $example = self::EXAMPLES[$command] ?? '…';

        return $this->translator->trans(
            'needs_argument',
            [
                '%command%' => $command,
                '%example%' => $example,
            ],
            self::DOMAIN,
            $this->normalizeLocale($locale),
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

    private function normalizeLocale(string $locale): string
    {
        $locale = strtolower(substr($locale, 0, 2));

        return in_array($locale, ['de', 'en', 'es', 'fr', 'tr'], true) ? $locale : 'en';
    }
}
