<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

use App\Entity\PlatformInstance;
use App\Repository\OAuthTokenRepository;
use App\Repository\PlatformInstanceRepository;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

/**
 * Client authentication by private_key_jwt (RFC 7523, OIDC Core 9): the
 * cloud signs a short assertion with the key it registered. Synaplan keeps
 * only public keys, so there is no client secret to steal.
 *
 * The assertion must name the client as iss and sub, one of Synaplan's
 * endpoints or its issuer as aud, carry a jti never seen before, and expire
 * within five minutes.
 */
final readonly class ClientAssertionVerifier
{
    public const TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    private const MAX_LIFETIME = 300;
    private const ALGORITHMS = ['RS256', 'ES256'];

    public function __construct(
        private PlatformInstanceRepository $instances,
        private OAuthTokenRepository $tokens,
        private string $issuer,
    ) {
    }

    /**
     * The client the assertion proves, or null.
     *
     * @param list<string> $audiences the endpoint URLs accepted besides the issuer
     * @param bool         $pendingOk whether a client still waiting for approval may authenticate
     */
    public function verify(string $assertion, array $audiences, bool $pendingOk = false): ?PlatformInstance
    {
        $clientId = $this->unverifiedIssuer($assertion);
        if (null === $clientId) {
            return null;
        }
        $instance = $this->instances->findByInstanceId($clientId);
        $allowed = [PlatformInstance::STATUS_ACTIVE];
        if ($pendingOk) {
            $allowed[] = PlatformInstance::STATUS_PENDING;
        }
        if (!$instance instanceof PlatformInstance || !in_array($instance->getStatus(), $allowed, true)) {
            return null;
        }
        $jwks = $instance->getJwks();
        if (null === $jwks) {
            return null;
        }
        try {
            $keys = JWK::parseKeySet($jwks, 'RS256');
            $claims = (array) JWT::decode($assertion, $keys);
        } catch (\Throwable) {
            return null;
        }
        $header = $this->header($assertion);
        if (!in_array($header['alg'] ?? null, self::ALGORITHMS, true)) {
            return null;
        }
        $aud = $claims['aud'] ?? null;
        $auds = is_array($aud) ? $aud : [$aud];
        $accepted = array_merge([rtrim($this->issuer, '/')], array_map(static fn (string $a): string => rtrim($a, '/'), $audiences));
        $exp = $claims['exp'] ?? null;
        $jti = $claims['jti'] ?? null;
        if (($claims['iss'] ?? null) !== $clientId || ($claims['sub'] ?? null) !== $clientId
            || [] === array_intersect(array_map(static fn ($a): string => rtrim((string) $a, '/'), $auds), $accepted)
            || !is_int($exp) || $exp > time() + self::MAX_LIFETIME
            || !is_string($jti) || '' === $jti) {
            return null;
        }
        if (!$this->tokens->claimAssertion($clientId, $jti, $exp)) {
            return null;
        }

        return $instance;
    }

    private function unverifiedIssuer(string $assertion): ?string
    {
        $parts = explode('.', $assertion);
        if (3 !== count($parts)) {
            return null;
        }
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        $iss = is_array($payload) ? ($payload['iss'] ?? null) : null;

        return is_string($iss) && '' !== $iss ? $iss : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function header(string $assertion): array
    {
        $decoded = json_decode((string) base64_decode(strtr(explode('.', $assertion)[0], '-_', '+/'), true), true);

        return is_array($decoded) ? $decoded : [];
    }
}
