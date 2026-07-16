<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Firebase\JWT\JWT;

/**
 * Builds the OAuth "client secret" for Apple's token endpoint.
 *
 * Unlike Google/GitHub, Apple does not issue a static client secret: it must be
 * a short-lived ES256 JWT signed with the Sign-in-with-Apple private key (.p8),
 * with the Team ID as issuer and the Services ID as subject. This is required
 * for the web/Android authorization-code exchange
 * ({@see \App\Controller\AppleAuthController::callback()}).
 *
 * The native iOS flow verifies the identity token directly and does NOT need a
 * client secret, so this generator is only exercised by the web OAuth path.
 */
final readonly class AppleClientSecretGenerator
{
    private const AUDIENCE = 'https://appleid.apple.com';

    /**
     * TTL kept well under Apple's 6-month maximum; a fresh secret is generated
     * per token exchange, so it never needs to be cached or rotated.
     */
    private const TTL = 3600;

    public function __construct(
        private string $teamId,
        private string $keyId,
        private string $clientId,
        private string $privateKey,
    ) {
    }

    /**
     * Whether all four inputs required to sign a client secret are present.
     */
    public function isConfigured(): bool
    {
        return '' !== $this->teamId
            && '' !== $this->keyId
            && '' !== $this->clientId
            && '' !== $this->normalizedPrivateKey();
    }

    /**
     * @throws \RuntimeException when the private key / identifiers are missing
     */
    public function generate(): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Apple Sign-In is not fully configured: APPLE_TEAM_ID, APPLE_KEY_ID, APPLE_CLIENT_ID and APPLE_PRIVATE_KEY are all required for the web OAuth flow.');
        }

        $now = time();
        $payload = [
            'iss' => $this->teamId,
            'iat' => $now,
            'exp' => $now + self::TTL,
            'aud' => self::AUDIENCE,
            'sub' => $this->clientId,
        ];

        return JWT::encode($payload, $this->normalizedPrivateKey(), 'ES256', $this->keyId);
    }

    /**
     * The .p8 is a PKCS#8 PEM. Allow it to be supplied in an env file as a
     * single line with literal "\n" escapes (common in container secrets) by
     * expanding those back into real newlines before OpenSSL parses it.
     */
    private function normalizedPrivateKey(): string
    {
        $key = trim($this->privateKey);

        if (str_contains($key, '\n')) {
            $key = str_replace('\n', "\n", $key);
        }

        return $key;
    }
}
