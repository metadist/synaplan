<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Service\Infrastructure\RedisService;
use App\Service\PlatformLink\Exception\PlatformLinkLimitException;

/**
 * One-time link codes exchanged by a registered partner instance for a scoped
 * per-user API key. Codes are never typed by a human (bin2hex 16 bytes).
 *
 * Redis layout (under RedisService's prefix):
 *   platform_link:code:{CODE}   -> JSON payload, TTL 300s
 *   platform_link:hour:{userId} -> counter, TTL 3600s (20 / hour)
 */
final readonly class LinkCodeService
{
    public const CODE_TTL_SECONDS = 300;
    private const MAX_PER_HOUR = 20;
    private const CODE_PREFIX = 'platform_link:code:';
    private const HOUR_PREFIX = 'platform_link:hour:';

    public function __construct(
        private RedisService $redis,
    ) {
    }

    /**
     * @param array{userId: int, instanceId: string, externalId: string, redirectUri: string, withMemories?: bool} $payload
     *
     * @return array{code: string, expiresAt: int}
     */
    public function create(array $payload): array
    {
        $userId = (int) $payload['userId'];
        $hourKey = self::HOUR_PREFIX.$userId;
        $created = $this->redis->increment($hourKey);
        if (1 === $created) {
            $this->redis->expire($hourKey, 3600);
        }
        if (null !== $created && $created > self::MAX_PER_HOUR) {
            throw new PlatformLinkLimitException('Too many connection codes created in the last hour. Please wait and try again.');
        }

        $code = bin2hex(random_bytes(16));
        $expiresAt = time() + self::CODE_TTL_SECONDS;
        $stored = json_encode([
            'userId' => $userId,
            'instanceId' => $payload['instanceId'],
            'externalId' => $payload['externalId'],
            'redirectUri' => $payload['redirectUri'],
            'withMemories' => (bool) ($payload['withMemories'] ?? false),
            'expiresAt' => $expiresAt,
        ], \JSON_UNESCAPED_SLASHES);
        if (false === $stored) {
            throw new \RuntimeException('Failed to encode link-code payload.');
        }

        $this->redis->set(self::CODE_PREFIX.$code, $stored, self::CODE_TTL_SECONDS);

        return ['code' => $code, 'expiresAt' => $expiresAt];
    }

    /**
     * Consume a code once. Returns null for unknown, expired, or already-used codes.
     * The read and the delete are one Redis command (GETDEL) so two exchanges
     * racing on the same code cannot both succeed (C5).
     *
     * @return array{userId: int, instanceId: string, externalId: string, redirectUri: string, withMemories: bool, expiresAt: int}|null
     */
    public function consume(string $code): ?array
    {
        $code = strtolower(trim($code));
        if ('' === $code || 1 !== preg_match('/^[0-9a-f]{32}$/', $code)) {
            return null;
        }

        $raw = $this->redis->getAndDelete(self::CODE_PREFIX.$code);
        if (null === $raw) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded) || !isset($decoded['userId'], $decoded['instanceId'], $decoded['externalId'])) {
            return null;
        }

        $expiresAt = (int) ($decoded['expiresAt'] ?? 0);
        if ($expiresAt > 0 && $expiresAt < time()) {
            return null;
        }

        $userId = (int) $decoded['userId'];
        if ($userId <= 0) {
            return null;
        }

        return [
            'userId' => $userId,
            'instanceId' => (string) $decoded['instanceId'],
            'externalId' => (string) $decoded['externalId'],
            'redirectUri' => (string) ($decoded['redirectUri'] ?? ''),
            'withMemories' => (bool) ($decoded['withMemories'] ?? false),
            'expiresAt' => $expiresAt,
        ];
    }
}
