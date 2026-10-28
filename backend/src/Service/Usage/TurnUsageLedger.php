<?php

declare(strict_types=1);

namespace App\Service\Usage;

use App\Service\Infrastructure\RedisService;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Shared per-turn usage ledger (`03_contracts.md` §1.5, S1 AU11).
 *
 * Stores each provider call's contribution under `turnusage:{turnId}` (TTL 1 h)
 * with `HSETNX` on the call's `$callId`, so a retried or duplicated contribution
 * counts once. Closing uses `SET turnusage:{turnId}:closed NX` (TTL 1 h) so
 * only one writer closes a turn across processes (S5 pumps and Messenger
 * workers). When Redis is unavailable (e.g. unit tests without Redis), falls
 * back to an in-process map with identical `HSETNX` and `SET ... NX` semantics.
 */
final class TurnUsageLedger implements ResetInterface
{
    public const TTL_SECONDS = 3600;
    private const KEY_PREFIX = 'turnusage:';

    /** @var array<string, array<string, string>> */
    private array $localHashes = [];

    /** @var array<string, bool> */
    private array $localClosed = [];

    public function __construct(
        private readonly ?RedisService $redis = null,
    ) {
    }

    /**
     * Record one provider call's contribution into the turn's hash (`HSETNX`).
     *
     * @param array{
     *     prompt_tokens?: int,
     *     completion_tokens?: int,
     *     total_tokens?: int,
     *     cached_tokens?: int,
     *     cache_creation_tokens?: int,
     *     cache_creation_1h_tokens?: int,
     *     charged_cost?: string|null,
     *     price_known?: bool,
     *     facade_metered?: bool,
     *     provider?: string,
     *     model?: string,
     *     model_id?: int|null
     * } $contribution
     *
     * @return bool true when this `$callId` was newly recorded, false on duplicate
     */
    public function addContribution(string $turnId, string $callId, array $contribution): bool
    {
        if ('' === $turnId || '' === $callId) {
            return false;
        }

        $encoded = json_encode($contribution, \JSON_THROW_ON_ERROR);
        $hashKey = self::KEY_PREFIX.$turnId;

        if (null !== $this->redis && $this->redis->isAvailable()) {
            $stored = $this->redis->hSetNx($hashKey, $callId, $encoded, self::TTL_SECONDS);
            $this->localHashes[$turnId][$callId] ??= $encoded;

            return $stored;
        }

        if (isset($this->localHashes[$turnId][$callId])) {
            return false;
        }

        $this->localHashes[$turnId][$callId] = $encoded;

        return true;
    }

    /**
     * Read the current sum of all contributions for `$turnId` without closing it.
     */
    public function summary(string $turnId): TurnUsageTotal
    {
        $fields = $this->readFields($turnId);

        return self::foldFields($fields);
    }

    /**
     * Idempotently close the turn (`SET turnusage:{turnId}:closed NX`).
     *
     * Returns the summed {@see TurnUsageTotal} for the single caller that won
     * the close, or `null` if the turn was already closed.
     */
    public function close(string $turnId): ?TurnUsageTotal
    {
        if ('' === $turnId) {
            return null;
        }

        $closedKey = self::KEY_PREFIX.$turnId.':closed';

        if (null !== $this->redis && $this->redis->isAvailable()) {
            $claimed = $this->redis->trySetIfAbsent($closedKey, '1', self::TTL_SECONDS);
            if (false === $claimed) {
                return null;
            }
            if (true === $claimed) {
                $this->localClosed[$turnId] = true;

                return $this->summary($turnId);
            }
            // $claimed === null: Redis command failed mid-turn; fall through to in-process close.
        }

        if ($this->localClosed[$turnId] ?? false) {
            return null;
        }

        $this->localClosed[$turnId] = true;

        return $this->summary($turnId);
    }

    public function isClosed(string $turnId): bool
    {
        if ('' === $turnId) {
            return false;
        }

        if (null !== $this->redis && $this->redis->isAvailable()) {
            return $this->redis->exists(self::KEY_PREFIX.$turnId.':closed');
        }

        return $this->localClosed[$turnId] ?? false;
    }

    public function reset(): void
    {
        $this->localHashes = [];
        $this->localClosed = [];
    }

    /**
     * @return array<string, string>
     */
    private function readFields(string $turnId): array
    {
        if ('' === $turnId) {
            return [];
        }

        if (null !== $this->redis && $this->redis->isAvailable()) {
            $remote = $this->redis->hGetAll(self::KEY_PREFIX.$turnId);
            if (null !== $remote) {
                return $remote;
            }
        }

        return $this->localHashes[$turnId] ?? [];
    }

    /**
     * @param array<string, string> $fields
     */
    private static function foldFields(array $fields): TurnUsageTotal
    {
        $prompt = 0;
        $completion = 0;
        $total = 0;
        $cached = 0;
        $cacheCreation = 0;
        $cacheCreation1h = 0;
        $charged = 0.0;
        $priceKnown = true;
        $facadeMetered = false;
        $count = 0;

        foreach ($fields as $raw) {
            $decoded = json_decode($raw, true);
            if (!\is_array($decoded)) {
                continue;
            }
            ++$count;
            $p = (int) ($decoded['prompt_tokens'] ?? 0);
            $c = (int) ($decoded['completion_tokens'] ?? 0);
            $prompt += $p;
            $completion += $c;
            $total += (int) ($decoded['total_tokens'] ?? ($p + $c));
            $cached += (int) ($decoded['cached_tokens'] ?? 0);
            $cacheCreation += (int) ($decoded['cache_creation_tokens'] ?? 0);
            $cacheCreation1h += (int) ($decoded['cache_creation_1h_tokens'] ?? 0);
            if (isset($decoded['charged_cost']) && is_numeric($decoded['charged_cost'])) {
                $charged += (float) $decoded['charged_cost'];
            }
            if (false === ($decoded['price_known'] ?? true)) {
                $priceKnown = false;
            }
            if (true === ($decoded['facade_metered'] ?? false)) {
                $facadeMetered = true;
            }
        }

        return new TurnUsageTotal(
            promptTokens: $prompt,
            completionTokens: $completion,
            totalTokens: $total,
            cachedTokens: $cached,
            cacheCreationTokens: $cacheCreation,
            cacheCreation1hTokens: $cacheCreation1h,
            chargedCost: number_format($charged, 6, '.', ''),
            priceKnown: $priceKnown,
            facadeMetered: $facadeMetered,
            callCount: $count,
        );
    }
}
