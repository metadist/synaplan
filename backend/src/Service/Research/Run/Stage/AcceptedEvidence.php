<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\Ledger\CanonicalUrl;
use App\Service\Research\Run\Ledger\EvidenceCard;
use App\Service\Research\Run\Ledger\EvidenceLedger;
use App\Service\Research\Run\Ledger\PassageMap;
use App\Service\Research\Run\Ledger\PassageVerifier;
use App\Service\Research\Run\Ledger\QuoteMatch;

/**
 * Drops a finding whose passages do not exist, removes a quote that is not
 * in its passage, and keeps a lead only when it is a link id from the page.
 */
final readonly class AcceptedEvidence
{
    private const MAX_LEADS = 5;

    public function __construct(private PassageVerifier $verifier)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $links
     *
     * @return array{cards: list<EvidenceCard>, leads: list<array{url: string, point: string}>}
     */
    public function accept(string $sourceId, array $data, PassageMap $map, array $links, EvidenceLedger $ledger): array
    {
        $cards = [];
        foreach (is_array($data['findings'] ?? null) ? $data['findings'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $card = $this->card($sourceId, $row, $map, $ledger);
            if ($card instanceof EvidenceCard) {
                $cards[] = $card;
            }
        }

        return ['cards' => $cards, 'leads' => $this->leads($data['leads'] ?? null, $links, $ledger)];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function card(string $sourceId, array $row, PassageMap $map, EvidenceLedger $ledger): ?EvidenceCard
    {
        $fact = trim((string) ($row['fact'] ?? ''));
        $point = $this->pointId($ledger, (string) ($row['point'] ?? ''));
        if ('' === $fact || null === $ledger->source($sourceId) || '' === $point) {
            return null;
        }
        $passages = $this->passageIds($row['passages'] ?? null, $map);
        if ([] === $passages) {
            return null;
        }

        return new EvidenceCard(
            '',
            $sourceId,
            $point,
            $fact,
            $passages,
            $this->quote($row['quote'] ?? null, $passages, $map),
            (string) ($row['confidence'] ?? 'medium'),
        );
    }

    /**
     * @param list<string> $passages
     *
     * @return list<QuoteMatch>
     */
    private function quote(mixed $quote, array $passages, PassageMap $map): array
    {
        if (!is_array($quote) || !is_string($quote['text'] ?? null) || !is_string($quote['passage'] ?? null)) {
            return [];
        }
        $words = preg_split('/\s+/u', trim($quote['text'])) ?: [];
        if (count($words) > 25 || !in_array($quote['passage'], $passages, true)) {
            return [];
        }
        $match = $this->verifier->quoteInPassage($quote['text'], $quote['passage'], $map);

        return $match instanceof QuoteMatch ? [$match] : [];
    }

    /**
     * @param list<string> $links
     *
     * @return list<array{url: string, point: string}>
     */
    private function leads(mixed $rows, array $links, EvidenceLedger $ledger): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $kept = [];
        foreach ($rows as $row) {
            if (count($kept) >= self::MAX_LEADS || !is_array($row) || !is_string($row['link'] ?? null)) {
                continue;
            }
            if (1 !== preg_match('/^L(\d+)$/', $row['link'], $match)) {
                continue;
            }
            $url = $links[(int) $match[1] - 1] ?? null;
            $point = $this->pointId($ledger, (string) ($row['point'] ?? ''));
            if (!is_string($url) || '' === $point) {
                continue;
            }
            $key = CanonicalUrl::key($url);
            if (null === $key || isset($kept[$key])) {
                continue;
            }
            $kept[$key] = ['url' => $key, 'point' => $point];
        }

        return array_values($kept);
    }

    /**
     * gpt-oss and other strict models cite "1" or "p1" for passage §1 and
     * point p1. A citation that names a real passage or point is kept.
     */
    private function pointId(EvidenceLedger $ledger, string $point): string
    {
        $point = trim($point);
        if ($this->pointExists($ledger, $point)) {
            return $point;
        }
        if (1 === preg_match('/^p?\s*(\d+)$/i', $point, $match) && $this->pointExists($ledger, 'p'.$match[1])) {
            return 'p'.$match[1];
        }
        foreach ($ledger->points() as $known) {
            if ('' !== $point && 0 === strcasecmp($known->title, $point)) {
                return $known->id;
            }
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function passageIds(mixed $raw, PassageMap $map): array
    {
        $ids = [];
        foreach (is_array($raw) ? $raw : [] as $id) {
            if (!is_string($id)) {
                continue;
            }
            $resolved = $this->passageId($id, $map);
            if (null !== $resolved) {
                $ids[$resolved] = $resolved;
            }
        }

        return array_values($ids);
    }

    private function passageId(string $id, PassageMap $map): ?string
    {
        $id = trim($id, " \t\n\r\0\x0B[]()");
        if ('' !== $id && null !== $map->text($id)) {
            return $id;
        }
        $bare = preg_replace('/^(?:§|section|passage)\s*/iu', '', $id) ?? $id;
        if (1 === preg_match('/^(\d+)$/u', trim($bare), $match) && null !== $map->text('§'.$match[1])) {
            return '§'.$match[1];
        }

        return null;
    }

    private function pointExists(EvidenceLedger $ledger, string $point): bool
    {
        foreach ($ledger->points() as $known) {
            if ($known->id === $point) {
                return true;
            }
        }

        return false;
    }
}
