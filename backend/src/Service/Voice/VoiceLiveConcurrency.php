<?php

declare(strict_types=1);

namespace App\Service\Voice;

use App\Exception\VoiceLiveRejectedException;
use App\Repository\ConfigRepository;
use App\Service\Infrastructure\RedisService;

/**
 * How many provider sockets this install may hold open at once.
 * Redis down refuses a new conversation rather than ignoring the cap.
 */
final readonly class VoiceLiveConcurrency
{
    /** @var array<string, int> */
    private const DEFAULTS = [
        'openai' => 50,
        'google' => 100,
        'xai' => 10,
        'compatible' => 4,
    ];

    public function __construct(
        private RedisService $redis,
        private ConfigRepository $config,
    ) {
    }

    public function acquire(string $provider): void
    {
        $key = $this->key($provider);
        $count = $this->redis->increment($key);
        if (null === $count) {
            throw new VoiceLiveRejectedException(503, ['error' => 'Live voice is temporarily unavailable.', 'code' => 'VOICE_CAPACITY']);
        }
        $this->redis->expire($key, 7200);
        if ($count > $this->cap($provider)) {
            $this->redis->increment($key, -1);
            throw new VoiceLiveRejectedException(503, ['error' => 'Live voice is at capacity. Try again shortly.', 'code' => 'VOICE_CAPACITY']);
        }
    }

    public function release(string $provider): void
    {
        $count = $this->redis->increment($this->key($provider), -1);
        if (null !== $count && $count < 0) {
            $this->redis->increment($this->key($provider), 1);
        }
    }

    private function key(string $provider): string
    {
        return 'voice:concurrency:'.$this->name($provider);
    }

    private function cap(string $provider): int
    {
        $setting = 'CONCURRENCY_'.strtoupper($this->name($provider));
        $raw = $this->config->getValue(0, 'LIVE_VOICE', $setting);
        if (null !== $raw && is_numeric($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        return self::DEFAULTS[$this->name($provider)] ?? 10;
    }

    private function name(string $provider): string
    {
        $name = strtolower(trim($provider));

        return '' === $name ? 'unknown' : $name;
    }
}
