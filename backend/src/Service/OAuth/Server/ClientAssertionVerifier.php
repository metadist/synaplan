<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

use App\Entity\PlatformInstance;
use App\Repository\OAuthTokenRepository;
use App\Repository\PlatformInstanceRepository;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;

/**
 * Client authentication by private_key_jwt (RFC 7523, OIDC Core 9): the
 * cloud signs a short assertion with the key it registered. Synaplan keeps
 * only public keys, so there is no client secret to steal.
 *
 * The assertion must name the client as iss and sub, one of Synaplan's
 * endpoints or its issuer as aud, carry a jti never seen before, and expire
 * within five minutes. The cloud's clock may be a minute off.
 */
final readonly class ClientAssertionVerifier
{
    public const TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    private const MAX_LIFETIME = 300;
    /** Seconds a cloud's clock may differ from Synaplan's. */
    public const CLOCK_SKEW = 60;
    private const ALGORITHMS = ['RS256', 'ES256'];
    private const PURGE_ODDS = 50;

    public function __construct(
        private PlatformInstanceRepository $instances,
        private OAuthTokenRepository $tokens,
        private string $issuer,
    ) {
    }

    /**
     * The client the assertion proves, or null.
     *
     * @param list<string> $audiences   the endpoint URLs accepted as audience
     * @param bool         $pendingOk   whether a client still waiting for approval may authenticate
     * @param bool         $allowIssuer whether the bare issuer is also an audience. Token, revocation and pushed requests accept it. Reading or changing a registration does not: an assertion made for one of those must not replace the client's keys.
     */
    public function verify(string $assertion, array $audiences, bool $pendingOk = false, bool $allowIssuer = true): ?PlatformInstance
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
        // The library checks the signature before the times; its time
        // checks allow no skew, so a refused time is judged again below.
        try {
            $claims = (array) JWT::decode($assertion, ClientKeys::parse($jwks));
        } catch (ExpiredException|BeforeValidException $e) {
            $claims = (array) $e->getPayload();
        } catch (\Throwable) {
            return null;
        }
        $header = $this->header($assertion);
        if (!in_array($header['alg'] ?? null, self::ALGORITHMS, true)) {
            return null;
        }
        $aud = $claims['aud'] ?? null;
        $auds = is_array($aud) ? $aud : [$aud];
        $accepted = array_map(static fn (string $a): string => rtrim($a, '/'), $audiences);
        if ($allowIssuer) {
            $accepted[] = rtrim($this->issuer, '/');
        }
        $exp = $claims['exp'] ?? null;
        $jti = $claims['jti'] ?? null;
        if (($claims['iss'] ?? null) !== $clientId || ($claims['sub'] ?? null) !== $clientId
            || [] === array_intersect(array_map(static fn ($a): string => rtrim((string) $a, '/'), $auds), $accepted)
            || !is_int($exp) || !$this->timely($exp, $claims['iat'] ?? null, $claims['nbf'] ?? null)
            || !is_string($jti) || '' === $jti) {
            return null;
        }
        // The jti is kept as long as the assertion could still be accepted.
        if (!$this->tokens->claimAssertion($clientId, $jti, $exp + self::CLOCK_SKEW)) {
            return null;
        }
        // Nothing schedules a sweep of the token table, so one in every
        // PURGE_ODDS authenticated calls removes what has expired.
        if (0 === random_int(0, self::PURGE_ODDS - 1)) {
            $this->tokens->purgeExpired(time());
        }

        return $instance;
    }

    /**
     * Whether the assertion is valid now, allowing the cloud's clock to be
     * CLOCK_SKEW seconds off either way.
     */
    private function timely(int $exp, mixed $iat, mixed $nbf): bool
    {
        $now = time();
        if ($exp + self::CLOCK_SKEW < $now || $exp > $now + self::MAX_LIFETIME + self::CLOCK_SKEW) {
            return false;
        }
        foreach ([$iat, $nbf] as $notBefore) {
            if (null !== $notBefore && (!is_int($notBefore) || $notBefore > $now + self::CLOCK_SKEW)) {
                return false;
            }
        }

        return true;
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
