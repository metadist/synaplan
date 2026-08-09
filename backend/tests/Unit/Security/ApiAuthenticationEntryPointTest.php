<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\ApiAuthenticationEntryPoint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * An unauthenticated request must be answered in the protocol the caller
 * speaks. A missing or misspelled key is the first thing a Claude Code user
 * hits, and the client parses Anthropic's error envelope — Synaplan's native
 * `{error, code}` shape surfaces there as a parse failure rather than as
 * "your key is wrong".
 */
final class ApiAuthenticationEntryPointTest extends TestCase
{
    public function testMessagesApiGetsAnAnthropicErrorEnvelope(): void
    {
        $body = $this->start('/v1/messages');

        self::assertSame('error', $body['type']);
        self::assertSame('authentication_error', $body['error']['type']);
        self::assertStringContainsString('x-api-key', $body['error']['message']);
    }

    public function testCountTokensGetsTheSameEnvelope(): void
    {
        self::assertSame('error', $this->start('/v1/messages/count_tokens')['type']);
    }

    public function testOpenAiCompatibleApiGetsAnOpenAiErrorEnvelope(): void
    {
        $body = $this->start('/v1/chat/completions');

        self::assertArrayNotHasKey('type', $body);
        self::assertSame('authentication_error', $body['error']['type']);
        self::assertSame('invalid_api_key', $body['error']['code']);
        self::assertNull($body['error']['param']);
    }

    public function testSynaplansOwnApiKeepsItsNativeShape(): void
    {
        $body = $this->start('/api/v1/widgets');

        self::assertSame('Authentication required', $body['error']);
        self::assertSame('UNAUTHENTICATED', $body['code']);
    }

    /**
     * @return array<string, mixed>
     */
    private function start(string $path): array
    {
        $response = (new ApiAuthenticationEntryPoint())->start(Request::create($path, 'POST'));

        self::assertSame(401, $response->getStatusCode());

        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
