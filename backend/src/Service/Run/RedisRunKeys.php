<?php

declare(strict_types=1);

namespace App\Service\Run;

use App\Service\Infrastructure\RedisService;

/**
 * Run keys on the shared Redis. A checkpoint is written only while the claim
 * token still holds the lease, in one Lua script, so a worker whose lease
 * expired cannot overwrite a newer run. Events are written only while the run
 * exists and expire with it.
 */
final readonly class RedisRunKeys implements RunKeys
{
    private const APPEND_EVENT = <<<'LUA'
local ttl = redis.call('PTTL', KEYS[3])
if ttl == -2 or ttl == 0 then
  return 0
end
local seq = redis.call('INCR', KEYS[1])
redis.call('RPUSH', KEYS[2], seq .. '\n' .. ARGV[1])
redis.call('XADD', KEYS[4], 'MAXLEN', '1', '0-' .. seq, 'seq', seq)
for i = 1, 4 do
  if i ~= 3 then
    if ttl > 0 then
      redis.call('PEXPIRE', KEYS[i], ttl)
    else
      redis.call('PERSIST', KEYS[i])
    end
  end
end
return seq
LUA;
    private const COMPARE_AND_SET = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] and redis.call('EXISTS', KEYS[2]) == 1 then
  if tonumber(ARGV[3]) > 0 then
    redis.call('SET', KEYS[2], ARGV[2], 'EX', tonumber(ARGV[3]))
  else
    redis.call('SET', KEYS[2], ARGV[2])
  end
  redis.call('DEL', KEYS[1])
  return 1
end
return 0
LUA;

    private const SET_IF_ABSENT = <<<'LUA'
if redis.call('SET', KEYS[1], ARGV[1], 'EX', tonumber(ARGV[2]), 'NX') then
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

    private const RENEW = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
  redis.call('PEXPIRE', KEYS[1], tonumber(ARGV[2]))
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
            throw new RunStoreUnavailable();
        }
        if (1 !== $this->integer($result[0])) {
            return null;
        }
        if (!isset($result[1])) {
            throw new RunStoreUnavailable();
        }

        return (string) $result[1];
    }

    public function set(string $key, string $value, ?int $ttlSeconds): bool
    {
        if (!$this->redis->set($key, $value, $ttlSeconds)) {
            throw new RunStoreUnavailable();
        }

        return true;
    }

    /**
     * Through a script, because the plain client answers false both for a
     * held key and for a store that did not answer.
     */
    public function setIfAbsent(string $key, string $value, int $ttlSeconds): bool
    {
        $result = $this->redis->eval(self::SET_IF_ABSENT, [$key], [$value, (string) max(1, $ttlSeconds)]);

        return 1 === $this->requiredInteger($result);
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

    public function renew(string $claimKey, string $token, int $ttlMillis, string $indexKey, int $score, string $member): bool
    {
        $result = $this->redis->eval(
            self::RENEW,
            [$claimKey, $indexKey],
            [$token, (string) max(1, $ttlMillis), (string) $score, $member],
        );

        return 1 === $this->requiredInteger($result);
    }

    public function compareAndSet(string $claimKey, string $token, string $stateKey, string $state, ?int $ttlSeconds): bool
    {
        $result = $this->redis->eval(
            self::COMPARE_AND_SET,
            [$claimKey, $stateKey],
            [$token, $state, (string) max(0, $ttlSeconds ?? 0)],
        );

        return 1 === $this->requiredInteger($result);
    }

    public function deleteIfEquals(string $key, string $expected): bool
    {
        $result = $this->redis->eval(self::DELETE_IF_EQUALS, [$key], [$expected]);

        return $this->requiredInteger($result) > 0;
    }

    public function delete(string $key): void
    {
        if (!$this->redis->delete($key)) {
            throw new RunStoreUnavailable();
        }
    }

    public function expire(string $key, int $ttlSeconds): void
    {
        $this->redis->expire($key, $ttlSeconds);
    }

    public function range(string $key, int $start, int $stop): array
    {
        $rows = $this->redis->listRange($key, $start, $stop);
        if (null === $rows) {
            throw new RunStoreUnavailable();
        }

        return $rows;
    }

    public function addMember(string $key, string $member): void
    {
        if (!$this->redis->sAdd($key, $member)) {
            throw new RunStoreUnavailable();
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
            throw new RunStoreUnavailable();
        }

        return array_values(array_map(static fn (mixed $m): string => (string) $m, $result));
    }

    public function zRem(string $key, string $member): void
    {
        $this->redis->zRem($key, $member);
    }

    public function zRangeByScore(string $key, int $maxScore): array
    {
        return $this->redis->zRangeByScore($key, '-inf', (string) $maxScore);
    }

    public function appendEvent(string $seqKey, string $listKey, string $runKey, string $body): ?int
    {
        $seq = $this->requiredInteger($this->redis->eval(self::APPEND_EVENT, [$seqKey, $listKey, $runKey, self::wakeKey($listKey)], [$body]));

        return $seq < 1 ? null : $seq;
    }

    public function awaitEvent(string $listKey, int $afterSeq, int $blockMs): void
    {
        $this->redis->readStream(self::wakeKey($listKey), '0-'.max(0, $afterSeq), $blockMs);
    }

    /** One entry, the last sequence number, so a waiter can block on it. */
    private static function wakeKey(string $listKey): string
    {
        return $listKey.':wake';
    }

    private function requiredInteger(mixed $result): int
    {
        $number = $this->integer($result);
        if (null === $number) {
            throw new RunStoreUnavailable();
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
