<?php

declare(strict_types=1);

namespace App\Service;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWKSet;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\ES384;
use Jose\Component\Signature\Algorithm\ES512;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Algorithm\RS384;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * JWT Validator for OIDC tokens.
 *
 * Validates JWT signatures using JWKS from OIDC providers (Keycloak, etc.).
 *
 * Note: Not marked as 'final' to allow mocking in tests.
 */
class JwtValidator
{
    private JWSVerifier $jwsVerifier;
    private CompactSerializer $serializer;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
        // Supported signature algorithms (from Keycloak discovery)
        $algorithmManager = new AlgorithmManager([
            new RS256(),
            new RS384(),
            new RS512(),
            new ES256(),
            new ES384(),
            new ES512(),
        ]);

        $this->jwsVerifier = new JWSVerifier($algorithmManager);
        $this->serializer = new CompactSerializer();
    }

    /**
     * Validate JWT token from OIDC provider.
     *
     * @return array<string, mixed>|null Token claims if valid, null otherwise
     */
    public function validateToken(string $token, string $jwksUri, string $expectedIssuer, ?string $expectedAudience = null): ?array
    {
        try {
            // 1. Parse JWT
            $jws = $this->serializer->unserialize($token);

            // 2. Get JWKS (cached)
            $jwkSet = $this->getJwkSet($jwksUri);

            // 3. Verify signature
            $isValid = $this->jwsVerifier->verifyWithKeySet($jws, $jwkSet, 0);

            if (!$isValid) {
                $this->logger->warning('JWT signature verification failed');

                return null;
            }

            // 4. Get claims
            $payload = $jws->getPayload();
            if (!$payload) {
                return null;
            }

            $claims = json_decode($payload, true);
            if (!is_array($claims)) {
                return null;
            }

            // 5. Validate claims
            if (!$this->validateClaims($claims, $expectedIssuer, $expectedAudience)) {
                return null;
            }

            return $claims;
        } catch (\Exception $e) {
            $this->logger->error('JWT validation error', [
                'error' => $e->getMessage(),
                'jwks_uri' => $jwksUri,
                'expected_issuer' => $expectedIssuer,
                // Note: Do NOT log token or stack trace - may expose sensitive data
            ]);

            return null;
        }
    }

    /**
     * Fetch and cache JWKS from OIDC provider.
     */
    private function getJwkSet(string $jwksUri): JWKSet
    {
        return $this->cache->get(
            'oidc_jwks_'.md5($jwksUri),
            function (ItemInterface $item) use ($jwksUri): JWKSet {
                // Cache for 1 hour (JWKS rarely changes)
                $item->expiresAfter(3600);

                $this->logger->debug('Fetching JWKS', ['uri' => $jwksUri]);

                $response = $this->httpClient->request('GET', $jwksUri);
                $jwksData = $response->toArray();

                return JWKSet::createFromKeyData($jwksData);
            }
        );
    }

    /**
     * Validate JWT claims (exp, iss, aud, nbf).
     *
     * @param array<string, mixed> $claims
     */
    private function validateClaims(array $claims, string $expectedIssuer, ?string $expectedAudience): bool
    {
        $now = time();

        // Check expiration (exp)
        if (isset($claims['exp']) && $claims['exp'] < $now) {
            $this->logger->debug('JWT expired', ['exp' => $claims['exp'], 'now' => $now]);

            return false;
        }

        // Check not before (nbf)
        if (isset($claims['nbf']) && $claims['nbf'] > $now) {
            $this->logger->debug('JWT not yet valid', ['nbf' => $claims['nbf'], 'now' => $now]);

            return false;
        }

        // Check issuer (iss)
        if (!isset($claims['iss']) || $claims['iss'] !== $expectedIssuer) {
            $this->logger->warning('JWT issuer mismatch', [
                'expected' => $expectedIssuer,
                'actual' => $claims['iss'] ?? 'missing',
            ]);

            return false;
        }

        // Check audience (aud) - optional
        if ($expectedAudience && isset($claims['aud'])) {
            $audiences = is_array($claims['aud']) ? $claims['aud'] : [$claims['aud']];
            if (!in_array($expectedAudience, $audiences, true)) {
                $this->logger->warning('JWT audience mismatch', [
                    'expected' => $expectedAudience,
                    'actual' => $claims['aud'],
                ]);

                return false;
            }
        }

        return true;
    }
}
