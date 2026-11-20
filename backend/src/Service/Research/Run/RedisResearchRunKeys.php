<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Service\Infrastructure\RedisService;
use App\Service\Run\RedisRunKeys;
use App\Service\Run\RunStoreUnavailable;

/**
 * Research keys on the shared Redis. Leases, checkpoints and events are the
 * run kernel's scripts; source texts are written only while the run exists
 * and expire with it. A dead store reads as `ResearchStoreUnavailable`, so a
 * person sees the research sentence.
 */
final readonly class RedisResearchRunKeys implements ResearchRunKeys
{
    private const PUT_SOURCE_TEXT = <<<'LUA'
local ttl = redis.call('PTTL', KEYS[1])
if ttl < 1 then
  return 0
end
redis.call('SET', KEYS[2], ARGV[1], 'PX', ttl)
redis.call('SADD', KEYS[3], ARGV[2])
redis.call('PEXPIRE', KEYS[3], ttl)
return 1
LUA;

    private RedisRunKeys $kernel;

    public function __construct(private RedisService $redis)
    {
        $this->kernel = new RedisRunKeys($redis);
    }

    public function get(string $key): ?string
    {
        return $this->guard(fn (): ?string => $this->kernel->get($key));
    }

    public function set(string $key, string $value, ?int $ttlSeconds): bool
    {
        return $this->guard(fn (): bool => $this->kernel->set($key, $value, $ttlSeconds));
    }

    public function setIfAbsent(string $key, string $value, int $ttlSeconds): bool
    {
        return $this->guard(fn (): bool => $this->kernel->setIfAbsent($key, $value, $ttlSeconds));
    }

    public function claim(string $claimKey, string $token, int $ttlMillis, string $indexKey, int $score, string $member): bool
    {
        return $this->guard(fn (): bool => $this->kernel->claim($claimKey, $token, $ttlMillis, $indexKey, $score, $member));
    }

    public function renew(string $claimKey, string $token, int $ttlMillis, string $indexKey, int $score, string $member): bool
    {
        return $this->guard(fn (): bool => $this->kernel->renew($claimKey, $token, $ttlMillis, $indexKey, $score, $member));
    }

    public function compareAndSet(string $claimKey, string $token, string $stateKey, string $state, ?int $ttlSeconds): bool
    {
        return $this->guard(fn (): bool => $this->kernel->compareAndSet($claimKey, $token, $stateKey, $state, $ttlSeconds));
    }

    public function deleteIfEquals(string $key, string $expected): bool
    {
        return $this->guard(fn (): bool => $this->kernel->deleteIfEquals($key, $expected));
    }

    public function delete(string $key): void
    {
        $this->guard(fn () => $this->kernel->delete($key));
    }

    public function expire(string $key, int $ttlSeconds): void
    {
        $this->guard(fn () => $this->kernel->expire($key, $ttlSeconds));
    }

    public function range(string $key, int $start, int $stop): array
    {
        return $this->guard(fn (): array => $this->kernel->range($key, $start, $stop));
    }

    public function addMember(string $key, string $member): void
    {
        $this->guard(fn () => $this->kernel->addMember($key, $member));
    }

    public function removeMember(string $key, string $member): void
    {
        $this->guard(fn () => $this->kernel->removeMember($key, $member));
    }

    public function members(string $key): array
    {
        return $this->guard(fn (): array => $this->kernel->members($key));
    }

    public function zRem(string $key, string $member): void
    {
        $this->guard(fn () => $this->kernel->zRem($key, $member));
    }

    public function zRangeByScore(string $key, int $maxScore): array
    {
        return $this->guard(fn (): array => $this->kernel->zRangeByScore($key, $maxScore));
    }

    public function appendEvent(string $seqKey, string $listKey, string $runKey, string $body): ?int
    {
        return $this->guard(fn (): ?int => $this->kernel->appendEvent($seqKey, $listKey, $runKey, $body));
    }

    public function awaitEvent(string $listKey, int $afterSeq, int $blockMs): void
    {
        $this->kernel->awaitEvent($listKey, $afterSeq, $blockMs);
    }

    public function putSourceText(string $runId, string $sourceId, string $text): bool
    {
        $result = $this->redis->eval(
            self::PUT_SOURCE_TEXT,
            [ResearchKey::run($runId), ResearchKey::text($runId, $sourceId), ResearchKey::textIndex($runId)],
            [$text, $sourceId],
        );
        if (is_int($result)) {
            return 1 === $result;
        }
        if ((is_string($result) || $result instanceof \Stringable) && is_numeric((string) $result)) {
            return 1 === (int) (string) $result;
        }

        throw new ResearchStoreUnavailable();
    }

    /**
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     */
    private function guard(\Closure $call): mixed
    {
        try {
            return $call();
        } catch (RunStoreUnavailable) {
            throw new ResearchStoreUnavailable();
        }
    }
}
