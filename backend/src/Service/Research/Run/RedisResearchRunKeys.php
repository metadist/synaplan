<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Service\Infrastructure\RedisService;

/**
 * Research keys on the shared Redis. A checkpoint is written only while the
 * claim token still holds the lease, in one Lua script, so a worker whose
 * lease expired cannot overwrite a newer run.
 */
final readonly class RedisResearchRunKeys implements ResearchRunKeys
{
    private const APPEND_EVENT = <<<'LUA'
local seq = redis.call('INCR', KEYS[1])
local ttl = tonumber(ARGV[1])
redis.call('EXPIRE', KEYS[1], ttl)
redis.call('RPUSH', KEYS[2], seq .. '\n' .. ARGV[2])
redis.call('EXPIRE', KEYS[2], ttl)
if redis.call('EXISTS', KEYS[3]) == 1 then
  redis.call('EXPIRE', KEYS[3], ttl)
end
return seq
LUA;

    private const COMPARE_AND_SET = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
  redis.call('SET', KEYS[2], ARGV[2], 'EX', tonumber(ARGV[3]))
  return 1
end
return 0
LUA;

    private const CLAIM = <<<'LUA'
if redis.call('SET', KEYS[1], ARGV[1], 'PX', tonumber(ARGV[2]), 'NX') then
  redis.call('ZADD', KEYS[2], tonumber(ARGV[3]), ARGV[4])
  return 1
end
return 0
LUA;

    private const GET = <<<'LUA'
local value = redis.call('GET', KEYS[1])
if value then
  return {1, value}
end
return {0}
LUA;

    private const MEMBERS = <<<'LUA'
return redis.call('SMEMBERS', KEYS[1])
LUA;

    private const DELETE_IF_EQUALS = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
  return redis.call('DEL', KEYS[1])
end
return 0
LUA;

    public function __construct(private RedisService $redis)
    {
    }

    public function get(string $key): ?string
    {
        $result = $this->redis->eval(self::GET, [$key], []);
        if (!is_array($result) || [] === $result) {
            throw new ResearchStoreUnavailable();
        }
        if (1 !== $this->integer($result[0])) {
            return null;
        }
        if (!isset($result[1])) {
            throw new ResearchStoreUnavailable();
        }

        return (string) $result[1];
    }

    public function set(string $key, string $value, int $ttlSeconds): bool
    {
        if (!$this->redis->set($key, $value, $ttlSeconds)) {
            throw new ResearchStoreUnavailable();
        }

        return true;
    }

    public function claim(string $claimKey, string $token, int $ttlMillis, string $indexKey, int $score, string $member): bool
    {
        $result = $this->redis->eval(
            self::CLAIM,
            [$claimKey, $indexKey],
            [$token, (string) max(1, $ttlMillis), (string) $score, $member],
        );

        return 1 === $this->requiredInteger($result);
    }

    public function compareAndSet(string $claimKey, string $token, string $stateKey, string $state, int $ttlSeconds): bool
    {
        $result = $this->redis->eval(
            self::COMPARE_AND_SET,
            [$claimKey, $stateKey],
            [$token, $state, (string) $ttlSeconds],
        );
        $number = $this->requiredInteger($result);

        return 1 === $number;
    }

    public function deleteIfEquals(string $key, string $expected): bool
    {
        $result = $this->redis->eval(self::DELETE_IF_EQUALS, [$key], [$expected]);

        return $this->requiredInteger($result) > 0;
    }

    public function delete(string $key): void
    {
        $this->redis->delete($key);
    }

    public function expire(string $key, int $ttlSeconds): void
    {
        $this->redis->expire($key, $ttlSeconds);
    }

    public function range(string $key, int $start, int $stop): array
    {
        $rows = $this->redis->listRange($key, $start, $stop);
        if (null === $rows) {
            throw new ResearchStoreUnavailable();
        }

        return $rows;
    }

    public function addMember(string $key, string $member): void
    {
        if (!$this->redis->sAdd($key, $member)) {
            throw new ResearchStoreUnavailable();
        }
    }

    public function removeMember(string $key, string $member): void
    {
        $this->redis->sRem($key, $member);
    }

    public function members(string $key): array
    {
        $result = $this->redis->eval(self::MEMBERS, [$key], []);
        if (!is_array($result)) {
            throw new ResearchStoreUnavailable();
        }

        return array_values(array_map(static fn (mixed $m): string => (string) $m, $result));
    }

    public function zAdd(string $key, int $score, string $member): void
    {
        if (!$this->redis->zAdd($key, (float) $score, $member)) {
            throw new ResearchStoreUnavailable();
        }
    }

    public function zRem(string $key, string $member): void
    {
        $this->redis->zRem($key, $member);
    }

    public function zRangeByScore(string $key, int $maxScore): array
    {
        return $this->redis->zRangeByScore($key, '-inf', (string) $maxScore);
    }

    public function appendEvent(string $runId, string $body, int $ttlSeconds): int
    {
        $result = $this->redis->eval(
            self::APPEND_EVENT,
            [ResearchKey::eventSeq($runId), ResearchKey::events($runId), ResearchKey::run($runId)],
            [(string) $ttlSeconds, $body],
        );
        $seq = $this->requiredInteger($result);
        if ($seq < 1) {
            throw new ResearchStoreUnavailable();
        }

        return $seq;
    }

    private function requiredInteger(mixed $result): int
    {
        $number = $this->integer($result);
        if (null === $number) {
            throw new ResearchStoreUnavailable();
        }

        return $number;
    }

    private function integer(mixed $result): ?int
    {
        if (is_int($result)) {
            return $result;
        }
        if (is_string($result) && is_numeric($result)) {
            return (int) $result;
        }
        if (is_object($result) && is_numeric((string) $result)) {
            return (int) (string) $result;
        }

        return null;
    }
}
