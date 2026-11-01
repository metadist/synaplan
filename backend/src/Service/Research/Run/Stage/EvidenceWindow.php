<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\Ledger\EvidenceLedger;
use App\Service\Research\Run\Ledger\Source;

/**
 * A card can answer the question only when its source sits inside the
 * brief's dates. A web page with no date, or a date wider than the window,
 * does not. A file the person added does. With no time frame, every card
 * is in window.
 */
final class EvidenceWindow
{
    /** @var array<string, int> */
    private const MONTHS = [
        'january' => 1, 'jan' => 1, 'januar' => 1, 'janvier' => 1, 'enero' => 1, 'ocak' => 1,
        'february' => 2, 'feb' => 2, 'februar' => 2, 'février' => 2, 'fevrier' => 2, 'febrero' => 2, 'şubat' => 2, 'subat' => 2,
        'march' => 3, 'mar' => 3, 'märz' => 3, 'marz' => 3, 'maerz' => 3, 'mars' => 3, 'marzo' => 3, 'mart' => 3,
        'april' => 4, 'apr' => 4, 'avril' => 4, 'abril' => 4, 'nisan' => 4,
        'may' => 5, 'mai' => 5, 'mayo' => 5, 'mayıs' => 5, 'mayis' => 5,
        'june' => 6, 'jun' => 6, 'juni' => 6, 'juin' => 6, 'junio' => 6, 'haziran' => 6,
        'july' => 7, 'jul' => 7, 'juli' => 7, 'juillet' => 7, 'julio' => 7, 'temmuz' => 7,
        'august' => 8, 'aug' => 8, 'augusti' => 8, 'août' => 8, 'aout' => 8, 'agosto' => 8, 'ağustos' => 8, 'agustos' => 8,
        'september' => 9, 'sep' => 9, 'sept' => 9, 'septembre' => 9, 'septiembre' => 9, 'eylül' => 9, 'eylul' => 9,
        'october' => 10, 'oct' => 10, 'oktober' => 10, 'octobre' => 10, 'octubre' => 10, 'ekim' => 10,
        'november' => 11, 'nov' => 11, 'novembre' => 11, 'noviembre' => 11, 'kasım' => 11, 'kasim' => 11,
        'december' => 12, 'dec' => 12, 'dezember' => 12, 'dez' => 12, 'décembre' => 12, 'decembre' => 12, 'diciembre' => 12, 'aralık' => 12, 'aralik' => 12,
    ];

    /**
     * Grouped like the writer sees them. Quote-only keeps a card only when
     * a quote was found in its passage.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function cards(EvidenceLedger $ledger, bool $quotesOnly): array
    {
        $frame = $ledger->brief()?->timeFrame;
        $grouped = [];
        foreach ($ledger->evidenceForWriter() as $pointId => $cards) {
            foreach ($cards as $card) {
                if (!self::usable($ledger, $card, $frame, $quotesOnly)) {
                    continue;
                }
                $grouped[$pointId][] = $card;
            }
        }

        return $grouped;
    }

    /**
     * Evidence ids inside the window, or null when the brief sets no dates.
     *
     * @return list<string>|null
     */
    public static function freshIds(EvidenceLedger $ledger): ?array
    {
        $frame = $ledger->brief()?->timeFrame;
        if (!self::constrained($frame)) {
            return null;
        }
        $ids = [];
        foreach ($ledger->evidenceForWriter() as $cards) {
            foreach ($cards as $card) {
                if (self::usable($ledger, $card, $frame, false)) {
                    $ids[] = strtolower((string) $card['id']);
                }
            }
        }

        return $ids;
    }

    /**
     * The published date's whole span sits inside the window. A year or a
     * month is inside only when that whole period is.
     *
     * @param array{from: ?string, to: ?string}|null $frame
     */
    public static function covers(?string $published, ?array $frame): bool
    {
        if (null === $frame) {
            return true;
        }
        $from = self::day($frame['from'] ?? null);
        $to = self::day($frame['to'] ?? null);
        if (null === $from && null === $to) {
            return true;
        }
        $span = self::span($published);
        if (null === $span) {
            return false;
        }
        if (null !== $from && $span[0] < $from) {
            return false;
        }

        return null === $to || $span[1] <= $to;
    }

    /**
     * @param array<string, mixed>                   $card
     * @param array{from: ?string, to: ?string}|null $frame
     */
    private static function usable(EvidenceLedger $ledger, array $card, ?array $frame, bool $quotesOnly): bool
    {
        if ($quotesOnly && [] === ($card['quotes'] ?? [])) {
            return false;
        }
        $sourceId = is_string($card['source'] ?? null) ? $card['source'] : '';
        $source = $ledger->source($sourceId);
        if (null !== $source && Source::WEB !== $source->kind) {
            return true;
        }

        return self::covers($source?->details->published, $frame);
    }

    /**
     * @param array{from: ?string, to: ?string}|null $frame
     */
    private static function constrained(?array $frame): bool
    {
        if (null === $frame) {
            return false;
        }

        return null !== self::day($frame['from'] ?? null) || null !== self::day($frame['to'] ?? null);
    }

    /**
     * @return array{0: string, 1: string}|null inclusive ISO dates
     */
    private static function span(?string $published): ?array
    {
        if (!is_string($published) || '' === trim($published)) {
            return null;
        }
        $text = mb_strtolower(trim($published));
        if (1 === preg_match('/(\d{4})-(\d{2})-(\d{2})/', $text, $match) && checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            $day = sprintf('%04d-%02d-%02d', (int) $match[1], (int) $match[2], (int) $match[3]);

            return [$day, $day];
        }
        $named = self::named($text);
        if (null !== $named) {
            return $named;
        }
        if (1 === preg_match('/(\d{4})-(\d{2})(?!-\d)/', $text, $match) && (int) $match[2] >= 1 && (int) $match[2] <= 12) {
            return self::monthSpan((int) $match[1], (int) $match[2]);
        }
        if (1 === preg_match('/(?<!\d)(\d{4})(?!\d)/', $text, $match)) {
            $year = (int) $match[1];
            if ($year >= 1900 && $year <= 2100) {
                return [$year.'-01-01', $year.'-12-31'];
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function named(string $text): ?array
    {
        if (1 === preg_match('/(\d{1,2})(?:st|nd|rd|th)?\s+([a-zà-ÿşğıöüç]+)\s+(\d{4})/u', $text, $match)) {
            return self::namedDay((int) $match[3], $match[2], (int) $match[1]);
        }
        if (1 === preg_match('/([a-zà-ÿşğıöüç]+)\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})/u', $text, $match)) {
            return self::namedDay((int) $match[3], $match[1], (int) $match[2]);
        }
        if (1 === preg_match('/([a-zà-ÿşğıöüç]+)\s+(\d{4})/u', $text, $match)) {
            $month = self::month($match[1]);
            if (null !== $month) {
                return self::monthSpan((int) $match[2], $month);
            }
        }
        if (1 === preg_match('/(\d{4})\s+([a-zà-ÿşğıöüç]+)/u', $text, $match)) {
            $month = self::month($match[2]);
            if (null !== $month) {
                return self::monthSpan((int) $match[1], $month);
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function namedDay(int $year, string $monthName, int $day): ?array
    {
        $month = self::month($monthName);
        if (null === $month || !checkdate($month, $day, $year)) {
            return null;
        }
        $iso = sprintf('%04d-%02d-%02d', $year, $month, $day);

        return [$iso, $iso];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function monthSpan(int $year, int $month): array
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = (new \DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');

        return [$start, $end];
    }

    private static function month(string $word): ?int
    {
        $word = str_replace('.', '', mb_strtolower($word));

        return self::MONTHS[$word] ?? null;
    }

    private static function day(?string $value): ?string
    {
        if (!is_string($value) || 1 !== preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $match)) {
            return null;
        }

        return $match[1];
    }
}
