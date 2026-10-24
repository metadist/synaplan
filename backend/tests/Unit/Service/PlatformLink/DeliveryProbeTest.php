<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\PlatformLink;

use App\Service\PlatformLink\DeliveryProbe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class DeliveryProbeTest extends TestCase
{
    public function testAnyHttpAnswerCountsAsReachableEvenAnUnsignedRefusal(): void
    {
        $probe = new DeliveryProbe(new MockHttpClient(new MockResponse('{}', ['http_code' => 401])));

        self::assertTrue($probe->reachable('http://nextcloud/index.php/apps/synaplan_integration/api/v1/inbound'));
    }

    public function testAConnectionFailureCountsAsUnreachable(): void
    {
        $probe = new DeliveryProbe(new MockHttpClient(static function (): never {
            throw new class('Connection refused') extends \Exception implements TransportExceptionInterface {
            };
        }));

        self::assertFalse($probe->reachable('http://nextcloud:9999/inbound'));
    }
}
