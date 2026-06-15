<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Repository\ConfigRepository;
use App\Service\Admin\SystemConfigService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Focused tests for SystemConfigService's database-backed config writes —
 * notably the multi-task routing master switch, which uses a dbGroup/dbKey
 * override to target the MULTITASK/ROUTING_ENABLED row.
 */
final class SystemConfigServiceTest extends TestCase
{
    private ConfigRepository&MockObject $configRepository;
    private SystemConfigService $service;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);

        $this->service = new SystemConfigService(
            projectDir: sys_get_temp_dir(),
            logger: new NullLogger(),
            configRepository: $this->configRepository,
            defaultTtsUrl: 'http://localhost:10200',
        );
    }

    /**
     * The multi-task master switch is exposed under the flat admin key
     * MULTITASK_ROUTING_ENABLED but MUST write to the BCONFIG row that
     * MultitaskRoutingConfig reads: group MULTITASK / setting ROUTING_ENABLED
     * (not the default QDRANT_SEARCH group). This is the dbGroup/dbKey override.
     */
    public function testMultitaskRoutingWritesToTheMultitaskGroupRow(): void
    {
        $this->configRepository->expects($this->once())
            ->method('setValue')
            ->with(0, 'MULTITASK', 'ROUTING_ENABLED', 'true');

        $result = $this->service->setValue('MULTITASK_ROUTING_ENABLED', 'true', 7);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['requiresRestart']);
    }

    /**
     * Existing users were grandfathered to an explicit per-user OFF row that
     * overrides the global flag. When an admin toggles the global switch we
     * drop their own per-user override so the value applies to them too.
     */
    public function testEnablingClearsActingAdminPerUserOverride(): void
    {
        $this->configRepository->expects($this->once())
            ->method('deleteValue')
            ->with(7, 'MULTITASK', 'ROUTING_ENABLED');

        $this->service->setValue('MULTITASK_ROUTING_ENABLED', 'true', 7);
    }

    public function testDisablingAlsoClearsActingAdminPerUserOverride(): void
    {
        $this->configRepository->expects($this->once())
            ->method('deleteValue')
            ->with(7, 'MULTITASK', 'ROUTING_ENABLED');

        $this->service->setValue('MULTITASK_ROUTING_ENABLED', 'false', 7);
    }

    public function testMultitaskWriteWithoutActingUserDoesNotDeleteAnything(): void
    {
        $this->configRepository->expects($this->never())->method('deleteValue');

        $this->service->setValue('MULTITASK_ROUTING_ENABLED', 'true');
    }

    /**
     * Reads must also resolve through the dbGroup/dbKey override so the admin
     * UI reflects the actual MultitaskRoutingConfig row.
     */
    public function testGetValuesReadsMultitaskFlagFromTheMultitaskGroup(): void
    {
        $this->configRepository->method('getValue')
            ->willReturnCallback(
                static fn (int $owner, string $group, string $setting): ?string => 'MULTITASK' === $group && 'ROUTING_ENABLED' === $setting ? 'false' : null
            );

        $values = $this->service->getValues();

        $this->assertSame('false', $values['MULTITASK_ROUTING_ENABLED']['value']);
        $this->assertTrue($values['MULTITASK_ROUTING_ENABLED']['isSet']);
    }
}
