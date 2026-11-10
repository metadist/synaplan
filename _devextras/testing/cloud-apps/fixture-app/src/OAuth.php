<?php

declare(strict_types=1);

namespace CloudFixture;

/**
 * The fixture as an OAuth2 client of Synaplan, the way a cloud app does it:
 * it registers itself (RFC 7591) with a key pair it made, proves itself with
 * signed client assertions (private_key_jwt, never a secret), connects a
 * person with a pushed authorization request (RFC 9126), the authorization
 * code flow and PKCE, keeps their rotating refresh token, and revokes it
 * when the person goes.
 *
 * Server-to-server calls go to Synaplan's internal address; assertions are
 * addressed to the issuer, which Synaplan accepts for every endpoint.
 */
final class OAuth
{
    private const ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
    private const SCOPE = 'chat files rag cloud:watch';

    /**
     * Register with Synaplan. With the initial access token Synaplan's
     * administrator handed out, the client is active at once; otherwise it
     * may wait for approval.
     *
     * @return array{status: int, body: array<mixed>}
     */
    public static function register(Store $store, string $registrationToken, array $capabilities): array
    {
        $state = self::ensureKey($store);
        $headers = '' !== $registrationToken ? ['Authorization' => 'Bearer '.$registrationToken] : [];
        $answer = Synaplan::from($state)->json('POST', '/oauth/register', [
            'software_id' => $state['client'],
            'software_version' => 'fixture-1',
            'client_uri' => $state['fixture_url'],
            'redirect_uris' => [$state['fixture_url'].'/link/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_method' => 'private_key_jwt',
            'jwks' => ['keys' => [self::publicJwk($state)]],
            'delivery_url' => $state['inbound_url'],
            'app_capabilities' => $capabilities,
            'server_id' => 'cloud-fixture',
        ], $headers);
        if (201 === $answer['status'] && is_string($answer['body']['client_id'] ?? null)) {
            $clientId = $answer['body']['client_id'];
            $store->update(static function (array $s) use ($clientId): array {
                $s['instance'] = ['id' => $clientId];
                $s['links'] = [];

                return $s;
            });
        }

        return $answer;
    }

    /**
     * The client's registration as Synaplan has it (RFC 7592).
     *
     * @return array{status: int, body: array<mixed>}
     */
    public static function configuration(Store $store): array
    {
        $state = $store->state();

        return Synaplan::from($state)->json('GET', '/oauth/register/'.$state['instance']['id'], null, [
            'Authorization' => 'Bearer '.self::assertion($state),
        ]);
    }

    /**
     * Where the person's browser goes to allow this cloud, and the PKCE
     * verifier to keep until the code comes back.
     *
     * The request itself is pushed from here (RFC 9126), signed as the
     * client: Synaplan takes the person's name in this cloud (external_id)
     * from the cloud's server only, never from a browser. The browser
     * carries just the one-time reference Synaplan answers with.
     *
     * @return array{url: string, verifier: string}
     */
    public static function authorizeUrl(array $state, string $person, string $nonce): array
    {
        $verifier = self::b64(random_bytes(32));
        $pushed = Synaplan::from($state)->form('/oauth/par', [
            'response_type' => 'code',
            'redirect_uri' => $state['fixture_url'].'/link/callback',
            'scope' => self::SCOPE,
            'state' => $nonce,
            'code_challenge' => self::b64(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'external_id' => $person,
            'client_assertion_type' => self::ASSERTION_TYPE,
            'client_assertion' => self::assertion($state),
        ]);
        if (201 !== $pushed['status'] || !is_string($pushed['body']['request_uri'] ?? null)) {
            throw new FixtureException(502, 'par_refused', 'Synaplan refused the authorization request: '.($pushed['body']['error'] ?? 'HTTP '.$pushed['status']).'.');
        }

        return [
            'url' => rtrim((string) $state['issuer'], '/').'/oauth/authorize?'.http_build_query([
                'client_id' => $state['instance']['id'],
                'request_uri' => $pushed['body']['request_uri'],
            ]),
            'verifier' => $verifier,
        ];
    }

    /**
     * @return array{status: int, body: array<mixed>}
     */
    public static function exchangeCode(Store $store, string $person, string $code, string $verifier): array
    {
        $state = $store->state();
        $answer = self::tokenRequest($state, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $state['fixture_url'].'/link/callback',
            'code_verifier' => $verifier,
        ]);
        if (200 === $answer['status']) {
            self::remember($store, $person, $answer['body']);
        }

        return $answer;
    }

    /**
     * A current access token for the person, refreshed when it ran out, or ''
     * when the person is not connected (or Synaplan ended the grant).
     */
    public static function accessToken(Store $store, string $person): string
    {
        $link = $store->state()['links'][$person] ?? null;
        if (!is_array($link) || !is_string($link['refresh_token'] ?? null)) {
            return '';
        }
        if (is_string($link['access_token'] ?? null) && (int) ($link['expires_at'] ?? 0) > time() + 30) {
            return $link['access_token'];
        }

        return $store->locked('refresh-'.$person, static function () use ($store, $person): string {
            $state = $store->state();
            $link = $state['links'][$person] ?? null;
            if (!is_array($link)) {
                return '';
            }
            if (is_string($link['access_token'] ?? null) && (int) ($link['expires_at'] ?? 0) > time() + 30) {
                return $link['access_token'];
            }
            $answer = self::tokenRequest($state, ['grant_type' => 'refresh_token', 'refresh_token' => (string) $link['refresh_token']]);
            if (200 !== $answer['status']) {
                self::forget($store, $person);

                return '';
            }
            self::remember($store, $person, $answer['body']);

            return (string) ($answer['body']['access_token'] ?? '');
        });
    }

    /**
     * The person leaves this cloud: end their grant at Synaplan, which ends
     * the link, and forget it here.
     */
    public static function revoke(Store $store, string $person): bool
    {
        $state = $store->state();
        $link = $state['links'][$person] ?? null;
        if (!is_array($link) || !is_string($link['refresh_token'] ?? null)) {
            return false;
        }
        $answer = Synaplan::from($state)->form('/oauth/revoke', [
            'token' => $link['refresh_token'],
            'client_assertion_type' => self::ASSERTION_TYPE,
            'client_assertion' => self::assertion($state),
        ]);
        self::forget($store, $person);

        return 200 === $answer['status'];
    }

    public static function forget(Store $store, string $person): void
    {
        $store->update(static function (array $s) use ($person): array {
            unset($s['links'][$person]);

            return $s;
        });
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, string> $params
     *
     * @return array{status: int, body: array<mixed>}
     */
    private static function tokenRequest(array $state, array $params): array
    {
        return Synaplan::from($state)->form('/oauth/token', $params + [
            'client_id' => $state['instance']['id'],
            'client_assertion_type' => self::ASSERTION_TYPE,
            'client_assertion' => self::assertion($state),
        ]);
    }

    /**
     * @param array<mixed> $tokens
     */
    private static function remember(Store $store, string $person, array $tokens): void
    {
        $store->update(static function (array $s) use ($person, $tokens): array {
            $previous = is_array($s['links'][$person] ?? null) ? $s['links'][$person] : [];
            $s['links'][$person] = [
                // A real app keeps these encrypted; the fixture is a development stand-in.
                'access_token' => (string) ($tokens['access_token'] ?? ''),
                'refresh_token' => (string) ($tokens['refresh_token'] ?? ''),
                'expires_at' => time() + (int) ($tokens['expires_in'] ?? 0),
                'scope' => (string) ($tokens['scope'] ?? self::SCOPE),
                'linked_at' => (int) ($previous['linked_at'] ?? time()),
            ];

            return $s;
        });
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function assertion(array $state): string
    {
        $clientId = (string) $state['instance']['id'];
        $header = self::b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $state['oauth_key']['kid']]));
        $payload = self::b64((string) json_encode([
            'iss' => $clientId,
            'sub' => $clientId,
            'aud' => rtrim((string) $state['issuer'], '/'),
            'jti' => bin2hex(random_bytes(16)),
            'iat' => time(),
            'exp' => time() + 60,
        ], JSON_UNESCAPED_SLASHES));
        openssl_sign($header.'.'.$payload, $signature, (string) $state['oauth_key']['private'], OPENSSL_ALGO_SHA256);

        return $header.'.'.$payload.'.'.self::b64((string) $signature);
    }

    /**
     * @return array<string, mixed>
     */
    private static function ensureKey(Store $store): array
    {
        return $store->update(static function (array $s): array {
            if (is_array($s['oauth_key'] ?? null) && is_string($s['oauth_key']['private'] ?? null)) {
                return $s;
            }
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            if (false === $key || !openssl_pkey_export($key, $private)) {
                throw new FixtureException(500, 'no_key', 'Could not create the client key.');
            }
            $s['oauth_key'] = ['private' => (string) $private, 'kid' => bin2hex(random_bytes(6))];

            return $s;
        });
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, string>
     */
    private static function publicJwk(array $state): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private((string) $state['oauth_key']['private']) ?: throw new FixtureException(500, 'no_key', 'Client key unreadable.'));

        return [
            'kty' => 'RSA',
            'kid' => (string) $state['oauth_key']['kid'],
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => self::b64($details['rsa']['n']),
            'e' => self::b64($details['rsa']['e']),
        ];
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
