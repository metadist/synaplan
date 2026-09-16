<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\OAuth;

use App\Service\OAuth\OAuthClient;
use App\Service\OAuth\OAuthException;
use App\Service\OAuth\OAuthProviderConfig;
use App\Service\OAuth\OAuthReauthRequiredException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OAuthClientTest extends TestCase
{
    public function testAuthorizationUrlCarriesPkceChallengeAndNeverTheVerifier(): void
    {
        $client = $this->client([]);
        $verifier = $client->generateCodeVerifier();
        $challenge = $client->codeChallenge($verifier);

        $url = $client->authorizationUrl($this->provider(), 'signed-state', $challenge);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame('code', $query['response_type']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame($challenge, $query['code_challenge']);
        self::assertSame('signed-state', $query['state']);
        self::assertStringContainsString('offline_access', $query['scope']);
        self::assertStringNotContainsString($verifier, $url, 'the verifier must never leave the server');
    }

    public function testAuthorizationUrlCarriesProviderSpecificExtras(): void
    {
        $client = $this->client([]);
        $dropbox = new OAuthProviderConfig(
            provider: 'dropbox',
            authorizeUrl: 'https://www.dropbox.com/oauth2/authorize',
            tokenUrl: 'https://api.dropboxapi.com/oauth2/token',
            clientId: 'app-key',
            clientSecret: 'app-secret',
            redirectUri: 'https://app.example/callback',
            scopes: ['files.content.write'],
            extraAuthorizeParams: ['token_access_type' => 'offline'],
        );

        $url = $client->authorizationUrl($dropbox, 'signed-state', 'challenge');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('offline', $query['token_access_type'], 'without it Dropbox issues no refresh token');
        self::assertArrayNotHasKey('prompt', $query, 'Microsoft-only params must not leak to other providers');
    }

    public function testCodeChallengeIsUrlSafeBase64OfSha256(): void
    {
        $client = $this->client([]);
        $expected = rtrim(strtr(base64_encode(hash('sha256', 'verifier', true)), '+/', '-_'), '=');

        self::assertSame($expected, $client->codeChallenge('verifier'));
    }

    public function testExchangeCodeSendsTheAuthorizationCodeGrant(): void
    {
        $captured = [];
        $client = $this->client([
            new MockResponse(json_encode(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600]), [
                'http_code' => 200,
            ]),
        ], $captured);

        $tokens = $client->exchangeCode($this->provider(), 'the-code', 'the-verifier');

        self::assertSame('at', $tokens->accessToken);
        self::assertSame('rt', $tokens->refreshToken);

        parse_str($captured[0]['options']['body'], $body);
        self::assertSame('authorization_code', $body['grant_type']);
        self::assertSame('the-code', $body['code']);
        self::assertSame('the-verifier', $body['code_verifier']);
        self::assertSame('https://app.example/callback', $body['redirect_uri']);
        self::assertSame('offline_access Mail.Read', $body['scope']);
        self::assertSame('POST', $captured[0]['method']);
    }

    public function testDropboxTokenRequestsOmitScope(): void
    {
        $captured = [];
        $client = $this->client([
            new MockResponse(json_encode(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 14400]), [
                'http_code' => 200,
            ]),
            new MockResponse(json_encode(['access_token' => 'at-2', 'expires_in' => 14400]), ['http_code' => 200]),
        ], $captured);

        $dropbox = new OAuthProviderConfig(
            provider: 'dropbox',
            authorizeUrl: 'https://www.dropbox.com/oauth2/authorize',
            tokenUrl: 'https://api.dropboxapi.com/oauth2/token',
            clientId: 'app-key',
            clientSecret: 'app-secret',
            redirectUri: 'https://app.example/callback',
            scopes: ['account_info.read', 'files.content.write'],
            extraAuthorizeParams: ['token_access_type' => 'offline'],
            includeScopeInTokenRequests: false,
        );

        $client->exchangeCode($dropbox, 'the-code', 'the-verifier');
        $client->refresh($dropbox, 'rt-1');

        parse_str($captured[0]['options']['body'], $exchange);
        parse_str($captured[1]['options']['body'], $refresh);
        self::assertArrayNotHasKey('scope', $exchange, 'Dropbox downscopes a token when scope is repeated on exchange');
        self::assertArrayNotHasKey('scope', $refresh, 'Dropbox downscopes a token when scope is repeated on refresh');
    }

    public function testRefreshSendsTheRefreshGrant(): void
    {
        $captured = [];
        $client = $this->client([
            new MockResponse(json_encode(['access_token' => 'at-2', 'expires_in' => 3600]), ['http_code' => 200]),
        ], $captured);

        $tokens = $client->refresh($this->provider(), 'rt-1');

        self::assertSame('at-2', $tokens->accessToken);
        self::assertSame('rt-1', $tokens->refreshToken);

        parse_str($captured[0]['options']['body'], $body);
        self::assertSame('refresh_token', $body['grant_type']);
        self::assertSame('rt-1', $body['refresh_token']);
        self::assertSame('offline_access Mail.Read', $body['scope']);
    }

    public function testExpiredGrantAsksForConsentAgainInsteadOfRetrying(): void
    {
        $client = $this->client([
            new MockResponse(json_encode([
                'error' => 'invalid_grant',
                'error_description' => 'AADSTS700082: The refresh token has expired.',
            ]), ['http_code' => 400]),
        ]);

        $this->expectException(OAuthReauthRequiredException::class);

        $client->refresh($this->provider(), 'rt-expired');
    }

    public function testRevokedConsentAsksForConsentAgain(): void
    {
        $client = $this->client([
            new MockResponse(json_encode(['error' => 'consent_required']), ['http_code' => 400]),
        ]);

        $this->expectException(OAuthReauthRequiredException::class);

        $client->refresh($this->provider(), 'rt');
    }

    public function testServerErrorIsTransientNotAReauth(): void
    {
        $client = $this->client([new MockResponse('upstream exploded', ['http_code' => 503])]);

        try {
            $client->refresh($this->provider(), 'rt');
            self::fail('expected an OAuthException');
        } catch (OAuthReauthRequiredException) {
            self::fail('a 503 must not invalidate the stored grant');
        } catch (OAuthException $e) {
            self::assertStringContainsString('503', $e->getMessage());
        }
    }

    public function testPublicClientOmitsAnEmptySecret(): void
    {
        $captured = [];
        $client = $this->client([
            new MockResponse(json_encode(['access_token' => 'at', 'expires_in' => 3600]), ['http_code' => 200]),
        ], $captured);

        $public = new OAuthProviderConfig(
            provider: 'mcp',
            authorizeUrl: 'https://mcp.notion.com/authorize',
            tokenUrl: 'https://mcp.notion.com/token',
            clientId: 'public-id',
            clientSecret: '',
            redirectUri: 'https://app.example/callback',
            scopes: ['default'],
            includeScopeInTokenRequests: false,
        );

        $client->exchangeCode($public, 'the-code', 'the-verifier');

        parse_str($captured[0]['options']['body'], $body);
        self::assertArrayNotHasKey('client_secret', $body);
        self::assertSame('public-id', $body['client_id']);
    }

    public function testRefreshWithoutAStoredTokenAsksForConsent(): void
    {
        $this->expectException(OAuthReauthRequiredException::class);

        $this->client([])->refresh($this->provider(), '');
    }

    /**
     * @param list<MockResponse>                                              $responses
     * @param list<array{method: string, url: string, options: array<mixed>}> $captured
     */
    private function client(array $responses, array &$captured = []): OAuthClient
    {
        $factory = function (string $method, string $url, array $options) use (&$captured, &$responses): MockResponse {
            $captured[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 200]);
        };

        return new OAuthClient(new MockHttpClient($factory), new NullLogger());
    }

    private function provider(): OAuthProviderConfig
    {
        return new OAuthProviderConfig(
            provider: 'm365',
            authorizeUrl: 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            tokenUrl: 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            clientId: 'client-id',
            clientSecret: 'client-secret',
            redirectUri: 'https://app.example/callback',
            scopes: ['offline_access', 'Mail.Read'],
        );
    }
}
