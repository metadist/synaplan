<?php

declare(strict_types=1);

namespace App\Service\Voice;

use App\Exception\VoiceLiveRejectedException;
use App\Service\Infrastructure\RedisService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * One-time ticket for the voice socket. 30 seconds, bound to the session.
 * Redis down rejects the ticket: a token we cannot mark as used is not single-use.
 */
final readonly class VoiceLiveTicketService
{
    private const TTL_SECONDS = 30;

    public function __construct(
        private RedisService $redis,
        private string $secret,
    ) {
    }

    public function mint(int $userId, int $sessionId): string
    {
        if ('' === $this->secret || strlen($this->secret) < 32 || $userId <= 0 || $sessionId <= 0) {
            throw new VoiceLiveRejectedException(503, ['error' => 'Live voice is temporarily unavailable.', 'code' => 'VOICE_UNAVAILABLE']);
        }

        return JWT::encode([
            'sub' => $userId,
            'sid' => $sessionId,
            'jti' => bin2hex(random_bytes(16)),
            'exp' => time() + self::TTL_SECONDS,
        ], $this->secret, 'HS256');
    }

    /**
     * @return array{userId: int, sessionId: int}
     */
    public function redeem(string $token): array
    {
        if (strlen($this->secret) < 32 || '' === $token) {
            throw $this->unauthorized();
        }

        try {
            $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (\Throwable) {
            throw $this->unauthorized();
        }

        $jti = isset($decoded->jti) && is_string($decoded->jti) ? $decoded->jti : '';
        $sessionId = isset($decoded->sid) && is_numeric($decoded->sid) ? (int) $decoded->sid : 0;
        $userId = isset($decoded->sub) && is_numeric($decoded->sub) ? (int) $decoded->sub : 0;
        if ('' === $jti || $sessionId <= 0 || $userId <= 0) {
            throw $this->unauthorized();
        }

        if (!$this->redis->setIfAbsent('voice:ticket:'.$jti, '1', self::TTL_SECONDS)) {
            throw $this->unauthorized();
        }

        return ['userId' => $userId, 'sessionId' => $sessionId];
    }

    private function unauthorized(): VoiceLiveRejectedException
    {
        return new VoiceLiveRejectedException(401, ['error' => 'Unauthorized']);
    }
}
