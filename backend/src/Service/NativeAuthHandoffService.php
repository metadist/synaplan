<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
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
 * Stateless (HMAC over the app secret), mirroring OAuthStateService — no server
 * storage required. NOTE (Epic 7 hardening): make it truly single-use by
 * tracking the nonce in a short-TTL cache to defeat replay within the window.
 */
final readonly class NativeAuthHandoffService
{
    private const TTL = 120;
    private const PURPOSE = 'native_oauth_handoff';

    public function __construct(
        private LoggerInterface $logger,
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

        return is_int($uid) ? $uid : null;
    }
}
