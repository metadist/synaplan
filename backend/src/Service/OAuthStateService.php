<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * OAuth State Service for CSRF protection in OAuth flows.
 *
 * This service generates and validates self-contained, signed state tokens
 * that don't require server-side session storage. This is more reliable
 * for stateless APIs and avoids issues with:
 * - Session cookie SameSite restrictions on OAuth redirects
 * - Session persistence across container restarts
 * - Stateless firewall configurations
 *
 * The state token contains:
 * - A random nonce for uniqueness
 * - Creation timestamp for expiration
 * - Provider identifier for validation
 */
final readonly class OAuthStateService
{
    // State tokens are valid for 10 minutes (enough for OAuth flow)
    private const STATE_TTL = 600;

    public function __construct(
        private LoggerInterface $logger,
        private string $appSecret,
    ) {
    }

    /**
     * Generate a signed OAuth state token.
     *
     * @param string $provider   OAuth provider identifier (e.g., 'google', 'github')
     * @param array  $extraData  Additional data to include in the state (e.g., PKCE verifier)
     *
     * @return string Base64-encoded signed state token
     */
    public function generateState(string $provider, array $extraData = []): string
    {
        $payload = array_merge($extraData, [
            'nonce' => bin2hex(random_bytes(16)),
            'provider' => $provider,
            'created_at' => time(),
        ]);

        return $this->encodeState($payload);
    }

    /**
     * Validate and decode an OAuth state token.
     *
     * Returns the decoded payload on success (including any extra data),
     * or null on validation failure.
     *
     * @param string $state    The state token to validate
     * @param string $provider Expected OAuth provider
     *
     * @return array|null Decoded payload on success, null on failure
     */
    public function validateState(string $state, string $provider): ?array
    {
        $payload = $this->decodeState($state);

        if (!$payload) {
            $this->logger->warning('OAuth state validation failed: invalid token format or signature');

            return null;
        }

        // Check provider matches
        if (!isset($payload['provider']) || $payload['provider'] !== $provider) {
            $this->logger->warning('OAuth state validation failed: provider mismatch', [
                'expected' => $provider,
                'received' => $payload['provider'] ?? 'null',
            ]);

            return null;
        }

        // Check expiration
        if (!isset($payload['created_at']) || (time() - $payload['created_at']) > self::STATE_TTL) {
            $this->logger->warning('OAuth state validation failed: token expired', [
                'created_at' => $payload['created_at'] ?? 'null',
                'current_time' => time(),
            ]);

            return null;
        }

        return $payload;
    }

    /**
     * Encode state payload with signature.
     */
    private function encodeState(array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = $this->sign($json);

        // Use URL-safe base64 encoding
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=').'.'.$signature;
    }

    /**
     * Decode and verify state token.
     */
    private function decodeState(string $state): ?array
    {
        $parts = explode('.', $state);
        if (2 !== count($parts)) {
            return null;
        }

        [$encodedPayload, $signature] = $parts;

        // Decode URL-safe base64
        $json = base64_decode(strtr($encodedPayload, '-_', '+/'), true);

        if (!$json) {
            return null;
        }

        // Verify signature
        if (!hash_equals($this->sign($json), $signature)) {
            $this->logger->warning('OAuth state signature verification failed');

            return null;
        }

        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Sign data with app secret.
     */
    private function sign(string $data): string
    {
        return hash_hmac('sha256', $data, $this->appSecret);
    }
}
