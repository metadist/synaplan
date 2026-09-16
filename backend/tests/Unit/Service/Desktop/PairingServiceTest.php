<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Desktop;

use App\Entity\ApiKey;
use App\Entity\DesktopDevice;
use App\Entity\User;
use App\Repository\ApiKeyRepository;
use App\Repository\DesktopDeviceRepository;
use App\Repository\UserRepository;
use App\Security\ApiKeyScope;
use App\Service\Desktop\PairingService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PairingServiceTest extends TestCase
{
    private ApiKeyRepository&MockObject $apiKeyRepository;
    private DesktopDeviceRepository&MockObject $deviceRepository;
    private UserRepository&MockObject $userRepository;
    private PairingService $service;

    protected function setUp(): void
    {
        $this->apiKeyRepository = $this->createMock(ApiKeyRepository::class);
        $this->deviceRepository = $this->createMock(DesktopDeviceRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->service = new PairingService($this->apiKeyRepository, $this->deviceRepository, $this->userRepository, 'https://web.synaplan.com/');
    }

    private function stubUser(int $id): User
    {
        $user = self::userWithId($id);
        $this->userRepository->expects(self::once())->method('find')->with($id)->willReturn($user);

        return $user;
    }

    private static function userWithId(int $id): User
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }

    public function testPairMintsScopedKeyAndDevice(): void
    {
        $this->stubUser(7);
        $savedKey = null;
        $this->apiKeyRepository->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (ApiKey $key) use (&$savedKey): void {
                $savedKey = $key;
            });

        $savedDevice = null;
        $this->deviceRepository->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (DesktopDevice $device) use (&$savedDevice): void {
                $savedDevice = $device;
            });

        $result = $this->service->pair(7, "Jan's laptop", ['skill.run']);

        self::assertInstanceOf(ApiKey::class, $savedKey);
        self::assertSame(7, $savedKey->getOwnerId());
        self::assertStringStartsWith('sk_', $savedKey->getKey());
        self::assertLessThanOrEqual(64, \strlen($savedKey->getKey()));
        self::assertSame(ApiKeyScope::pairingScopes(), $savedKey->getScopes());
        self::assertStringContainsString("Jan's laptop", $savedKey->getName());

        self::assertInstanceOf(DesktopDevice::class, $savedDevice);
        self::assertSame(7, $savedDevice->getOwnerId());
        self::assertSame("Jan's laptop", $savedDevice->getName());
        self::assertSame([DesktopDevice::STATUS_ACTIVE], [$savedDevice->getStatus()]);
        self::assertSame(['skill.run'], $savedDevice->getCapabilities());

        // apiBaseUrl has no trailing slash.
        self::assertSame('https://web.synaplan.com', $result['apiBaseUrl']);
    }

    public function testPairMintsRestrictedKeyByConstruction(): void
    {
        $this->stubUser(1);
        $this->deviceRepository->method('save');
        $captured = null;
        $this->apiKeyRepository->method('save')->willReturnCallback(function (ApiKey $key) use (&$captured): void {
            $captured = $key;
        });

        $this->service->pair(1, 'x', []);

        self::assertInstanceOf(ApiKey::class, $captured);
        // A paired key is scoped, never a wildcard.
        self::assertTrue(ApiKeyScope::isRestricted($captured->getScopes()));
    }

    public function testPairFallsBackToDefaultDeviceName(): void
    {
        $this->stubUser(1);
        $this->apiKeyRepository->method('save');
        $captured = null;
        $this->deviceRepository->method('save')->willReturnCallback(function (DesktopDevice $device) use (&$captured): void {
            $captured = $device;
        });

        $this->service->pair(1, '   ', []);

        self::assertInstanceOf(DesktopDevice::class, $captured);
        self::assertSame('Computer', $captured->getName());
    }

    public function testPairDropsInvalidCapabilities(): void
    {
        $this->stubUser(1);
        $this->apiKeyRepository->method('save');
        $captured = null;
        $this->deviceRepository->method('save')->willReturnCallback(function (DesktopDevice $device) use (&$captured): void {
            $captured = $device;
        });

        $this->service->pair(1, 'x', ['skill.run', 'BAD CAP!!', 'notes', 'skill.run']);

        self::assertInstanceOf(DesktopDevice::class, $captured);
        self::assertSame(['skill.run', 'notes'], $captured->getCapabilities());
    }

    public function testPairThrowsWhenOwningUserMissing(): void
    {
        $this->userRepository->method('find')->willReturn(null);
        $this->apiKeyRepository->expects(self::never())->method('save');
        $this->deviceRepository->expects(self::never())->method('save');

        $this->expectException(\App\Service\Desktop\Exception\PairingException::class);
        $this->service->pair(999, 'x', []);
    }

    public function testRevokeRemovesKeyAndFlagsDevice(): void
    {
        $device = (new DesktopDevice())->setOwnerId(1)->setApiKeyId(55)->setStatus(DesktopDevice::STATUS_ACTIVE);
        $apiKey = (new ApiKey())->setOwnerId(1)->setKey('sk_x')->setName('Desktop');

        $this->apiKeyRepository->expects(self::once())->method('find')->with(55)->willReturn($apiKey);
        $this->apiKeyRepository->expects(self::once())->method('remove')->with($apiKey, false);
        $this->deviceRepository->expects(self::once())->method('save')->with($device);

        $this->service->revoke($device);

        self::assertSame(DesktopDevice::STATUS_REVOKED, $device->getStatus());
    }

    public function testRevokeToleratesMissingKey(): void
    {
        $device = (new DesktopDevice())->setOwnerId(1)->setApiKeyId(55)->setStatus(DesktopDevice::STATUS_ACTIVE);
        $this->apiKeyRepository->method('find')->willReturn(null);
        $this->apiKeyRepository->expects(self::never())->method('remove');
        $this->deviceRepository->expects(self::once())->method('save');

        $this->service->revoke($device);

        self::assertSame(DesktopDevice::STATUS_REVOKED, $device->getStatus());
    }
}
