<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp;

use App\Service\Mcp\McpOAuthException;
use App\Service\Mcp\McpOAuthRegistration;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class McpOAuthRegistrationTest extends TestCase
{
    public function testRegistersAPublicClientAndRecordsRefreshSupport(): void
    {
        $captured = [];
        $registration = $this->registration([
            new MockResponse((string) json_encode([
                'client_id' => 'atclHxQjy13pQbwb',
                'grant_types' => ['authorization_code', 'refresh_token'],
                'token_endpoint_auth_method' => 'none',
            ]), ['http_code' => 201]),
        ], $captured);

        $result = $registration->register(
            'https://mcp.notion.com/register',
            'https://web.synaplan.com/api/v1/mcp-servers/oauth/callback',
            'https://web.synaplan.com',
            ['default'],
        );

        self::assertSame('atclHxQjy13pQbwb', $result['client_id']);
        self::assertTrue($result['supports_refresh']);

        $body = json_decode((string) $captured[0]['options']['body'], true);
        self::assertSame('none', $body['token_endpoint_auth_method']);
        self::assertSame('Synaplan', $body['client_name']);
        self::assertContains('refresh_token', $body['grant_types']);
    }

    public function testHiggsfieldStyleResponseWithoutRefreshGrant(): void
    {
        $registration = $this->registration([
            new MockResponse((string) json_encode([
                'client_id' => 'XirNp6APWCavQe8h',
                'grant_types' => ['authorization_code'],
                'token_endpoint_auth_method' => 'none',
            ]), ['http_code' => 201]),
        ]);

        $result = $registration->register(
            'https://mcp.higgsfield.ai/oauth2/register',
            'https://web.synaplan.com/api/v1/mcp-servers/oauth/callback',
            'https://web.synaplan.com',
            ['openid', 'email', 'offline_access'],
        );

        self::assertSame('XirNp6APWCavQe8h', $result['client_id']);
        self::assertFalse($result['supports_refresh']);
    }

    public function testPrivateEndpointIsBlocked(): void
    {
        $this->expectException(McpOAuthException::class);
        $this->expectExceptionMessageMatches('/not allowed/');

        $this->registration([])->register(
            'http://127.0.0.1/register',
            'https://web.synaplan.com/callback',
            'https://web.synaplan.com',
            [],
        );
    }

    /**
     * @param list<MockResponse>                                                      $responses
     * @param list<array{method: string, url: string, options: array<string, mixed>}> $captured
     */
    private function registration(array $responses, array &$captured = []): McpOAuthRegistration
    {
        $factory = function (string $method, string $url, array $options) use (&$captured, &$responses): MockResponse {
            $captured[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 500]);
        };

        return new McpOAuthRegistration(new MockHttpClient($factory), new SsrfGuard());
    }
}
