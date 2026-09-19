<?php

declare(strict_types=1);

namespace App\Service\Compute;

use App\Repository\ComputeRunRepository;
use App\Service\Compute\Contract\ComputeHealth;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Builds the `compute` entry of the admin feature-status payload (CS27).
 *
 * Always probes the sidecar — even when the flag is off — so the card can
 * tell "disabled" apart from "sidecar not answering". A failing sidecar
 * degrades to the unreachable entry; it never breaks the status page.
 * Cached 30 s; the entry is global (not per-user).
 */
final readonly class ComputeFeatureStatusBuilder
{
    private const CACHE_KEY = 'compute_feature_status';
    private const CACHE_TTL_SECONDS = 30;

    /**
     * Minimum tier until CS31 introduces the COMPUTE.REQUIRE_TIER setting.
     * `docker` is the lowest tier, so every known tier meets it.
     */
    private const MINIMUM_TIER = 'docker';

    /** @var array<string, int> */
    private const TIER_ORDER = ['docker' => 0, 'gvisor' => 1, 'microvm' => 2];

    public function __construct(
        private ComputeConfig $config,
        private ComputeClient $client,
        private ComputeRunRepository $runs,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{enabled: bool, reachable: bool, protocol: int, tier: string, tierMeetsRequirement: bool, capacity: array{maxConcurrent: int, running: int, queued: int}, images: list<array{key: string, digest: string}>, runsLast24h: int, failedLast24h: int}
     */
    public function build(): array
    {
        try {
            return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
                $item->expiresAfter(self::CACHE_TTL_SECONDS);

                return $this->computeEntry();
            });
        } catch (\Throwable $e) {
            $this->logger->warning('ComputeFeatureStatusBuilder: status cache failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->emptyEntry();
        }
    }

    /**
     * @return array{enabled: bool, reachable: bool, protocol: int, tier: string, tierMeetsRequirement: bool, capacity: array{maxConcurrent: int, running: int, queued: int}, images: list<array{key: string, digest: string}>, runsLast24h: int, failedLast24h: int}
     */
    private function computeEntry(): array
    {
        $since = new \DateTimeImmutable('-24 hours');
        $runsLast24h = $this->runs->countSince($since);
        $failedLast24h = $this->runs->countFailedSince($since);

        try {
            $health = $this->client->health();
        } catch (\Throwable) {
            return array_merge($this->emptyEntry(), [
                'runsLast24h' => $runsLast24h,
                'failedLast24h' => $failedLast24h,
            ]);
        }

        return [
            'enabled' => $this->config->isEnabled(),
            'reachable' => true,
            'protocol' => $health->protocol,
            'tier' => $health->tier,
            'tierMeetsRequirement' => $this->tierMeetsRequirement($health),
            'capacity' => [
                'maxConcurrent' => $health->capacity['maxConcurrent'],
                'running' => $health->capacity['running'],
                'queued' => $health->capacity['queued'],
            ],
            'images' => array_map(
                static fn (array $image): array => [
                    'key' => (string) $image['key'],
                    'digest' => self::shortDigest((string) $image['ref']),
                ],
                $health->images
            ),
            'runsLast24h' => $runsLast24h,
            'failedLast24h' => $failedLast24h,
        ];
    }

    /**
     * @return array{enabled: bool, reachable: bool, protocol: int, tier: string, tierMeetsRequirement: bool, capacity: array{maxConcurrent: int, running: int, queued: int}, images: list<array{key: string, digest: string}>, runsLast24h: int, failedLast24h: int}
     */
    private function emptyEntry(): array
    {
        return [
            'enabled' => $this->config->isEnabled(),
            'reachable' => false,
            'protocol' => 0,
            'tier' => '',
            'tierMeetsRequirement' => false,
            'capacity' => ['maxConcurrent' => 0, 'running' => 0, 'queued' => 0],
            'images' => [],
            'runsLast24h' => 0,
            'failedLast24h' => 0,
        ];
    }

    private function tierMeetsRequirement(ComputeHealth $health): bool
    {
        return $this->tierAtLeast($health->tier, self::MINIMUM_TIER);
    }

    private function tierAtLeast(string $tier, string $minimum): bool
    {
        $levels = array_keys(self::TIER_ORDER);
        $at = array_search($tier, $levels, true);
        $need = array_search($minimum, $levels, true);

        return false !== $at && false !== $need && $at >= $need;
    }

    private static function shortDigest(string $ref): string
    {
        if (1 === preg_match('/sha256:([0-9a-f]{12})[0-9a-f]*/i', $ref, $matches)) {
            return strtolower($matches[1]);
        }

        return mb_strlen($ref) > 24 ? mb_substr($ref, 0, 24).'…' : $ref;
    }
}
