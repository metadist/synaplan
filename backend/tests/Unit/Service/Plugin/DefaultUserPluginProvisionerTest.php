<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Plugin;

use App\Entity\User;
use App\Service\Plugin\DefaultUserPluginProvisioner;
use App\Service\Plugin\PluginManager;
use App\Service\Plugin\PluginManifest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class DefaultUserPluginProvisionerTest extends TestCase
{
    private PluginManager&MockObject $pluginManager;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->pluginManager = $this->createMock(PluginManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testProvisionNewUserInstallsConfiguredAvailablePlugin(): void
    {
        $user = $this->createUser(42);
        $service = $this->createService(['marketeer']);

        $this->pluginManager->expects($this->once())
            ->method('listAvailablePlugins')
            ->willReturn([
                new PluginManifest('marketeer', '1.0.0', 'Marketing plugin'),
            ]);

        $this->pluginManager->expects($this->once())
            ->method('installPlugin')
            ->with(42, 'marketeer');

        $service->provisionNewUser($user);
    }

    public function testProvisionNewUserSkipsInvalidPluginName(): void
    {
        $user = $this->createUser(7);
        $service = $this->createService([' ../../etc ']);

        $this->pluginManager->expects($this->once())
            ->method('listAvailablePlugins')
            ->willReturn([]);

        $this->pluginManager->expects($this->never())
            ->method('installPlugin');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('plugin name is invalid'),
                $this->callback(static fn (array $context): bool => 7 === $context['user_id'] && '../../etc' === $context['plugin'])
            );

        $service->provisionNewUser($user);
    }

    public function testProvisionNewUserSkipsWhenPluginIsUnavailable(): void
    {
        $user = $this->createUser(9);
        $service = $this->createService(['marketeer']);

        $this->pluginManager->expects($this->once())
            ->method('listAvailablePlugins')
            ->willReturn([]);

        $this->pluginManager->expects($this->never())
            ->method('installPlugin');

        $this->logger->expects($this->once())
            ->method('debug')
            ->with(
                $this->stringContains('plugin is not available'),
                $this->callback(static fn (array $context): bool => 9 === $context['user_id'] && 'marketeer' === $context['plugin'])
            );

        $service->provisionNewUser($user);
    }

    /**
     * @param string[] $defaultPlugins
     */
    private function createService(array $defaultPlugins): DefaultUserPluginProvisioner
    {
        return new DefaultUserPluginProvisioner(
            $this->pluginManager,
            $this->logger,
            $defaultPlugins,
        );
    }

    private function createUser(int $id): User
    {
        $user = new User();
        $reflection = new \ReflectionProperty($user, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($user, $id);

        return $user;
    }
}
