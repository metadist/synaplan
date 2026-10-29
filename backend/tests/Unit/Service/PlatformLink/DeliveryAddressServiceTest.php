<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\PlatformLink;

use App\Entity\PlatformInstance;
use App\Repository\PlatformInstanceRepository;
use App\Service\Iam\AuditLogWriter;
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
    private PlatformInstanceRepository&MockObject $platforms;
    private AuditLogWriter&MockObject $auditLog;
    private DeliveryAddressService $service;

    protected function setUp(): void
    {
        $this->instance = new PlatformInstance('nextcloud', 'pi_abc', 'files.example.org', null, PlatformInstance::STATUS_ACTIVE);
        $instances = $this->createStub(PlatformInstanceService::class);
        $instances->method('requireInstance')->willReturn($this->instance);
        $this->platforms = $this->createMock(PlatformInstanceRepository::class);
        $origins = $this->createStub(DeliveryOriginPolicy::class);
        $origins->method('accepts')->willReturn(true);
        $this->probe = $this->createMock(DeliveryProbe::class);
        $this->auditLog = $this->createMock(AuditLogWriter::class);
        $this->service = new DeliveryAddressService($instances, $this->platforms, $origins, $this->probe, $this->auditLog);
    }

    public function testReRegisteringTheSameHostSupersedesTheEarlierInstance(): void
    {
        $earlier = new PlatformInstance('nextcloud', 'pi_old', 'files.example.org', null, PlatformInstance::STATUS_ACTIVE);
        $earlier->setOidcClientId('synaplan-nextcloud');
        $elsewhere = new PlatformInstance('nextcloud', 'pi_other', 'files.example.org', null, PlatformInstance::STATUS_REVOKED);
        $elsewhere->setOidcClientId('synaplan-nextcloud');
        $this->platforms->expects($this->once())->method('findBy')
            ->with(['oidcClientId' => 'synaplan-nextcloud', 'host' => 'files.example.org'])
            ->willReturn([$earlier, $elsewhere, $this->instance]);
        $this->auditLog->expects($this->once())->method('record')
            ->with(0, 'platform_instance.superseded', 'platform_instance', 'pi_old', ['by' => 'pi_abc', 'host' => 'files.example.org']);

        $this->service->update('pi_abc', 'secret', null, null, 'synaplan-nextcloud');

        self::assertSame(PlatformInstance::STATUS_REVOKED, $earlier->getStatus());
        self::assertSame(PlatformInstance::STATUS_REVOKED, $elsewhere->getStatus(), 'already revoked stays revoked');
        self::assertTrue($this->instance->isActive(), 'the newer registration wins');
    }

    public function testAPendingInstanceSupersedesNothing(): void
    {
        $this->instance->setStatus(PlatformInstance::STATUS_PENDING);
        $this->platforms->expects($this->never())->method('findBy');

        $this->service->update('pi_abc', 'secret', null, null, 'synaplan-nextcloud');
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
