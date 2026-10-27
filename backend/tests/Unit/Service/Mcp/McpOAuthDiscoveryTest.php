<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp;

use App\Service\Mcp\McpOAuthDiscovery;
use App\Service\Mcp\McpOAuthException;
use App\Service\Mcp\McpOAuthFailure;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class McpOAuthDiscoveryTest extends TestCase
{
    public function testNotionShapedDiscoveryFromChallengeHeader(): void
    {
        $captured = [];
        $discovery = $this->discovery([
            new MockResponse('{"error":"invalid_token"}', [
                'http_code' => 401,
                'response_headers' => [
                    'www-authenticate' => 'Bearer realm="OAuth", resource_metadata="https://mcp.notion.com/.well-known/oauth-protected-resource/mcp"',
                ],
            ]),
            new MockResponse((string) json_encode([
                'resource' => 'https://mcp.notion.com/mcp',
                'authorization_servers' => ['https://mcp.notion.com'],
                'scopes_supported' => ['default'],
                'bearer_methods_supported' => ['header'],
            ]), ['http_code' => 200]),
            new MockResponse((string) json_encode([
                'issuer' => 'https://mcp.notion.com',
                'authorization_endpoint' => 'https://mcp.notion.com/authorize',
                'token_endpoint' => 'https://mcp.notion.com/token',
                'registration_endpoint' => 'https://mcp.notion.com/register',
                'code_challenge_methods_supported' => ['plain', 'S256'],
                'grant_types_supported' => ['authorization_code', 'refresh_token'],
                'scopes_supported' => ['default'],
            ]), ['http_code' => 200]),
        ], $captured);

        $result = $discovery->discover('https://mcp.notion.com/mcp');

        self::assertSame('https://mcp.notion.com/mcp', $result->resource);
        self::assertSame('https://mcp.notion.com/authorize', $result->authorizationEndpoint);
        self::assertSame('https://mcp.notion.com/register', $result->registrationEndpoint);
        self::assertSame(['default'], $result->scopes);
        self::assertTrue($result->supportsRefreshGrant);
        self::assertSame('https://mcp.notion.com/.well-known/oauth-protected-resource/mcp', $captured[1]['url']);
    }

    public function testHiggsfieldShapedDiscoveryFallsBackToPathSuffix(): void
    {
        $discovery = $this->discovery([
            new MockResponse('{"error":"Unauthorized"}', ['http_code' => 401]),
            new MockResponse((string) json_encode([
                'resource' => 'https://mcp.higgsfield.ai/mcp',
                'authorization_servers' => ['https://mcp.higgsfield.ai'],
                'scopes_supported' => ['openid', 'email', 'offline_access'],
            ]), ['http_code' => 200]),
            new MockResponse((string) json_encode([
                'issuer' => 'https://mcp.higgsfield.ai',
                'authorization_endpoint' => 'https://mcp.higgsfield.ai/oauth2/authorize',
                'token_endpoint' => 'https://mcp.higgsfield.ai/oauth2/token',
                'registration_endpoint' => 'https://mcp.higgsfield.ai/oauth2/register',
                'code_challenge_methods_supported' => ['S256'],
                'grant_types_supported' => ['authorization_code', 'refresh_token'],
            ]), ['http_code' => 200]),
        ]);

        $result = $discovery->discover('https://mcp.higgsfield.ai/mcp');

        self::assertSame(['openid', 'email', 'offline_access'], $result->scopes);
        self::assertSame('https://mcp.higgsfield.ai/oauth2/token', $result->tokenEndpoint);
        self::assertTrue($result->supportsRefreshGrant);
    }

    public function testMissingS256IsRejected(): void
    {
        $discovery = $this->discovery([
            new MockResponse('{}', [
                'http_code' => 401,
                'response_headers' => ['www-authenticate' => 'Bearer resource_metadata="https://auth.example.com/.well-known/oauth-protected-resource/mcp"'],
            ]),
            new MockResponse((string) json_encode([
                'resource' => 'https://auth.example.com/mcp',
                'authorization_servers' => ['https://auth.example.com'],
            ]), ['http_code' => 200]),
            new MockResponse((string) json_encode([
                'issuer' => 'https://auth.example.com',
                'authorization_endpoint' => 'https://auth.example.com/authorize',
                'token_endpoint' => 'https://auth.example.com/token',
                'registration_endpoint' => 'https://auth.example.com/register',
                'code_challenge_methods_supported' => ['plain'],
            ]), ['http_code' => 200]),
        ]);

        $this->expectException(McpOAuthException::class);
        $this->expectExceptionMessageMatches('/S256/');

        $discovery->discover('https://auth.example.com/mcp');
    }

    public function testMissingRegistrationEndpointIsRejected(): void
    {
        $discovery = $this->discovery([
            new MockResponse('{}', [
                'http_code' => 401,
                'response_headers' => ['www-authenticate' => 'Bearer resource_metadata="https://auth.example.com/.well-known/oauth-protected-resource/mcp"'],
            ]),
            new MockResponse((string) json_encode([
                'resource' => 'https://auth.example.com/mcp',
                'authorization_servers' => ['https://auth.example.com'],
            ]), ['http_code' => 200]),
            new MockResponse((string) json_encode([
                'issuer' => 'https://auth.example.com',
                'authorization_endpoint' => 'https://auth.example.com/authorize',
                'token_endpoint' => 'https://auth.example.com/token',
                'code_challenge_methods_supported' => ['S256'],
            ]), ['http_code' => 200]),
        ]);

        $this->expectException(McpOAuthException::class);
        $this->expectExceptionMessageMatches('/automatic app registration/');

        $discovery->discover('https://auth.example.com/mcp');
    }

    public function testPrivateTargetIsBlocked(): void
    {
        $this->expectException(McpOAuthException::class);
        $this->expectExceptionMessageMatches('/not allowed/');

        $this->discovery([])->discover('http://127.0.0.1/mcp');
    }

    public function testPrivateEndpointInMetadataIsBlocked(): void
    {
        $discovery = $this->discovery([
            new MockResponse('{}', [
                'http_code' => 401,
                'response_headers' => ['www-authenticate' => 'Bearer resource_metadata="https://auth.example.com/.well-known/oauth-protected-resource/mcp"'],
            ]),
            new MockResponse((string) json_encode([
                'resource' => 'https://auth.example.com/mcp',
                'authorization_servers' => ['https://auth.example.com'],
            ]), ['http_code' => 200]),
            new MockResponse((string) json_encode([
                'issuer' => 'https://auth.example.com',
                'authorization_endpoint' => 'http://127.0.0.1/authorize',
                'token_endpoint' => 'https://auth.example.com/token',
                'registration_endpoint' => 'https://auth.example.com/register',
                'code_challenge_methods_supported' => ['S256'],
            ]), ['http_code' => 200]),
        ]);

        $this->expectException(McpOAuthException::class);
        $this->expectExceptionMessageMatches('/not allowed/');

        $discovery->discover('https://auth.example.com/mcp');
    }

    public function testMetadataForAnotherServerIsRefused(): void
    {
        $discovery = $this->discovery($this->signInChain(resource: 'https://mcp.notion.com/mcp'));

        $this->expectException(McpOAuthException::class);
        $this->expectExceptionMessageMatches('/different server/');

        $discovery->discover('https://mcp.example.com/mcp');
    }

    public function testMetadataForASiblingPathIsRefused(): void
    {
        $discovery = $this->discovery($this->signInChain(resource: 'https://mcp.example.com/other'));

        $this->expectException(McpOAuthException::class);
        $this->expectExceptionMessageMatches('/different server/');

        $discovery->discover('https://mcp.example.com/mcp');
    }

    public function testResourceAtTheOriginCoversThePath(): void
    {
        $result = $this->discovery($this->signInChain(resource: 'https://mcp.example.com'))
            ->discover('https://mcp.example.com/mcp');

        self::assertSame('https://mcp.example.com', $result->resource);
        self::assertSame('https://auth.example.com', $result->issuer);
    }

    public function testIssuerMismatchIsRefused(): void
    {
        $discovery = $this->discovery($this->signInChain(advertisedIssuer: 'https://evil.example.com'));

        $this->expectException(McpOAuthException::class);
        $this->expectExceptionMessageMatches('/different issuer/');

        $discovery->discover('https://mcp.example.com/mcp');
    }

    public function testPlainHttpServerIsRefused(): void
    {
        $this->expectException(McpOAuthException::class);
        $this->expectExceptionMessageMatches('/https/');

        $this->discovery([])->discover('http://mcp.example.com/mcp');
    }

    public function testPlainHttpTokenEndpointIsRefused(): void
    {
        $discovery = $this->discovery($this->signInChain(tokenEndpoint: 'http://auth.example.com/token'));

        $this->expectException(McpOAuthException::class);
        $this->expectExceptionMessageMatches('/https/');

        $discovery->discover('https://mcp.example.com/mcp');
    }

    public function testPlainHttpMetadataLinkIsRefused(): void
    {
        $discovery = $this->discovery([
            new MockResponse('{}', [
                'http_code' => 401,
                'response_headers' => ['www-authenticate' => 'Bearer resource_metadata="http://mcp.example.com/.well-known/oauth-protected-resource/mcp"'],
            ]),
        ]);

        $this->expectException(McpOAuthException::class);
        $this->expectExceptionMessageMatches('/https/');

        $discovery->discover('https://mcp.example.com/mcp');
    }

    public function testTheAdvertisedResourceIsSentBackUnchanged(): void
    {
        $result = $this->discovery($this->signInChain(resource: 'https://mcp.example.com/mcp/'))
            ->discover('https://mcp.example.com/mcp');

        self::assertSame('https://mcp.example.com/mcp/', $result->resource);
    }

    public function testIssuerIsComparedExactly(): void
    {
        $discovery = $this->discovery($this->signInChain(
            advertisedIssuer: 'https://auth.example.com/tenant/',
            namedIssuer: 'https://auth.example.com/tenant',
        ));

        $this->expectException(McpOAuthException::class);
        $this->expectExceptionMessageMatches('/different issuer/');

        $discovery->discover('https://mcp.example.com/mcp');
    }

    public function testEveryFetchIsBoundedInTimeAndSize(): void
    {
        $captured = [];
        $this->discovery($this->signInChain(), $captured)->discover('https://mcp.example.com/mcp');

        self::assertCount(3, $captured);
        foreach ($captured as $request) {
            self::assertSame((float) McpOAuthDiscovery::MAX_DURATION_SECONDS, (float) $request['options']['max_duration']);
            self::assertSame(0, $request['options']['max_redirects']);
        }
    }

    public function testOversizedMetadataIsRefused(): void
    {
        $discovery = $this->discovery([
            new MockResponse('{}', [
                'http_code' => 401,
                'response_headers' => ['www-authenticate' => 'Bearer resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource/mcp"'],
            ]),
            new MockResponse(str_repeat(' ', McpOAuthDiscovery::MAX_BODY_BYTES + 1).'{}', ['http_code' => 200]),
        ]);

        $this->expectException(McpOAuthException::class);

        $discovery->discover('https://mcp.example.com/mcp');
    }

    public function testEveryRefusalNamesItsReason(): void
    {
        $chain = fn (string ...$overrides): array => $this->signInChain(...$overrides);

        $this->assertReason(McpOAuthFailure::AddressNotAllowed, fn () => $this->discovery([])->discover('https://127.0.0.1/mcp'));
        $this->assertReason(McpOAuthFailure::HttpsRequired, fn () => $this->discovery([])->discover('http://mcp.example.com/mcp'));
        $this->assertReason(McpOAuthFailure::MetadataInvalid, fn () => $this->discovery($chain(resource: 'https://mcp.notion.com/mcp'))->discover('https://mcp.example.com/mcp'));
        $this->assertReason(McpOAuthFailure::MetadataInvalid, fn () => $this->discovery($chain(advertisedIssuer: 'https://evil.example.com'))->discover('https://mcp.example.com/mcp'));
        $this->assertReason(McpOAuthFailure::HttpsRequired, fn () => $this->discovery($chain(tokenEndpoint: 'http://auth.example.com/token'))->discover('https://mcp.example.com/mcp'));
        $this->assertReason(McpOAuthFailure::NoSignIn, fn () => $this->discovery([new MockResponse('{}', ['http_code' => 401])])->discover('https://mcp.example.com/mcp'));
        $this->assertReason(McpOAuthFailure::Unreachable, fn () => $this->discovery([
            new MockResponse('{}', [
                'http_code' => 401,
                'response_headers' => ['www-authenticate' => 'Bearer resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource/mcp"'],
            ]),
            new MockResponse('', ['error' => 'Connection refused']),
        ])->discover('https://mcp.example.com/mcp'));
        $this->assertReason(McpOAuthFailure::MetadataInvalid, fn () => $this->discovery([
            new MockResponse('{}', [
                'http_code' => 401,
                'response_headers' => ['www-authenticate' => 'Bearer resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource/mcp"'],
            ]),
            new MockResponse(str_repeat(' ', McpOAuthDiscovery::MAX_BODY_BYTES + 1).'{}', ['http_code' => 200]),
        ])->discover('https://mcp.example.com/mcp'));
    }

    public function testWithoutAChallengeTheFallbackKeepsTheMeaningfulReason(): void
    {
        $noChallenge = new MockResponse('{}', ['http_code' => 401]);
        $missing = new MockResponse('', ['http_code' => 404]);

        $this->assertReason(McpOAuthFailure::NoSignIn, fn () => $this->discovery([
            $noChallenge, $missing, new MockResponse('', ['http_code' => 404]), new MockResponse('', ['http_code' => 404]),
        ])->discover('https://mcp.example.com/mcp'));
        $this->assertReason(McpOAuthFailure::Unreachable, fn () => $this->discovery([
            new MockResponse('{}', ['http_code' => 401]),
            new MockResponse('', ['error' => 'Connection refused']),
            new MockResponse('', ['http_code' => 404]),
            new MockResponse('', ['http_code' => 404]),
        ])->discover('https://mcp.example.com/mcp'));
        $this->assertReason(McpOAuthFailure::MetadataInvalid, fn () => $this->discovery([
            new MockResponse('{}', ['http_code' => 401]),
            new MockResponse('', ['http_code' => 404]),
            new MockResponse(str_repeat(' ', McpOAuthDiscovery::MAX_BODY_BYTES + 1).'{}', ['http_code' => 200]),
            new MockResponse('', ['http_code' => 404]),
        ])->discover('https://mcp.example.com/mcp'));
        $this->assertReason(McpOAuthFailure::Unreachable, fn () => $this->discovery([
            new MockResponse('{}', ['http_code' => 401]),
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('', ['http_code' => 404]),
            new MockResponse('', ['http_code' => 404]),
        ])->discover('https://mcp.example.com/mcp'));
    }

    private function assertReason(McpOAuthFailure $expected, callable $discover): void
    {
        try {
            $discover();
            self::fail('Expected the sign-in to be refused with '.$expected->value);
        } catch (McpOAuthException $e) {
            self::assertSame($expected, $e->reason, $e->getMessage());
        }
    }

    /**
     * @return list<MockResponse>
     */
    private function signInChain(
        string $resource = 'https://mcp.example.com/mcp',
        string $advertisedIssuer = 'https://auth.example.com',
        string $tokenEndpoint = 'https://auth.example.com/token',
        string $namedIssuer = 'https://auth.example.com',
    ): array {
        return [
            new MockResponse('{}', [
                'http_code' => 401,
                'response_headers' => ['www-authenticate' => 'Bearer resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource/mcp"'],
            ]),
            new MockResponse((string) json_encode([
                'resource' => $resource,
                'authorization_servers' => [$namedIssuer],
            ]), ['http_code' => 200]),
            new MockResponse((string) json_encode([
                'issuer' => $advertisedIssuer,
                'authorization_endpoint' => 'https://auth.example.com/authorize',
                'token_endpoint' => $tokenEndpoint,
                'registration_endpoint' => 'https://auth.example.com/register',
                'code_challenge_methods_supported' => ['S256'],
            ]), ['http_code' => 200]),
        ];
    }

    /**
     * @param list<MockResponse>                                                      $responses
     * @param list<array{method: string, url: string, options: array<string, mixed>}> $captured
     */
    private function discovery(array $responses, array &$captured = []): McpOAuthDiscovery
    {
        $factory = function (string $method, string $url, array $options) use (&$captured, &$responses): MockResponse {
            $captured[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 404]);
        };

        return new McpOAuthDiscovery(new MockHttpClient($factory), new SsrfGuard(), new NullLogger());
    }
}
