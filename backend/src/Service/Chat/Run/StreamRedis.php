<?php

declare(strict_types=1);

namespace App\Service\Chat\Run;

use App\Service\Infrastructure\RedisService;

/**
 * The pump's Redis keys: the job stream, one expiring body, the run
 * state, and the run's frame log (`chatrun:{id}:stream`, ids `0-{seq}`).
 *
 * Every key goes through {@see RedisService}, so it keeps the application
 * prefix. A body that expired is claimed as a miss; the caller writes the
 * terminal frame.
 */
final class StreamRedis
{
    public const JOB_TTL_SECONDS = 120;

    public const RUN_TTL_SECONDS = 1800;

    public const CLAIM_DEADLINE_SECONDS = 30;

    private const OPEN = 'stream:open';

    private const JOBS = 'stream:jobs';

    public function __construct(
        private readonly RedisService $redis,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public function enqueue(string $jobId, string $runId, array $body, int $now): bool
    {
        StreamJobGuard::reject($body);
        $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($encoded)) {
            return false;
        }
        $deadline = (string) ($now + self::CLAIM_DEADLINE_SECONDS);
        $result = $this->redis->eval(
            <<<'LUA'
redis.call('SET', KEYS[1], ARGV[1], 'EX', tonumber(ARGV[2]))
redis.call('XADD', KEYS[2], '*', 'job', ARGV[3], 'run', ARGV[4])
redis.call('HSET', KEYS[3], 'state', 'queued', 'deadline', ARGV[5], 'incarnation', '')
redis.call('EXPIRE', KEYS[3], ARGV[6])
redis.call('SADD', KEYS[4], ARGV[4])
return 1
LUA,
            [
                'stream:job:'.$jobId,
                self::JOBS,
                'stream:run:'.$runId,
                self::OPEN,
            ],
            [$encoded, (string) self::JOB_TTL_SECONDS, $jobId, $runId, $deadline, (string) self::RUN_TTL_SECONDS],
        );

        return 1 === (int) $result;
    }

    public function ensureGroup(): void
    {
        $this->redis->eval(
            <<<'LUA'
redis.call('XGROUP', 'CREATE', KEYS[1], ARGV[1], '0', 'MKSTREAM')
return 1
LUA,
            [self::JOBS],
            ['pump'],
        );
    }

    /**
     * @return array{id: string, job: string, run: string}|null
     */
    public function claim(string $consumer): ?array
    {
        $claimed = $this->readGroup(
            <<<'LUA'
local function field(list, name)
  if list == nil then return '' end
  for i = 1, #list, 2 do
    if list[i] == name then return list[i + 1] end
  end
  return ''
end
local res = redis.call('XAUTOCLAIM', KEYS[1], 'pump', ARGV[1], '10000', '0-0', 'COUNT', 1)
if res == false or res[2] == false or res[2][1] == nil then
  return false
end
local entry = res[2][1]
return {entry[1], field(entry[2], 'job'), field(entry[2], 'run')}
LUA,
            $consumer,
        );
        if (null !== $claimed) {
            return $claimed;
        }

        return $this->readGroup(
            <<<'LUA'
local function field(list, name)
  if list == nil then return '' end
  for i = 1, #list, 2 do
    if list[i] == name then return list[i + 1] end
  end
  return ''
end
local res = redis.call('XREADGROUP', 'GROUP', 'pump', ARGV[1], 'COUNT', '1', 'STREAMS', KEYS[1], '>')
if res == false or res[1] == nil or res[1][2] == nil or res[1][2][1] == nil then
  return false
end
local entry = res[1][2][1]
return {entry[1], field(entry[2], 'job'), field(entry[2], 'run')}
LUA,
            $consumer,
        );
    }

    public function acknowledge(string $streamId): void
    {
        $this->redis->eval(
            <<<'LUA'
redis.call('XACK', KEYS[1], 'pump', ARGV[1])
redis.call('XDEL', KEYS[1], ARGV[1])
return 1
LUA,
            [self::JOBS],
            [$streamId],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function takeBody(string $jobId): ?array
    {
        $raw = $this->redis->getAndDelete('stream:job:'.$jobId);
        if (!is_string($raw) || '' === $raw) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function markRunning(string $runId, string $incarnation): void
    {
        $this->redis->eval(
            <<<'LUA'
if redis.call('HGET', KEYS[1], 'state') ~= 'queued' then
  return 0
end
redis.call('HSET', KEYS[1], 'state', 'running', 'incarnation', ARGV[1])
redis.call('EXPIRE', KEYS[1], ARGV[2])
return 1
LUA,
            ['stream:run:'.$runId],
            [$incarnation, (string) self::RUN_TTL_SECONDS],
        );
    }

    public function markFinished(string $runId): void
    {
        $this->redis->eval(
            <<<'LUA'
redis.call('HSET', KEYS[1], 'state', 'complete')
redis.call('EXPIRE', KEYS[1], '300')
redis.call('SREM', KEYS[2], ARGV[1])
return 1
LUA,
            ['stream:run:'.$runId, self::OPEN],
            [$runId],
        );
    }

    public function appendFrame(string $runId, int $seq, string $kind, string $frame): bool
    {
        if ($seq < 1) {
            return false;
        }
        $result = $this->redis->eval(
            <<<'LUA'
redis.call('XADD', KEYS[1], ARGV[1], 'kind', ARGV[2], 'frame', ARGV[3])
redis.call('EXPIRE', KEYS[1], ARGV[4])
return 1
LUA,
            ['chatrun:'.$runId.':stream'],
            ['0-'.$seq, $kind, $frame, (string) self::RUN_TTL_SECONDS],
        );

        return 1 === (int) $result;
    }

    /**
     * @return list<RunFrame>
     */
    public function framesAfter(string $runId, int $afterSeq): array
    {
        $rows = $this->redis->eval(
            <<<'LUA'
return redis.call('XRANGE', KEYS[1], '(' .. ARGV[1], '+')
LUA,
            ['chatrun:'.$runId.':stream'],
            ['0-'.$afterSeq],
        );
        if (!is_array($rows)) {
            return [];
        }

        return self::frames($rows);
    }

    public function beat(string $incarnation, string $serverId): void
    {
        $this->redis->set('pump:'.$incarnation, $serverId, 10);
        $this->redis->set('pump:alive', '1', 10);
    }

    public function heartbeatAlive(string $incarnation): bool
    {
        if ('' === $incarnation) {
            return false;
        }

        return is_string($this->redis->get('pump:'.$incarnation));
    }

    /**
     * @return list<string>
     */
    public function openRunIds(): array
    {
        $rows = $this->redis->eval(
            <<<'LUA'
return redis.call('SMEMBERS', KEYS[1])
LUA,
            [self::OPEN],
            [],
        );
        if (!is_array($rows)) {
            return [];
        }
        $ids = [];
        foreach ($rows as $row) {
            if (is_string($row) && '' !== $row) {
                $ids[] = $row;
            }
        }

        return $ids;
    }

    /**
     * @return array{state: string, deadline: int, incarnation: string}
     */
    public function runState(string $runId): array
    {
        $rows = $this->redis->eval(
            <<<'LUA'
return redis.call('HGETALL', KEYS[1])
LUA,
            ['stream:run:'.$runId],
            [],
        );
        $fields = self::fields(is_array($rows) ? $rows : []);

        return [
            'state' => $fields['state'] ?? '',
            'deadline' => isset($fields['deadline']) && ctype_digit($fields['deadline']) ? (int) $fields['deadline'] : 0,
            'incarnation' => $fields['incarnation'] ?? '',
        ];
    }

    public function failRun(string $runId, string $expectedState, string $frame): bool
    {
        $seq = $this->nextSeq($runId);
        $result = $this->redis->eval(
            <<<'LUA'
if redis.call('HGET', KEYS[1], 'state') ~= ARGV[1] then
  return 0
end
redis.call('HSET', KEYS[1], 'state', 'failed')
redis.call('EXPIRE', KEYS[1], '300')
redis.call('XADD', KEYS[2], ARGV[2], 'kind', 'terminal', 'frame', ARGV[3])
redis.call('EXPIRE', KEYS[2], '300')
redis.call('SREM', KEYS[3], ARGV[4])
return 1
LUA,
            ['stream:run:'.$runId, 'chatrun:'.$runId.':stream', self::OPEN],
            [$expectedState, '0-'.$seq, $frame, $runId],
        );

        return 1 === (int) $result;
    }

    public function reap(int $now): int
    {
        $failed = 0;
        foreach ($this->openRunIds() as $runId) {
            $state = $this->runState($runId);
            if (!RunReapDecision::shouldFail($state['state'], $state['deadline'], $now, $this->heartbeatAlive($state['incarnation']))) {
                continue;
            }
            $frame = 'queued' === $state['state'] ? PumpFrames::DID_NOT_START : PumpFrames::LOST_PUMP;
            if ($this->failRun($runId, $state['state'], $frame)) {
                ++$failed;
            }
        }

        return $failed;
    }

    private function nextSeq(string $runId): int
    {
        $frames = $this->framesAfter($runId, 0);
        $last = 0;
        foreach ($frames as $frame) {
            $last = max($last, $frame->seq);
        }

        return $last + 1;
    }

    /**
     * @return array{id: string, job: string, run: string}|null
     */
    private function readGroup(string $script, string $consumer): ?array
    {
        $row = $this->redis->eval($script, [self::JOBS], [$consumer]);
        if (!is_array($row) || !isset($row[0], $row[1], $row[2])) {
            return null;
        }

        return [
            'id' => (string) $row[0],
            'job' => (string) $row[1],
            'run' => (string) $row[2],
        ];
    }

    /**
     * @param array<mixed> $rows
     *
     * @return list<RunFrame>
     */
    private static function frames(array $rows): array
    {
        $frames = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row[0])) {
                continue;
            }
            $id = (string) $row[0];
            $seq = self::seqOf($id);
            if (null === $seq) {
                continue;
            }
            $fields = self::fields(is_array($row[1] ?? null) ? $row[1] : []);
            $frames[] = new RunFrame($seq, $fields['kind'] ?? 'frame', $fields['frame'] ?? '');
        }

        return $frames;
    }

    /**
     * @param array<mixed> $raw
     *
     * @return array<string, string>
     */
    private static function fields(array $raw): array
    {
        if ([] === $raw || array_is_list($raw)) {
            $fields = [];
            $count = count($raw);
            for ($i = 0; $i + 1 < $count; $i += 2) {
                $key = $raw[$i];
                if (is_string($key)) {
                    $fields[$key] = is_string($raw[$i + 1]) ? $raw[$i + 1] : '';
                }
            }

            return $fields;
        }
        $fields = [];
        foreach ($raw as $key => $value) {
            if (is_string($key)) {
                $fields[$key] = is_string($value) ? $value : '';
            }
        }

        return $fields;
    }

    private static function seqOf(string $id): ?int
    {
        if (1 !== preg_match('/^0-(\d+)$/', $id, $match)) {
            return null;
        }
        $seq = (int) $match[1];

        return $seq > 0 ? $seq : null;
    }
}
