<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Intent;

/**
 * Extracts the useful fields from raw, copy-pasted bank-statement text so a
 * payment can be matched against an open invoice. Best-effort and deterministic
 * — it never guesses silently; missing fields simply come back as null.
 */
final class BankStatementParser
{
    /**
     * @return array{amount: float|null, currency: string|null, date: string|null, invoice_number: string|null, reference: string}
     */
    public function parse(string $text): array
    {
        return [
            'amount' => $this->extractAmount($text),
            'currency' => $this->extractCurrency($text),
            'date' => $this->extractDate($text),
            'invoice_number' => $this->extractInvoiceNumber($text),
            'reference' => trim($text),
        ];
    }

    private function extractAmount(string $text): ?float
    {
        // Matches 1.190,00 / 1,190.00 / 1190.00 / 1190,00 / 99.99 optionally with a currency symbol.
        $count = preg_match_all('/(?<![\w.,])(\d{1,3}(?:[.,]\d{3})*(?:[.,]\d{2})|\d+[.,]\d{2})(?![\w])/u', $text, $matches);
        if (0 === $count || false === $count) {
            return null;
        }

        $best = null;
        foreach ($matches[1] as $raw) {
            $value = $this->normalizeAmount($raw);
            if (null !== $value && (null === $best || $value > $best)) {
                $best = $value;
            }
        }

        return $best;
    }

    private function normalizeAmount(string $raw): ?float
    {
        $lastComma = strrpos($raw, ',');
        $lastDot = strrpos($raw, '.');

        if (false !== $lastComma && (false === $lastDot || $lastComma > $lastDot)) {
            // Comma is the decimal separator (European): drop dots, comma -> dot.
            $normalized = str_replace('.', '', $raw);
            $normalized = str_replace(',', '.', $normalized);
        } else {
            // Dot is the decimal separator (or no separator): drop thousands commas.
            $normalized = str_replace(',', '', $raw);
        }

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function extractCurrency(string $text): ?string
    {
        if (str_contains($text, '€') || 1 === preg_match('/\bEUR\b/i', $text)) {
            return 'EUR';
        }
        if (str_contains($text, '$') || 1 === preg_match('/\bUSD\b/i', $text)) {
            return 'USD';
        }
        if (str_contains($text, '£') || 1 === preg_match('/\bGBP\b/i', $text)) {
            return 'GBP';
        }

        return null;
    }

    private function extractDate(string $text): ?string
    {
        if (1 === preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $text, $m)) {
            return $m[1].'-'.$m[2].'-'.$m[3];
        }
        // dd.mm.yyyy or dd/mm/yyyy
        if (1 === preg_match('#\b(\d{1,2})[./](\d{1,2})[./](\d{2,4})\b#', $text, $m)) {
            $year = 2 === strlen($m[3]) ? '20'.$m[3] : $m[3];

            return sprintf('%04d-%02d-%02d', (int) $year, (int) $m[2], (int) $m[1]);
        }

        return null;
    }

    private function extractInvoiceNumber(string $text): ?string
    {
        // Explicit "invoice/Rechnung <no>" references win.
        if (1 === preg_match('/(?:invoice|rechnung|rg\.?|inv)[\s#:.\-]*([A-Z0-9][A-Z0-9\-\/]{2,})/i', $text, $m)) {
            return trim($m[1], '-/');
        }
        // Otherwise a year-based invoice pattern like 2026-042, but never a
        // yyyy-mm-dd date (guarded by the trailing "-\d" negative lookahead).
        if (1 === preg_match('/\b(20\d{2}[-\/]\d{2,5})\b(?![-\/]\d)/', $text, $m)) {
            return $m[1];
        }

        return null;
    }
}
