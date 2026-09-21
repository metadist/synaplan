<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Service\UrlContentService;

/**
 * Refuses file generation when the message names a source URL no fetch
 * could read — so a generator produces an honest "could not read" message
 * instead of inventing rows (#2050).
 *
 * Used by every path that turns a model reply into a stored file: the
 * document_generation DAG runner, the legacy ChatHandler envelope branch
 * (single-node plans bypass the runner via
 * TaskPlanExecutor::shouldUseLegacyRouter), and the streaming
 * StreamController envelope branch.
 */
final readonly class UnreadSourceGuard
{
    /**
     * Asked instead of writing the file when no fetch could read a named
     * source. Text-only by design: reply nodes only see `.text`, so a
     * failure here would hide the cause behind a generic fallback while
     * the file stayed missing.
     */
    private const UNREAD_SOURCE_TEXT = [
        'en' => 'I could not read %s, so no file was created. Check that the link opens in a browser, then ask again.',
        'de' => 'Ich konnte %s nicht lesen, daher wurde keine Datei erstellt. Prüfe, ob der Link im Browser funktioniert, und frag dann erneut.',
        'es' => 'No pude leer %s, así que no se creó ningún archivo. Comprueba que el enlace funcione en un navegador y vuelve a preguntar.',
        'fr' => 'Je n\'ai pas pu lire %s, donc aucun fichier n\'a été créé. Vérifiez que le lien s\'ouvre dans un navigateur, puis redemandez.',
        'tr' => '%s okunamadı, bu yüzden dosya oluşturulmadı. Bağlantının tarayıcıda açıldığını kontrol edin, sonra tekrar sorun.',
    ];

    public function __construct(
        private UrlContentService $urlContent,
    ) {
    }

    /**
     * Refusal text when generation must not proceed, null otherwise: no URL
     * named, at least one page read, the URL is incidental (no load/use
     * verb), or no read ran at all (URL reading off — not this guard's call).
     */
    public function refusalFor(string $text, mixed $pagesRead, mixed $language): ?string
    {
        if (!is_int($pagesRead) || $pagesRead > 0) {
            return null;
        }
        $urls = $this->urlContent->extractUrls($text);
        if ([] === $urls) {
            return null;
        }
        if (!self::textDemandsSourceRead($text)) {
            return null;
        }

        $url = mb_strlen($urls[0]) > 80 ? mb_substr($urls[0], 0, 80).'…' : $urls[0];
        $locale = is_string($language) && isset(self::UNREAD_SOURCE_TEXT[$language]) ? $language : 'en';

        return sprintf(self::UNREAD_SOURCE_TEXT[$locale], $url);
    }

    private static function textDemandsSourceRead(string $text): bool
    {
        return 1 === preg_match('/\b(lad\w*|load\w*|lies|lese\w*|read\w*|fetch\w*|hol\w*|nutz\w*|us(e|ing)|verwend\w*|zusammenfass\w*|summariz\w*|summaris\w*)\b/i', $text)
            || 1 === preg_match('/\b(aus|von|from)\s+https?:\/\//i', $text)
            || 1 === preg_match('/basierend auf|based on/i', $text);
    }
}
