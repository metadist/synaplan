<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Egress;

use App\Service\Egress\EgressGuzzleClient;
use GuzzleHttp\HandlerStack;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class EgressGuzzleClientTest extends TestCase
{
    public function testTheHandlerIsAStackGoogleCanExtend(): void
    {
        $client = EgressGuzzleClient::fromHttpClient(new MockHttpClient());
        $handler = $client->getConfig('handler');

        self::assertInstanceOf(HandlerStack::class, $handler);
        $handler->remove('google_auth');
        $handler->push(static fn (callable $next): callable => $next, 'google_auth');
    }
}
