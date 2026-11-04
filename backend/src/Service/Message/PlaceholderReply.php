<?php

declare(strict_types=1);

namespace App\Service\Message;

/**
 * Detects reply text that is not a real answer: the degraded assembler
 * fallback, a generating/generated media token, or an empty body.
 *
 * Async media DAG turns persist one of these while the job is still running.
 * {@see \App\Service\Media\MediaJobMessageSync} may replace them when the job
 * settles — and must never replace model-written prose.
 */
final class PlaceholderReply
{
    /**
     * Static fallback shown when no node produced any text. Keyed by the
     * detected message language (the frontend-supported set); English is the
     * fallback for everything else.
     *
     * @var array<string, string>
     */
    public const FALLBACK_TEXT = [
        'en' => "I couldn't fully complete that request.",
        'de' => 'Ich konnte diese Anfrage leider nicht vollständig abschließen.',
        'es' => 'No pude completar esa solicitud por completo.',
        'fr' => "Je n'ai pas pu terminer cette demande entièrement.",
        'tr' => 'Bu isteği tamamen tamamlayamadım.',
    ];

    public static function fallbackFor(string $language): string
    {
        return self::FALLBACK_TEXT[$language] ?? self::FALLBACK_TEXT['en'];
    }

    public static function isPlaceholder(?string $text): bool
    {
        $trimmed = trim((string) $text);
        if ('' === $trimmed) {
            return true;
        }
        if (GeneratedMediaTextRenderer::isBareMediaToken($trimmed)) {
            return true;
        }

        return in_array($trimmed, self::FALLBACK_TEXT, true);
    }
}
