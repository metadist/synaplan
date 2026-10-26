<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\PlatformLink;

use App\Entity\PlatformInstance;
use App\Repository\PlatformInstanceRepository;
use App\Service\PlatformLink\DeliveryAddressService;
use App\Service\PlatformLink\DeliveryOriginPolicy;
use App\Service\PlatformLink\DeliveryProbe;
use App\Service\PlatformLink\PlatformInstanceService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DeliveryAddressServiceTest extends TestCase
{
    private PlatformInstance $instance;
    private DeliveryProbe&MockObject $probe;
    private DeliveryAddressService $service;

    protected function setUp(): void
    {
        $this->instance = new PlatformInstance('nextcloud', 'pi_abc', 'files.example.org');
        $instances = $this->createStub(PlatformInstanceService::class);
        $instances->method('requireInstance')->willReturn($this->instance);
        $platforms = $this->createStub(PlatformInstanceRepository::class);
        $origins = $this->createStub(DeliveryOriginPolicy::class);
        $origins->method('accepts')->willReturn(true);
        $this->probe = $this->createMock(DeliveryProbe::class);
        $this->service = new DeliveryAddressService($instances, $platforms, $origins, $this->probe);
    }

    public function testSavingTheAddressProbesItAndStoresWhatTheAppReports(): void
    {
        $this->probe->expects($this->once())->method('reachable')->with('https://files.example.org/inbound')->willReturn(true);

        $saved = $this->service->update(
            'pi_abc',
            'secret',
            'https://files.example.org/inbound',
            ['deliveries', 'name-the-core-does-not-know', 'watches'],
            'synaplan-nextcloud',
            '2.0.0',
            'nc-instance-1',
        );

        self::assertSame(['deliveries', 'watches'], $saved['app_capabilities']);
        self::assertTrue($saved['reachable']);
        self::assertSame('synaplan-nextcloud', $saved['oidc_client_id']);
        self::assertSame('2.0.0', $saved['app_version']);
        self::assertSame('nc-instance-1', $saved['server_id']);
        self::assertSame('https://files.example.org/inbound', $this->instance->getDeliveryUrl());
    }

    public function testAnUnansweredProbeIsStoredAsUnreachable(): void
    {
        $this->probe->expects($this->once())->method('reachable')->willReturn(false);

        $saved = $this->service->update('pi_abc', 'secret', 'https://files.example.org/inbound', null);

        self::assertFalse($saved['reachable']);
    }

    public function testAMissingFieldKeepsItsValue(): void
    {
        $this->instance->setAppVersion('1.7.0');
        $this->probe->expects($this->never())->method('reachable');

        $saved = $this->service->update('pi_abc', 'secret', null, ['pickups']);

        self::assertSame('1.7.0', $saved['app_version']);
        self::assertSame(['pickups'], $saved['app_capabilities']);
        self::assertNull($saved['reachable']);
    }
}
