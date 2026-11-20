<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

/**
 * The note under a checked answer that names the statements its sources do
 * not back, each with a link to the pages it cites so the person can check
 * it. The answer itself stays as the person saw it while it streamed.
 */
final class ChatRunCheckNote
{
    /** The most statements a note lists; the rest are counted. */
    public const MAX_LISTED = 10;

    /** The longest statement quoted whole. */
    public const MAX_CHARS = 280;

    private const HEADS = [
        'en' => '**Not backed by the sources read.** These statements are not found like this in the pages the answer cites. Please check them before you rely on them:',
        'de' => '**Nicht durch die gelesenen Quellen belegt.** Diese Aussagen stehen so nicht in den Seiten, die die Antwort zitiert. Bitte prüfe sie, bevor du dich darauf verlässt:',
        'es' => '**No respaldado por las fuentes leídas.** Estas afirmaciones no aparecen así en las páginas que cita la respuesta. Compruébalas antes de fiarte de ellas:',
        'fr' => '**Non étayé par les sources lues.** Ces affirmations ne figurent pas ainsi dans les pages citées par la réponse. Vérifiez-les avant de vous y fier :',
        'tr' => '**Okunan kaynaklarla desteklenmiyor.** Bu ifadeler, yanıtın alıntıladığı sayfalarda bu şekilde geçmiyor. Bunlara güvenmeden önce kontrol edin:',
    ];

    private const MORE = [
        'en' => '… and %d more.',
        'de' => '… und %d weitere.',
        'es' => '… y %d más.',
        'fr' => '… et %d de plus.',
        'tr' => '… ve %d tane daha.',
    ];

    /** A link the note writes itself: http(s) only, nothing that ends its Markdown. */
    private const SAFE_URL = '/\Ahttps?:\/\/[^\s<>\[\]"]+\z/i';

    /**
     * @param list<array{statement: string, sources: list<string>}> $items as the answer wrote them, with the pages they cite
     */
    public static function text(array $items, string $locale): string
    {
        $statements = [];
        foreach ($items as $item) {
            $line = self::quote($item['statement']);
            if ('' !== $line && !isset($statements[$line])) {
                $statements[$line] = $line.self::links($item['sources']);
            }
        }
        $statements = array_values($statements);
        if ([] === $statements) {
            return '';
        }
        $lang = strtolower(substr($locale, 0, 2));
        $lines = ['---', '', self::HEADS[$lang] ?? self::HEADS['en'], ''];
        foreach (\array_slice($statements, 0, self::MAX_LISTED) as $statement) {
            $lines[] = '- '.$statement;
        }
        $more = \count($statements) - self::MAX_LISTED;
        if ($more > 0) {
            $lines[] = '';
            $lines[] = sprintf(self::MORE[$lang] ?? self::MORE['en'], $more);
        }

        return implode("\n", $lines);
    }

    /** One line, short, and nothing that would start a new Markdown block. */
    private static function quote(string $statement): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', $statement));
        $line = ltrim($line, "#>-*+| \t");
        if (mb_strlen($line) > self::MAX_CHARS) {
            $line = rtrim(mb_substr($line, 0, self::MAX_CHARS - 1)).'…';
        }

        return $line;
    }

    /**
     * @param list<string> $urls
     */
    private static function links(array $urls): string
    {
        $links = [];
        foreach (array_unique($urls) as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            if (1 !== preg_match(self::SAFE_URL, $url) || !\is_string($host) || '' === $host) {
                continue;
            }
            $links[] = '['.preg_replace('/^www\./i', '', $host).'](<'.$url.'>)';
        }

        return [] === $links ? '' : ' — '.implode(', ', $links);
    }
}
