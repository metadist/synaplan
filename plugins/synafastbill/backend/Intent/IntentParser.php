<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Intent;

/**
 * Turns a chat prompt into a structured {@see ParsedCommand}.
 *
 * The parser is deterministic and rule-based (English + German keywords) so the
 * whole pipeline can be unit-tested and run offline. An optional
 * {@see AiCommandExtractorInterface} can be injected to improve extraction; when
 * it is absent or unsure, the rules decide. Session answers (confirm / cancel)
 * are always resolved by rules first, before any AI call.
 */
final class IntentParser
{
    private const AFFIRMATIONS = [
        'yes', 'y', 'yep', 'yeah', 'ok', 'okay', 'confirm', 'confirmed', 'correct', 'sure',
        'do it', 'go ahead', 'proceed', 'right',
        'ja', 'jup', 'passt', 'stimmt', 'genau', 'bestätige', 'bestätigen', 'mach', 'los',
    ];

    private const NEGATIONS = [
        'no', 'n', 'nope', 'cancel', 'stop', 'abort', 'never mind', 'nevermind', 'not', 'dont', 'do not',
        'nein', 'abbrechen', 'stopp', 'nicht',
    ];

    private const ORDINALS = [
        'first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5,
        'erste' => 1, 'zweite' => 2, 'dritte' => 3, 'vierte' => 4, 'fünfte' => 5,
    ];

    public function __construct(
        private readonly ?AiCommandExtractorInterface $aiExtractor = null,
    ) {
    }

    public function parse(string $input): ParsedCommand
    {
        $text = $this->stripCommandPrefix(trim($input));

        if ('' === $text) {
            return new ParsedCommand(CommandType::Help, [], $text);
        }

        $normalized = str_replace(["'", '’'], '', mb_strtolower($text));

        $selection = $this->detectSelection($normalized);
        if (null !== $selection) {
            return new ParsedCommand(CommandType::Confirm, ['selection' => $selection], $text);
        }
        if ($this->isNegation($normalized)) {
            return new ParsedCommand(CommandType::Cancel, [], $text);
        }
        if ($this->isAffirmation($normalized)) {
            return new ParsedCommand(CommandType::Confirm, [], $text);
        }

        if (null !== $this->aiExtractor) {
            $aiResult = $this->aiExtractor->extract($text);
            if (null !== $aiResult) {
                return $aiResult;
            }
        }

        return $this->parseWithRules($text, $normalized);
    }

    private function parseWithRules(string $text, string $normalized): ParsedCommand
    {
        if ($this->matchesHelp($normalized)) {
            return new ParsedCommand(CommandType::Help, [], $text);
        }

        if ($this->matchesOverdue($normalized)) {
            return new ParsedCommand(CommandType::ShowOverdue, [], $text);
        }

        if ($this->matchesPayment($normalized)) {
            return new ParsedCommand(CommandType::RegisterPayment, ['bank_text' => $this->paymentPayload($text)], $text);
        }

        if ($this->matchesCreateInvoice($normalized)) {
            return new ParsedCommand(
                CommandType::CreateDraftInvoice,
                ['customer_hint' => $this->extractCustomerHint($text)],
                $text,
            );
        }

        return new ParsedCommand(CommandType::Unknown, [], $text);
    }

    private function stripCommandPrefix(string $text): string
    {
        return trim((string) preg_replace('/^[\/@]fastbill\b[:,]?\s*/i', '', $text));
    }

    private function matchesHelp(string $normalized): bool
    {
        return in_array($normalized, ['help', 'hilfe', '?', 'commands', 'befehle'], true)
            || str_starts_with($normalized, 'help ')
            || str_starts_with($normalized, 'hilfe ');
    }

    private function matchesOverdue(string $normalized): bool
    {
        if (str_contains($normalized, 'overdue') || str_contains($normalized, 'überfällig') || str_contains($normalized, 'fällige')) {
            return true;
        }

        return (str_contains($normalized, 'open') || str_contains($normalized, 'offene') || str_contains($normalized, 'unpaid') || str_contains($normalized, 'unbezahlt'))
            && (str_contains($normalized, 'invoice') || str_contains($normalized, 'rechnung') || str_contains($normalized, 'payment') || str_contains($normalized, 'zahlung'));
    }

    private function matchesPayment(string $normalized): bool
    {
        $paymentWord = str_contains($normalized, 'payment') || str_contains($normalized, 'zahlung') || str_contains($normalized, 'bezahlt') || str_contains($normalized, 'paid');
        $registerWord = str_contains($normalized, 'register') || str_contains($normalized, 'record') || str_contains($normalized, 'erfass') || str_contains($normalized, 'buch') || str_contains($normalized, 'bank') || str_contains($normalized, 'mark');

        return $paymentWord && $registerWord;
    }

    private function matchesCreateInvoice(string $normalized): bool
    {
        $invoiceWord = str_contains($normalized, 'invoice') || str_contains($normalized, 'rechnung');
        $createWord = str_contains($normalized, 'create') || str_contains($normalized, 'new') || str_contains($normalized, 'draft')
            || str_contains($normalized, 'erstell') || str_contains($normalized, 'neue') || str_contains($normalized, 'entwurf')
            || str_contains($normalized, 'anleg') || str_contains($normalized, 'schreib');

        return $invoiceWord && $createWord;
    }

    private function extractCustomerHint(string $text): string
    {
        $patterns = [
            '/(?:for|für)\s+(?:customer|client|kunden?|firma)\s+(.+)$/iu',
            '/(?:customer|client|kunden?|firma)\s+(.+)$/iu',
            '/(?:for|für)\s+(.+)$/iu',
        ];
        foreach ($patterns as $pattern) {
            if (1 === preg_match($pattern, $text, $matches)) {
                return $this->cleanHint($matches[1]);
            }
        }

        return '';
    }

    private function cleanHint(string $hint): string
    {
        return trim((string) preg_replace('/[.?!,;:]+$/u', '', trim($hint)));
    }

    private function paymentPayload(string $text): string
    {
        $stripped = preg_replace('/^.*?(?:payment|zahlung)[:\-\s]*/iu', '', $text, 1);

        return trim((string) ($stripped ?? $text));
    }

    private function detectSelection(string $normalized): ?int
    {
        // The number has to be the whole reply. "500.00 EUR" is a bank amount,
        // not a choice of invoice 500, and must not confirm a pending write.
        if (1 === preg_match('/^(?:(?:take|use|the|number|option|nr\.?|#)\s+)*([1-9]\d*)(?:st|nd|rd|th)?[.!?]*$/u', $normalized, $m)) {
            return (int) $m[1];
        }
        foreach (self::ORDINALS as $word => $index) {
            if (1 === preg_match('/\b'.$word.'\b/u', $normalized) && (str_contains($normalized, 'take') || str_contains($normalized, 'use') || str_contains($normalized, 'the ') || str_contains($normalized, 'nimm') || str_contains($normalized, 'den ') || str_contains($normalized, 'die '))) {
                return $index;
            }
        }

        return null;
    }

    private function isAffirmation(string $normalized): bool
    {
        return $this->matchesShortPhrase($normalized, self::AFFIRMATIONS, true);
    }

    private function isNegation(string $normalized): bool
    {
        return $this->matchesShortPhrase($normalized, self::NEGATIONS, false);
    }

    /**
     * True when the input is a short answer whose first word is one of the given
     * tokens. A multi-word affirmation must be the whole answer, so "do not go
     * ahead" is not a yes. A multi-word refusal may also be a prefix. Punctuation
     * is ignored so "ja, passt" or "yes!" still match.
     *
     * @param array<int, string> $vocabulary
     */
    private function matchesShortPhrase(string $normalized, array $vocabulary, bool $phraseMustBeWhole): bool
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ([] === $tokens || count($tokens) > 6) {
            return false;
        }
        $first = $tokens[0];
        $joined = implode(' ', $tokens);

        foreach ($vocabulary as $entry) {
            if (str_contains($entry, ' ')) {
                if ($joined === $entry || (!$phraseMustBeWhole && str_starts_with($joined, $entry.' '))) {
                    return true;
                }
            } elseif ($first === $entry) {
                return true;
            }
        }

        return false;
    }
}
