<?php

declare(strict_types=1);

namespace App\Service\ModelDiscovery;

/**
 * Pure matcher: upstream OpenRouter models vs catalog rows and ignore entries.
 *
 * Matching keys are for comparison only — never shown as a suggested provider id.
 */
final readonly class ModelDiscoveryMatcher
{
    public const DEFAULT_WINDOW_DAYS = 30;

    /**
     * OpenRouter vendor prefix → our first-party catalog service name.
     * Every other vendor is out of scope (gateways / open-weight hosts).
     *
     * @var array<string, string>
     */
    public const VENDOR_MAP = [
        'anthropic' => 'Anthropic',
        'openai' => 'OpenAI',
        'google' => 'Google',
        'x-ai' => 'xAI',
        'mistralai' => 'Mistral',
    ];

    /**
     * @param list<UpstreamModel>                                     $upstream
     * @param list<array{service: string, providerId: string}>        $catalogRows
     * @param array<string, array{reason: string, decidedOn: string}> $ignoreEntries keyed by exact OpenRouter id
     */
    public function match(
        array $upstream,
        array $catalogRows,
        array $ignoreEntries,
        \DateTimeImmutable $now,
        int $windowDays = self::DEFAULT_WINDOW_DAYS,
    ): DiscoveryMatchResult {
        $knownByService = $this->indexCatalog($catalogRows);
        $upstreamIds = [];
        foreach ($upstream as $model) {
            $upstreamIds[$model->openRouterId] = true;
        }

        $windowStart = $now->modify(sprintf('-%d days', $windowDays));

        $new = [];
        $ignored = [];

        foreach ($upstream as $model) {
            if (!$this->isCandidate($model, $windowStart)) {
                continue;
            }

            $service = self::VENDOR_MAP[$model->vendor] ?? null;
            if (null === $service) {
                continue;
            }

            $key = self::normalizeKey(substr($model->openRouterId, strlen($model->vendor) + 1));
            $knownKeys = $knownByService[$service] ?? [];

            if (isset($knownKeys[$key])) {
                continue;
            }

            $ignore = $ignoreEntries[$model->openRouterId] ?? null;
            if (null !== $ignore) {
                $ignored[] = [
                    'model' => $model,
                    'reason' => $ignore['reason'],
                    'decidedOn' => $ignore['decidedOn'],
                ];
                continue;
            }

            $new[] = $model;
        }

        $obsoleteIgnores = [];
        foreach ($ignoreEntries as $openRouterId => $entry) {
            if (!isset($upstreamIds[$openRouterId])) {
                $obsoleteIgnores[] = [
                    'openRouterId' => $openRouterId,
                    'reason' => $entry['reason'],
                    'decidedOn' => $entry['decidedOn'],
                    'why' => 'gone_upstream',
                ];
                continue;
            }

            $slash = strpos($openRouterId, '/');
            if (false === $slash) {
                continue;
            }
            $vendor = substr($openRouterId, 0, $slash);
            $service = self::VENDOR_MAP[$vendor] ?? null;
            if (null === $service) {
                continue;
            }

            $key = self::normalizeKey(substr($openRouterId, $slash + 1));
            if (isset(($knownByService[$service] ?? [])[$key])) {
                $obsoleteIgnores[] = [
                    'openRouterId' => $openRouterId,
                    'reason' => $entry['reason'],
                    'decidedOn' => $entry['decidedOn'],
                    'why' => 'now_in_catalog',
                ];
            }
        }

        return new DiscoveryMatchResult($new, $ignored, $obsoleteIgnores);
    }

    /**
     * Comparison key only — never use as a suggested provider id.
     *
     * Strips a trailing date suffix (`-YYYYMMDD` or `-YYYY-MM-DD`), lowercases,
     * and replaces `.` with `-`.
     */
    public static function normalizeKey(string $id): string
    {
        $key = strtolower(str_replace('.', '-', $id));
        $key = preg_replace('/-\d{8}$/', '', $key) ?? $key;
        $key = preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $key) ?? $key;

        return $key;
    }

    private function isCandidate(UpstreamModel $model, \DateTimeImmutable $windowStart): bool
    {
        if (str_contains($model->openRouterId, ':')) {
            return false;
        }

        if (str_starts_with($model->openRouterId, '~')) {
            return false;
        }

        if (!isset(self::VENDOR_MAP[$model->vendor])) {
            return false;
        }

        return $model->created >= $windowStart;
    }

    /**
     * @param list<array{service: string, providerId: string}> $catalogRows
     *
     * @return array<string, array<string, true>> service => normalized key => true
     */
    private function indexCatalog(array $catalogRows): array
    {
        $known = [];
        foreach ($catalogRows as $row) {
            $service = $row['service'];
            $key = self::normalizeKey($row['providerId']);
            $known[$service][$key] = true;
        }

        return $known;
    }
}
