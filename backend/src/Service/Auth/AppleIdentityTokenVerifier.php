<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Verifies an Apple "identity token" (a signed JWT) against Apple's published
 * public keys (JWKS).
 *
 * Two callers rely on this:
 *  - the web/Android OAuth callback, which receives the identity token from the
 *    token-endpoint response (audience = Services ID);
 *  - the native iOS flow, which receives the identity token straight from the
 *    Sign-in-with-Apple system UI via the Capacitor plugin (audience = the app
 *    bundle id).
 *
 * Both audiences are accepted; issuer, audience, signature and expiry are all
 * enforced. The JWKS is cached for an hour to avoid fetching it on every login.
 */
final class AppleIdentityTokenVerifier
{
    private const ISSUER = 'https://appleid.apple.com';
    private const JWKS_URL = 'https://appleid.apple.com/auth/keys';
    private const JWKS_CACHE_KEY = 'apple_signin_jwks';
    private const JWKS_TTL = 3600;
    private const CLOCK_SKEW_LEEWAY = 60;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $webClientId,
        private readonly string $appBundleId,
    ) {
    }

    /**
     * Verify the token and return the claims we care about.
     *
     * @return array{sub: string, email: ?string, emailVerified: bool, isPrivateEmail: bool}
     *
     * @throws \RuntimeException on any verification failure
     */
    public function verify(string $identityToken): array
    {
        JWT::$leeway = self::CLOCK_SKEW_LEEWAY;

        try {
            $decoded = JWT::decode($identityToken, $this->getKeys());
        } catch (\Throwable $e) {
            throw new \RuntimeException('Apple identity token verification failed: '.$e->getMessage(), 0, $e);
        }

        /** @var array<string, mixed> $claims */
        $claims = (array) $decoded;

        if (self::ISSUER !== ($claims['iss'] ?? null)) {
            throw new \RuntimeException('Apple identity token has an unexpected issuer.');
        }

        $aud = $claims['aud'] ?? null;
        if (!is_string($aud) || !in_array($aud, $this->allowedAudiences(), true)) {
            throw new \RuntimeException('Apple identity token has an unexpected audience.');
        }

        $sub = $claims['sub'] ?? null;
        if (!is_string($sub) || '' === $sub) {
            throw new \RuntimeException('Apple identity token is missing a subject.');
        }

        $email = isset($claims['email']) && is_string($claims['email']) && '' !== $claims['email']
            ? $claims['email']
            : null;

        return [
            'sub' => $sub,
            'email' => $email,
            'emailVerified' => filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'isPrivateEmail' => filter_var($claims['is_private_email'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * @return list<string>
     */
    private function allowedAudiences(): array
    {
        return array_values(array_filter(
            [$this->webClientId, $this->appBundleId],
            static fn (string $aud): bool => '' !== $aud,
        ));
    }

    /**
     * @return array<string, \Firebase\JWT\Key>
     */
    private function getKeys(): array
    {
        $item = $this->cache->getItem(self::JWKS_CACHE_KEY);

        if ($item->isHit()) {
            /** @var array{keys: array<int, array<string, mixed>>} $jwks */
            $jwks = $item->get();
        } else {
            $jwks = $this->httpClient->request('GET', self::JWKS_URL)->toArray();
            $item->set($jwks)->expiresAfter(self::JWKS_TTL);
            $this->cache->save($item);
            $this->logger->debug('Fetched Apple Sign-In JWKS');
        }

        return JWK::parseKeySet($jwks);
    }
}
