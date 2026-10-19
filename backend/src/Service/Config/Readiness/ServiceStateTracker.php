<?php

declare(strict_types=1);

namespace App\Service\Config\Readiness;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

/**
 * Gives each observation a history, shared by all web nodes through the app
 * cache: when the current state began, whether the service was ever ready,
 * and how fast a download moves.
 *
 *  - never answered for less than START_GRACE_SECONDS → `starting`, then `failed`
 *  - answered before, does not answer now → `unavailable`
 *  - a download without a percent that runs longer than DOWNLOAD_STALL_SECONDS → `failed`
 *
 * A history is only continued while it is observed at least every
 * STREAK_GAP_SECONDS; after a longer silence (the stack was stopped, nobody
 * opened the page) it starts over, so yesterday's boot never reads as failed today.
 */
final readonly class ServiceStateTracker
{
    public const START_GRACE_SECONDS = 600;
    public const DOWNLOAD_STALL_SECONDS = 1800;
    public const STREAK_GAP_SECONDS = 900;

    private const KEY_PREFIX = 'service_readiness.';
    private const TTL_SECONDS = 86400;
    private const MIN_RATE_WINDOW_SECONDS = 30;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * @return array{state: string, since: string, minutes_left: ?int}
     */
    public function track(string $id, ReadinessObservation $observation): array
    {
        $now = $this->clock->now()->getTimestamp();
        $item = $this->cache->getItem(self::KEY_PREFIX.$id);
        $record = $this->continuedRecord($item->isHit() ? $item->get() : null, $now);

        if (null === $record || $record['kind'] !== $observation->kind) {
            $record = [
                'kind' => $observation->kind,
                'since' => $now,
                'ever_ready' => $record['ever_ready'] ?? false,
                'dl_model' => null,
                'dl_t0' => null,
                'dl_p0' => null,
            ];
        }
        $record['seen'] = $now;
        if (ReadinessObservation::READY === $observation->kind) {
            $record['ever_ready'] = true;
        }

        $minutesLeft = null;
        if (ReadinessObservation::DOWNLOADING === $observation->kind && null !== $observation->percent) {
            if ($record['dl_model'] !== $observation->model || null === $record['dl_p0'] || $observation->percent < $record['dl_p0']) {
                $record['dl_model'] = $observation->model;
                $record['dl_t0'] = $now;
                $record['dl_p0'] = $observation->percent;
            }
            $minutesLeft = $this->minutesLeft((int) $record['dl_t0'], (int) $record['dl_p0'], $observation->percent, $now);
        }

        $item->set($record);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);

        return [
            'state' => $this->state($observation, $record, $now),
            'since' => (new \DateTimeImmutable('@'.$record['since']))->format(\DATE_ATOM),
            'minutes_left' => $minutesLeft,
        ];
    }

    /**
     * @return array{kind: string, since: int, seen: int, ever_ready: bool, dl_model: ?string, dl_t0: ?int, dl_p0: ?int}|null
     */
    private function continuedRecord(mixed $stored, int $now): ?array
    {
        if (!\is_array($stored) || !\is_string($stored['kind'] ?? null) || !\is_int($stored['since'] ?? null) || !\is_int($stored['seen'] ?? null)) {
            return null;
        }
        if ($now - $stored['seen'] > self::STREAK_GAP_SECONDS) {
            return null;
        }

        return [
            'kind' => $stored['kind'],
            'since' => $stored['since'],
            'seen' => $stored['seen'],
            'ever_ready' => true === ($stored['ever_ready'] ?? false),
            'dl_model' => \is_string($stored['dl_model'] ?? null) ? $stored['dl_model'] : null,
            'dl_t0' => \is_int($stored['dl_t0'] ?? null) ? $stored['dl_t0'] : null,
            'dl_p0' => \is_int($stored['dl_p0'] ?? null) ? $stored['dl_p0'] : null,
        ];
    }

    /**
     * @param array{since: int, ever_ready: bool} $record
     */
    private function state(ReadinessObservation $observation, array $record, int $now): string
    {
        $age = $now - $record['since'];

        return match ($observation->kind) {
            ReadinessObservation::OFF => ServiceReadiness::OFF,
            ReadinessObservation::READY => ServiceReadiness::READY,
            ReadinessObservation::BROKEN => ServiceReadiness::FAILED,
            ReadinessObservation::DOWNLOADING => null === $observation->percent && $age >= self::DOWNLOAD_STALL_SECONDS
                ? ServiceReadiness::FAILED
                : ServiceReadiness::DOWNLOADING,
            default => match (true) {
                $record['ever_ready'] => ServiceReadiness::UNAVAILABLE,
                $age < self::START_GRACE_SECONDS => ServiceReadiness::STARTING,
                default => ServiceReadiness::FAILED,
            },
        };
    }

    private function minutesLeft(int $startedAt, int $startPercent, int $percent, int $now): ?int
    {
        $elapsed = $now - $startedAt;
        if ($percent <= $startPercent || $percent >= 100 || $elapsed < self::MIN_RATE_WINDOW_SECONDS) {
            return null;
        }

        $secondsLeft = (100 - $percent) * $elapsed / ($percent - $startPercent);

        return max(1, (int) ceil($secondsLeft / 60));
    }
}
