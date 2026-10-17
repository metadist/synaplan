<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin\SystemConfig;

use App\Repository\ConfigRepository;
use App\Service\Admin\SystemConfig\ComputeSettingGuard;
use PHPUnit\Framework\TestCase;

final class ComputeSettingGuardTest extends TestCase
{
    public function testWithoutASidecarOnlyAnUnknownTierIsRefused(): void
    {
        $guard = new ComputeSettingGuard($this->createStub(ConfigRepository::class));

        self::assertNull($guard->refusal('COMPUTE_ENABLED', 'true'));
        self::assertNull($guard->refusal('COMPUTE_DEFAULT_TIMEOUT_SEC', '9999'));
        self::assertNull($guard->refusal('COMPUTE_EGRESS_ENABLED', 'true'));
        self::assertNull($guard->refusal('COMPUTE_REQUIRE_TIER', 'gvisor'));
        self::assertSame('Isolation tier must be one of: docker, gvisor, microvm.', $guard->refusal('COMPUTE_REQUIRE_TIER', 'quantum')['message'] ?? null);
    }

    public function testASettingOutsideFileWorkIsNeverRefused(): void
    {
        self::assertNull((new ComputeSettingGuard($this->createStub(ConfigRepository::class)))->refusal('BRAND_NAME', 'Acme'));
    }
}
