<?php

declare(strict_types=1);

namespace App\Realtime\Token;

use Firebase\JWT\JWT;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

/**
 * Mints HMAC JWTs that browsers present to Centrifugo.
 *
 * Two token kinds:
 *
 *   * Connection token — proves identity for the WS upgrade itself
 *     (`sub` = stable user/visitor id, plus our `kind` claim).
 *   * Subscription token — proves authorisation for ONE specific channel
 *     (Centrifugo verifies channel binding server-side).
 *
 * Tokens are short-lived (60s default) and re-minted by the frontend on
 * every reconnect/refresh. The HMAC secret is shared with Centrifugo via
 * `CENTRIFUGO_TOKEN_HMAC_SECRET_KEY`.
 *
 * IMPORTANT: never reuse tokens across users — the cache is per-call only.
 */
final readonly class RealtimeTokenService
{
    private const ALGO = 'HS256';
    private const DEFAULT_TTL_SECONDS = 60;

    public function __construct(
        private string $hmacSecret,
        private ClockInterface $clock = new Clock(),
        private int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {
    }

    /**
     * @param array<string, mixed> $info optional `info` claim (display name etc.)
     */
    public function issueConnectionToken(string $subject, array $info = []): string
    {
        $now = $this->clock->now()->getTimestamp();

        $claims = [
            'sub' => $subject,
            'iat' => $now,
            'exp' => $now + $this->ttlSeconds,
            'kind' => 'connection',
        ];

        if ([] !== $info) {
            $claims['info'] = $info;
        }

        return $this->encode($claims);
    }

    /**
     * @param array<string, mixed> $info optional channel-scoped metadata
     */
    public function issueSubscriptionToken(string $subject, string $channel, array $info = []): string
    {
        $now = $this->clock->now()->getTimestamp();

        $claims = [
            'sub' => $subject,
            'channel' => $channel,
            'iat' => $now,
            'exp' => $now + $this->ttlSeconds,
            'kind' => 'subscription',
        ];

        if ([] !== $info) {
            $claims['info'] = $info;
        }

        return $this->encode($claims);
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function encode(array $claims): string
    {
        if ('' === trim($this->hmacSecret)) {
            throw new \LogicException('REALTIME_TOKEN_SECRET is empty — refusing to mint unsigned token');
        }

        return JWT::encode($claims, $this->hmacSecret, self::ALGO);
    }
}
