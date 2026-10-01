<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Index;

/**
 * Function words and search verbs of the five UI languages. InnoDB only
 * knows English stopwords, so "wo stelle ich den mailserver ein" would
 * otherwise match every German text containing "ich" or "den".
 */
final class StopWords
{
    private const WORDS = [
        // en
        'the', 'and', 'for', 'are', 'was', 'were', 'you', 'your', 'with', 'what', 'where', 'how', 'who', 'why',
        'can', 'not', 'this', 'that', 'from', 'have', 'has', 'all', 'any', 'into', 'about', 'when', 'which',
        'there', 'their', 'then', 'than', 'does', 'did', 'find', 'show', 'open', 'search', 'please',
        // de
        'der', 'die', 'das', 'den', 'dem', 'des', 'und', 'oder', 'ist', 'sind', 'war', 'wie', 'was', 'wer',
        'ich', 'mir', 'mich', 'mein', 'meine', 'meinen', 'dein', 'deine', 'ein', 'eine', 'einen', 'einem', 'einer',
        'mit', 'von', 'für', 'auf', 'aus', 'bei', 'nach', 'zum', 'zur', 'nicht', 'auch', 'noch', 'kann', 'wird',
        'werden', 'hat', 'habe', 'haben', 'stelle', 'finde', 'zeige', 'zeig', 'suche', 'bitte',
        // es
        'los', 'las', 'del', 'una', 'uno', 'unos', 'unas', 'que', 'qué', 'por', 'para', 'con', 'como', 'cómo',
        'donde', 'dónde', 'mis', 'tus', 'sus', 'esta', 'está', 'este', 'son', 'hay', 'buscar', 'muestra',
        // fr
        'les', 'une', 'est', 'sont', 'pour', 'par', 'avec', 'dans', 'sur', 'qui', 'quoi', 'comment',
        'mes', 'tes', 'ses', 'mon', 'ton', 'pas', 'trouver', 'chercher',
        // tr
        'bir', 'ile', 'için', 'gibi', 'nasıl', 'nerede', 'var', 'yok', 'ben', 'benim', 'sen', 'olan', 'bul', 'göster',
        // Switching verbs name the action, not the thing ("enable" would
        // match every *_ENABLED setting).
        'turn', 'enable', 'disable', 'switch', 'activate', 'deactivate', 'einschalten', 'ausschalten', 'aktivieren',
        'deaktivieren', 'anschalten', 'abschalten', 'activar', 'desactivar', 'activer', 'désactiver', 'aç', 'kapat',
        'etkinleştir', 'devre',
        // Short words (the full-text path drops them by length anyway).
        'on', 'off', 'in', 'to', 'of', 'my', 'an', 'is', 'it', 'wo', 'la', 'le', 'el', 'de', 'un', 'en', 'et', 'mi', 've', 'bu',
    ];

    public static function contains(string $word): bool
    {
        static $set = null;
        $set ??= array_fill_keys(self::WORDS, true);

        return isset($set[$word]);
    }
}
