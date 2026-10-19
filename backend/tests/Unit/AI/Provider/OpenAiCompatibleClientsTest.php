<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider;

use App\AI\Provider\OpenAiCompatibleClients;
use App\AI\Provider\OpenAiCompatibleRequestHeaders;
use PHPUnit\Framework\TestCase;

final class OpenAiCompatibleClientsTest extends TestCase
{
    private const ENDPOINT = [
        'name' => 'synaplan-demo',
        'base_url' => 'http://127.0.0.1:8787/v1',
        'api_key' => 'sk-free-1',
        'headers' => ['X-Synaplan-Install' => 'abc'],
    ];

    public function testWithoutAHeaderSourceTheEndpointIsUnchanged(): void
    {
        $this->assertSame(self::ENDPOINT, (new OpenAiCompatibleClients())->withCallHeaders(self::ENDPOINT, []));
    }

    public function testCallHeadersAreAddedToTheStaticOnes(): void
    {
        $source = new class implements OpenAiCompatibleRequestHeaders {
            public function forCall(string $endpointName, array $options): array
            {
                return ['X-Synaplan-Surface' => 'widget'];
            }
        };

        $endpoint = (new OpenAiCompatibleClients(requestHeaders: $source))->withCallHeaders(self::ENDPOINT, []);

        $this->assertSame(['X-Synaplan-Install' => 'abc', 'X-Synaplan-Surface' => 'widget'], $endpoint['headers']);
    }

    public function testClientsAreReusedPerEndpointAndRebuiltWhenAnythingChanges(): void
    {
        $clients = new OpenAiCompatibleClients();
        $first = $clients->clientFor(self::ENDPOINT, 'free-chat');

        $this->assertSame($first, $clients->clientFor(self::ENDPOINT, 'free-sort'));
        $this->assertNotSame($first, $clients->clientFor(['api_key' => 'sk-free-2'] + self::ENDPOINT, 'free-chat'), 'a rotated key needs a new client');
        $this->assertNotSame($first, $clients->clientFor(['base_url' => 'http://127.0.0.1:9999/v1'] + self::ENDPOINT, 'free-chat'));
        $this->assertNotSame($first, $clients->clientFor(['headers' => ['X-Synaplan-End-User' => 'h']] + self::ENDPOINT, 'free-chat'));
    }
}
