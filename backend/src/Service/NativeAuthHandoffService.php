<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Short-lived, signed handoff token for native (mobile) OAuth.
 *
 * The native OAuth flow runs in the system browser and returns to the app via a
 * custom-scheme deep link. We must hand the freshly authenticated user back to
 * the app without exposing long-lived credentials in that URL (deep links land
 * in browser history and could be observed by a scheme-squatting app).
 *
 * Instead the OAuth callback emits a single-purpose, 120s-TTL HMAC token that
 * embeds only the user id. The app immediately exchanges it at
 * `POST /api/v1/auth/native/exchange` for real access/refresh tokens, so the
 * long-lived tokens never travel through the browser/deep-link.
 *
 * Signed with HMAC over the app secret (mirroring OAuthStateService). The token
 * is additionally made single-use: the embedded nonce is recorded in a
 * short-TTL cache on first successful validation, so a captured handoff URL
 * cannot be replayed to mint a second set of tokens within the TTL window
 * (Epic 7 hardening — defends against scheme-squatting apps observing the URL).
 */
final readonly class NativeAuthHandoffService
{
    private const TTL = 120;
    private const PURPOSE = 'native_oauth_handoff';
    private const NONCE_CACHE_PREFIX = 'native_handoff_nonce_';

    public function __construct(
        private LoggerInterface $logger,
        private CacheItemPoolInterface $cache,
        private string $appSecret,
    ) {
    }

    /**
     * Generate a signed handoff token for the given user.
     */
    public function generate(User $user): string
    {
        $payload = [
            'uid' => $user->getId(),
            'purpose' => self::PURPOSE,
            'nonce' => bin2hex(random_bytes(16)),
            'created_at' => time(),
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $json, $this->appSecret);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=').'.'.$signature;
    }

    /**
     * Validate a handoff token and return the embedded user id, or null on any
     * failure (bad format, bad signature, wrong purpose, expired).
     */
    public function validate(string $token): ?int
    {
        $parts = explode('.', $token);
        if (2 !== count($parts)) {
            return null;
        }

        [$encodedPayload, $signature] = $parts;

        $json = base64_decode(strtr($encodedPayload, '-_', '+/'), true);
        if (false === $json || '' === $json) {
            return null;
        }

        if (!hash_equals(hash_hmac('sha256', $json, $this->appSecret), $signature)) {
            $this->logger->warning('Native handoff token signature verification failed');

            return null;
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (self::PURPOSE !== ($payload['purpose'] ?? null)) {
            return null;
        }

        $createdAt = $payload['created_at'] ?? null;
        if (!is_int($createdAt) || (time() - $createdAt) > self::TTL) {
            $this->logger->warning('Native handoff token expired');

            return null;
        }

        $uid = $payload['uid'] ?? null;
        if (!is_int($uid)) {
            return null;
        }

        // Enforce single-use: reject any token whose nonce we have already seen,
        // then record it for the remaining lifetime of the token so a replay of
        // the same handoff URL within the TTL window cannot mint fresh tokens.
        $nonce = $payload['nonce'] ?? null;
        if (!is_string($nonce) || '' === $nonce) {
            return null;
        }
        if (!$this->consumeNonce($nonce, $createdAt)) {
            $this->logger->warning('Native handoff token replay detected');

            return null;
        }

        return $uid;
    }

    /**
     * Atomically claim a nonce. Returns false when it was already consumed.
     */
    private function consumeNonce(string $nonce, int $createdAt): bool
    {
        $item = $this->cache->getItem(self::NONCE_CACHE_PREFIX.hash('sha256', $nonce));
        if ($item->isHit()) {
            return false;
        }

        // Keep the marker only until the token itself would expire anyway.
        $remaining = self::TTL - (time() - $createdAt);
        $item->set(true)->expiresAfter(max(1, $remaining));
        $this->cache->save($item);

        return true;
    }
}
