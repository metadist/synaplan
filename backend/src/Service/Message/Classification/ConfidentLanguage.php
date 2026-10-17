<?php

namespace App\Service\Message\Classification;

use App\Entity\Message;

/**
 * The language of a message, but only when it is certain: a distinctive word
 * in the text, else a language already pinned on the message. A layer that
 * skips the AI sorter must not guess (a German "wer bist du?" once got an
 * English answer that way), so null means: ask the sorter.
 */
final class ConfidentLanguage
{
    /**
     * Resolve a confident 2-letter language code for a deterministic
     * short-circuit (fast-path heuristic or embedding-router match) that
     * skips the AI sorter entirely.
     *
     * Tries, in order:
     *   1. {@see detect()} — a local text heuristic.
     *   2. A language already pinned on the message (frontend UI locale or a
     *      previously detected turn; 'NN' is the entity default = unknown).
     *
     * Returns null when neither is available. Callers MUST treat null as "do
     * not shortcut, fall through to the AI sorter" rather than defaulting to
     * 'en' — see {@see detect()}'s docblock for the
     * incident this guards against (a German "wer bist du?" answered in
     * English because an undetectable language silently defaulted).
     */
    public static function of(Message $message, string $text): ?string
    {
        $confidentLanguage = self::detect($text);

        if (null === $confidentLanguage) {
            $existingLanguage = strtolower(trim($message->getLanguage()));
            if ('nn' !== $existingLanguage && 1 === preg_match('/^[a-z]{2}$/', $existingLanguage)) {
                $confidentLanguage = $existingLanguage;
            }
        }

        return $confidentLanguage;
    }

    /**
     * Cheap, confidence-aware language heuristic for the fast-path.
     *
     * The full AI sorter detects language too — we lose that signal when we
     * skip it, so reproduce a "good enough" guess locally from distinctive
     * stopwords / question words / greetings.
     *
     * Returns a 2-character ISO code when a distinctive anchor matched, or
     * `null` when there is no signal — the fast-path caller treats `null` as
     * "defer to the AI sorter" rather than guessing the wrong language.
     *
     * IMPORTANT: never returns `'auto'`. The `'auto'` sentinel is used
     * elsewhere in the pipeline (fixed-prompt widget mode) for the
     * system-prompt directive only and is NOT persistable to
     * `BMESSAGES.BLANG` (varchar(2)); leaking it here would trigger
     * SQLSTATE[22001] "Data too long for column 'BLANG'" on the outgoing
     * message insert. Stick to ISO codes (or null).
     */
    private static function detect(string $text): ?string
    {
        // Normalize first: collapse every run of non-letters to a single
        // space, then pad with spaces. Without this, punctuation-hugging
        // tokens defeat the space-delimited anchors below — e.g.
        // "wer bist du?" lowercases to " wer bist du? " and the " du " /
        // "du?" mismatch meant the most common German phrase scored 0 and
        // fell back to English. Letters (incl. umlauts) survive; everything
        // else becomes a separator.
        $normalized = preg_replace('/[^\p{L}]+/u', ' ', mb_strtolower($text)) ?? '';
        $lower = ' '.trim($normalized).' ';

        $hits = [
            'de' => 0, 'en' => 0, 'fr' => 0, 'es' => 0, 'it' => 0,
        ];

        // German: stopwords, question words, pronouns, common verbs and
        // greetings. These short function words are what carry the signal in
        // a one-line chat ("wer bist du?", "wie geht es dir?", "danke").
        foreach ([
            ' ich ', ' der ', ' die ', ' das ', ' und ', ' nicht ', ' ist ', ' für ',
            ' können ', ' kannst ', ' möchte ', ' über ', ' wäre ', ' mit ', ' auch ',
            ' wer ', ' wie ', ' was ', ' warum ', ' wo ', ' wann ', ' welche ', ' welcher ',
            ' du ', ' bist ', ' bin ', ' sind ', ' hast ', ' habe ', ' mir ', ' mich ',
            ' dich ', ' dein ', ' deine ', ' machst ', ' heißt ', ' gibt ', ' soll ',
            ' mein ', ' eine ', ' einen ', ' hallo ', ' danke ', ' bitte ', ' guten ',
        ] as $w) {
            if (str_contains($lower, $w)) {
                $hits['de'] += 2;
            }
        }
        // English: stopwords, question words, pronouns, common verbs and
        // greetings.
        foreach ([
            ' the ', ' and ', ' you ', ' please ', ' what ', ' write ', ' dont ', ' im ', ' ill ',
            ' who ', ' how ', ' why ', ' where ', ' when ', ' which ',
            ' are ', ' is ', ' do ', ' does ', ' can ', ' your ', ' me ', ' my ', ' a ', ' an ',
            ' hello ', ' hi ', ' hey ', ' thanks ', ' thank ',
        ] as $w) {
            if (str_contains($lower, $w)) {
                $hits['en'] += 2;
            }
        }
        // French.
        foreach ([
            ' le ', ' la ', ' les ', ' un ', ' une ', ' est ', ' pour ', ' avec ', ' écrire ',
            ' qui ', ' quoi ', ' comment ', ' pourquoi ', ' es ', ' tu ', ' merci ', ' bonjour ',
        ] as $w) {
            if (str_contains($lower, $w)) {
                $hits['fr'] += 2;
            }
        }
        // Spanish.
        foreach ([
            ' el ', ' la ', ' los ', ' las ', ' por ', ' para ', ' escribir ',
            ' quién ', ' qué ', ' cómo ', ' por qué ', ' eres ', ' gracias ', ' hola ',
        ] as $w) {
            if (str_contains($lower, $w)) {
                $hits['es'] += 2;
            }
        }
        // Italian.
        foreach ([
            ' il ', ' lo ', ' la ', ' gli ', ' una ', ' per ', ' scrivere ',
            ' chi ', ' cosa ', ' come ', ' perché ', ' sei ', ' grazie ', ' ciao ',
        ] as $w) {
            if (str_contains($lower, $w)) {
                $hits['it'] += 2;
            }
        }

        arsort($hits);
        $best = array_key_first($hits);
        $bestScore = $hits[$best];

        // A single distinctive anchor (score 2) is already a strong signal
        // for a short chat one-liner, so we trust it; below that we have no
        // signal and return null so the caller can decide (persist 'en', or
        // defer to the AI sorter). The 2-char constraint matters elsewhere:
        // BMESSAGES.BLANG is varchar(2), so even though the 'auto' sentinel
        // works for in-memory routing, it'd break any downstream code that
        // persists $classification['language'] to BLANG (email webhook reply,
        // queue-mode chat persistence, ...).
        return $bestScore >= 2 ? $best : null;
    }
}
