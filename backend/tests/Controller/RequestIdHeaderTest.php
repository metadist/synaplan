<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Observability\RequestIdGenerator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class RequestIdHeaderTest extends WebTestCase
{
    /** Public 200 endpoint — exercises the response path without auth. */
    private const PUBLIC_PATH = '/.well-known/oauth-protected-resource/mcp';

    public function testResponseCarriesGeneratedCorrelationId(): void
    {
        $client = static::createClient();
        $client->request('GET', self::PUBLIC_PATH);

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $id = $response->headers->get(RequestIdGenerator::HEADER);
        self::assertIsString($id);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
    }

    public function testValidUpstreamCorrelationIdIsEchoed(): void
    {
        $client = static::createClient();
        $client->request(
            'GET',
            self::PUBLIC_PATH,
            server: ['HTTP_X_REQUEST_ID' => 'trace-upstream_42'],
        );

        self::assertSame(
            'trace-upstream_42',
            $client->getResponse()->headers->get(RequestIdGenerator::HEADER),
        );
    }

    public function testGarbageUpstreamCorrelationIdIsReplaced(): void
    {
        $client = static::createClient();
        $client->request(
            'GET',
            self::PUBLIC_PATH,
            server: ['HTTP_X_REQUEST_ID' => 'has spaces and @pii'],
        );

        $id = $client->getResponse()->headers->get(RequestIdGenerator::HEADER);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $id);
    }
}
