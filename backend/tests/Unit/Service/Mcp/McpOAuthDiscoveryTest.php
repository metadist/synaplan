<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp;

use App\Service\Mcp\McpOAuthDiscovery;
use App\Service\Mcp\McpOAuthException;
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
