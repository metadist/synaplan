<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Client;

use App\Repository\ConfigRepository;
use App\Service\Client\ClientContext;
use App\Service\Client\MobileVersionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MobileVersionServiceTest extends TestCase
{
    private ConfigRepository&MockObject $configRepository;
    private MobileVersionService $service;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->service = new MobileVersionService($this->configRepository);
    }

    public function testNoGateWhenMinVersionUnset(): void
    {
        $this->configRepository->method('getValue')->willReturn(null);

        $this->assertSame('', $this->service->getMinVersion());
        $this->assertFalse($this->service->isUpdateRequired($this->app('4.0')));
    }

    public function testNoGateForWebClient(): void
    {
        $this->withMinVersion('5.0');

        $this->assertFalse($this->service->isUpdateRequired(ClientContext::web()));
    }

    public function testUpdateRequiredWhenAppOlderThanMin(): void
    {
        $this->withMinVersion('4.1');

        $this->assertTrue($this->service->isUpdateRequired($this->app('4.0')));
        $this->assertTrue($this->service->isUpdateRequired($this->app('4.0.9')));
    }

    public function testNoUpdateWhenAppAtOrAboveMin(): void
    {
        $this->withMinVersion('4.1');

        $this->assertFalse($this->service->isUpdateRequired($this->app('4.1')));
        $this->assertFalse($this->service->isUpdateRequired($this->app('4.1.1')));
        $this->assertFalse($this->service->isUpdateRequired($this->app('5.0')));
    }

    public function testPatchLevelComparison(): void
    {
        $this->withMinVersion('4.0.5');

        $this->assertTrue($this->service->isUpdateRequired($this->app('4.0.4')));
        $this->assertFalse($this->service->isUpdateRequired($this->app('4.0.5')));
        $this->assertFalse($this->service->isUpdateRequired($this->app('4.0.6')));
    }

    public function testStoreUrlsReturnedFromConfig(): void
    {
        $this->configRepository->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => match ($setting) {
                MobileVersionService::KEY_IOS_APP_URL => 'https://apps.apple.com/app/id1',
                MobileVersionService::KEY_ANDROID_APP_URL => 'https://play.google.com/store/apps/details?id=com.synaplan.app',
                default => null,
            }
        );

        $urls = $this->service->getStoreUrls();

        $this->assertSame('https://apps.apple.com/app/id1', $urls['ios']);
        $this->assertSame('https://play.google.com/store/apps/details?id=com.synaplan.app', $urls['android']);
    }

    private function withMinVersion(string $min): void
    {
        $this->configRepository->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => MobileVersionService::KEY_MIN_APP_VERSION === $setting ? $min : null
        );
    }

    private function app(string $version): ClientContext
    {
        $parts = array_map('intval', explode('.', $version));

        return new ClientContext(
            isMobileApp: true,
            appVersion: $version,
            appVersionMajor: $parts[0],
            appVersionMinor: $parts[1] ?? null,
            appVersionPatch: $parts[2] ?? null,
        );
    }
}
