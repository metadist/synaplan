<?php

declare(strict_types=1);

namespace App\Service\Prompt;

/**
 * Language of the latest user message — not the UI locale and not a sorter
 * default of `en`. Calendar confirmations and clarifying questions use this
 * so a German request in an English UI is answered in German.
 */
final class UserUtteranceLanguage
{
    /** @var list<string> */
    public const SUPPORTED = ['de', 'en', 'es', 'fr', 'tr'];

    /**
     * @var array<string, list<string>>
     */
    private const MARKERS = [
        'de' => ['ä', 'ö', 'ü', 'ß', ' und ', ' nicht ', ' für ', ' einen ', ' eine ', ' kalender', ' termin', ' morgen', ' uhr', ' bitte', ' stelle', ' meinen', ' hinzufüg'],
        'es' => ['ñ', 'á', 'ó', ' una ', ' para ', ' calendario', ' reunión', ' mañana', ' por favor'],
        'fr' => ['é', 'è', 'ê', 'ç', ' une ', ' pour ', ' calendrier', ' rendez-vous', ' demain', " s'il"],
        'tr' => ['ı', 'ş', 'ğ', 'ç', 'ö', 'ü', ' takvim', ' toplantı', ' yarın', ' lütfen'],
        'en' => [' the ', ' please ', ' tomorrow', ' calendar', ' meeting', ' add ', ' into '],
    ];

    public static function detect(string $text, string $fallback = 'en'): string
    {
        $fallback = self::normalize($fallback);
        $haystack = ' '.mb_strtolower($text).' ';
        $scores = [];
        foreach (self::MARKERS as $code => $markers) {
            $score = 0;
            foreach ($markers as $marker) {
                if (str_contains($haystack, $marker)) {
                    ++$score;
                }
            }
            $scores[$code] = $score;
        }
        arsort($scores);
        $best = (string) array_key_first($scores);
        if ($scores[$best] < 1) {
            return $fallback;
        }

        return $best;
    }

    public static function normalize(string $code): string
    {
        $code = strtolower(substr($code, 0, 2));

        return in_array($code, self::SUPPORTED, true) ? $code : 'en';
    }
}
