<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\PlatformLink;

use App\Entity\PlatformInstance;
use App\Service\PlatformLink\DeliveryOriginPolicy;
use App\Service\PlatformLink\DeliveryProbe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class DeliveryProbeTest extends TestCase
{
    public function testAnyHttpAnswerCountsAsReachableEvenAnUnsignedRefusal(): void
    {
        $probe = $this->probe(new MockHttpClient(new MockResponse('{}', ['http_code' => 401])), '203.0.113.9');

        self::assertTrue($probe->reachable($this->cloud(), 'https://files.example.org/inbound'));
    }

    public function testTheProbeConnectsToTheAddressTheOriginCheckResolved(): void
    {
        $seen = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): ResponseInterface {
            $seen = $options;

            return new MockResponse('{}', ['http_code' => 200]);
        });
        $probe = $this->probe($http, '203.0.113.9');

        self::assertTrue($probe->reachable($this->cloud(), 'https://files.example.org/inbound'));
        self::assertSame(['files.example.org' => '203.0.113.9'], $seen['resolve'] ?? null);
        self::assertSame(5.0, $seen['max_duration'] ?? null);
    }

    public function testAConnectionFailureCountsAsUnreachable(): void
    {
        $probe = $this->probe(new MockHttpClient(static function (): never {
            throw new class('Connection refused') extends \Exception implements TransportExceptionInterface {
            };
        }), '203.0.113.9');

        self::assertFalse($probe->reachable($this->cloud(), 'https://files.example.org/inbound'));
    }

    public function testNoAcceptedAddressFailsClosedWithoutAsking(): void
    {
        $asked = false;
        $http = new MockHttpClient(static function () use (&$asked): ResponseInterface {
            $asked = true;

            return new MockResponse('{}', ['http_code' => 200]);
        });
        $probe = $this->probe($http, null);

        self::assertFalse($probe->reachable($this->cloud(), 'https://files.example.org/inbound'));
        self::assertFalse($asked);
    }

    private function probe(MockHttpClient $http, ?string $address): DeliveryProbe
    {
        $origins = $this->createStub(DeliveryOriginPolicy::class);
        $origins->method('pinnedAddress')->willReturn($address);

        return new DeliveryProbe($http, $origins);
    }

    private function cloud(): PlatformInstance
    {
        return new PlatformInstance('nextcloud', 'pi_test', 'files.example.org', [], PlatformInstance::STATUS_ACTIVE);
    }
}
