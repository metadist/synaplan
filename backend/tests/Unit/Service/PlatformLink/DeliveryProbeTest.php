<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\PlatformLink;

use App\Service\PlatformLink\DeliveryProbe;
use App\Service\PlatformLink\RedirectUriPolicy;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class DeliveryProbeTest extends TestCase
{
    public function testAnyHttpAnswerCountsAsReachableEvenAnUnsignedRefusal(): void
    {
        $probe = $this->probe(new MockHttpClient(new MockResponse('{}', ['http_code' => 401])), ['192.168.10.20']);

        self::assertTrue($probe->reachable('http://files.example.lan/inbound'));
    }

    public function testTheProbeConnectsToThePinnedAddressOnly(): void
    {
        $seen = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): ResponseInterface {
            $seen = $options;

            return new MockResponse('{}', ['http_code' => 200]);
        });
        $probe = $this->probe($http, ['192.168.10.20', '203.0.113.9']);

        self::assertTrue($probe->reachable('https://files.example.org/inbound'));
        self::assertSame(['files.example.org' => '192.168.10.20'], $seen['resolve'] ?? null);
        self::assertSame(5.0, $seen['max_duration'] ?? null);
    }

    public function testAConnectionFailureCountsAsUnreachable(): void
    {
        $probe = $this->probe(new MockHttpClient(static function (): never {
            throw new class('Connection refused') extends \Exception implements TransportExceptionInterface {
            };
        }), ['192.168.10.20']);

        self::assertFalse($probe->reachable('http://files.example.lan:9999/inbound'));
    }

    public function testNoUsableAddressFailsClosedWithoutAsking(): void
    {
        $asked = false;
        $http = new MockHttpClient(static function () use (&$asked): ResponseInterface {
            $asked = true;

            return new MockResponse('{}', ['http_code' => 200]);
        });
        $probe = $this->probe($http, ['127.0.0.1']);

        self::assertFalse($probe->reachable('https://files.example.org/inbound'));
        self::assertFalse($asked);
    }

    /**
     * @param list<string> $pinned
     */
    private function probe(MockHttpClient $http, array $pinned): DeliveryProbe
    {
        $ssrf = $this->createStub(SsrfGuard::class);
        $ssrf->method('pinnedIps')->willReturn($pinned);
        $redirects = $this->createStub(RedirectUriPolicy::class);
        $redirects->method('isDev')->willReturn(false);

        return new DeliveryProbe($http, $ssrf, $redirects);
    }
}
