<?php

declare(strict_types=1);

namespace App\Service\Voice;

use App\AI\Catalog\ProviderCatalog;
use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use App\Exception\VoiceLiveRejectedException;
use App\Repository\ConfigRepository;
use App\Service\Infrastructure\RedisService;

/**
 * How many provider sockets this install may hold open at once.
 * Redis down refuses a new conversation rather than ignoring the cap.
 *
 * The default per provider is the descriptor's `liveVoiceSessions`; an
 * operator overrides it with `LIVE_VOICE.CONCURRENCY_<PROVIDER>`. The
 * OpenAI-compatible realtime socket is addressed as
 * {@see VoiceLiveCompatibleEndpoint::PROVIDER} by the sidecar and the
 * settings, so that name maps onto the `openaicompatible` descriptor.
 */
final readonly class VoiceLiveConcurrency
{
    private const FALLBACK_SESSIONS = 10;

    public function __construct(
        private RedisService $redis,
        private ConfigRepository $config,
        private ProviderCatalog $catalog,
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

        $descriptor = VoiceLiveCompatibleEndpoint::PROVIDER === $this->name($provider)
            ? OpenAiCompatibleEndpointRegistry::PROVIDER_NAME
            : $this->name($provider);

        return $this->catalog->find($descriptor)->liveVoiceSessions ?? self::FALLBACK_SESSIONS;
    }

    private function name(string $provider): string
    {
        $name = strtolower(trim($provider));

        return '' === $name ? 'unknown' : $name;
    }
}
