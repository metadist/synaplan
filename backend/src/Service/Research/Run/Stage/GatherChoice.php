<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\Ledger\CanonicalUrl;
use App\Service\Research\Run\Ledger\PlainText;

/**
 * Picks which hits to read. A title never becomes a search, and a host is
 * not opened twice until every other host has had a turn.
 */
final class GatherChoice
{
    /**
     * The agreed shape is a list under `results`. A model that ignores the
     * schema and returns one verdict per hit id, at the root or under
     * `results`, made the same decision and is kept. Ids that name no hit
     * are dropped later by choose().
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<string, array<string, mixed>>
     */
    public static function verdicts(array $data): array
    {
        $source = is_array($data['results'] ?? null) ? $data['results'] : $data;

        $verdicts = [];
        foreach ($source as $key => $row) {
            if (!is_array($row) || !array_key_exists('read', $row)) {
                continue;
            }
            $id = is_string($key) ? $key : ($row['id'] ?? null);
            if (is_string($id) && '' !== $id) {
                $verdicts[$id] = ['id' => $id] + $row;
            }
        }

        return $verdicts;
    }

    /**
     * @param list<array{id: string, url: string, host: string, title: string}> $hits
     * @param array<string, array<string, mixed>>                               $verdicts
     * @param list<string>                                                      $mustUse
     * @param list<string>                                                      $mustAvoid
     *
     * @return list<array{url: string, title: string, kind: string, reason: string}>
     */
    public function choose(array $hits, array $verdicts, array $mustUse, array $mustAvoid, int $room): array
    {
        $wanted = [];
        foreach ($hits as $hit) {
            $verdict = $verdicts[$hit['id']] ?? null;
            if (!is_array($verdict) || true !== ($verdict['read'] ?? false)) {
                continue;
            }
            $kind = is_string($verdict['kind'] ?? null) ? $verdict['kind'] : 'other';
            if ($this->avoided($hit, $kind, $mustAvoid)) {
                continue;
            }
            $reason = is_string($verdict['reason'] ?? null) ? PlainText::line($verdict['reason'], 200) : '';
            $wanted[] = $hit + ['kind' => $kind, 'reason' => $reason, 'preferred' => $this->preferred($hit, $mustUse)];
        }

        usort($wanted, static fn (array $left, array $right): int => $right['preferred'] <=> $left['preferred']);

        return $this->diverse($wanted, $room);
    }

    /**
     * @param array{url: string, host: string, title: string} $hit
     * @param list<string>                                    $avoid
     */
    private function avoided(array $hit, string $kind, array $avoid): bool
    {
        foreach ($avoid as $token) {
            $needle = mb_strtolower($token);
            if ('' === $needle) {
                continue;
            }
            if ($needle === $kind || str_contains(mb_strtolower($hit['host']), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{host: string, title: string} $hit
     * @param list<string>                       $mustUse
     */
    private function preferred(array $hit, array $mustUse): int
    {
        $haystack = mb_strtolower($hit['host'].' '.$hit['title']);
        foreach ($mustUse as $token) {
            $needle = mb_strtolower($token);
            if ('' !== $needle && str_contains($haystack, $needle)) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * @param list<array{url: string, title: string, host: string, kind: string, reason: string}> $wanted
     *
     * @return list<array{url: string, title: string, kind: string, reason: string}>
     */
    private function diverse(array $wanted, int $room): array
    {
        $picked = [];
        $seenHost = [];
        $seenUrl = [];
        $passes = [$wanted, $wanted];
        foreach ($passes as $pass => $rows) {
            foreach ($rows as $row) {
                if (count($picked) >= $room || isset($seenUrl[$row['url']])) {
                    continue;
                }
                if (0 === $pass && isset($seenHost[$row['host']])) {
                    continue;
                }
                $seenHost[$row['host']] = true;
                $seenUrl[$row['url']] = true;
                $picked[] = ['url' => $row['url'], 'title' => $row['title'], 'kind' => $row['kind'], 'reason' => $row['reason']];
            }
        }

        return $picked;
    }

    public static function host(string $url): string
    {
        return CanonicalUrl::host($url) ?? '';
    }
}
