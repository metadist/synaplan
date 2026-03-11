<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\InstallPluginForVerifiedUsersCommand;
use App\Service\Plugin\PluginManager;
use App\Service\Plugin\PluginManifest;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class InstallPluginForVerifiedUsersCommandTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private Connection&MockObject $connection;
    private PluginManager&MockObject $pluginManager;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->connection = $this->createMock(Connection::class);
        $this->pluginManager = $this->createMock(PluginManager::class);

        $this->entityManager->method('getConnection')->willReturn($this->connection);

        $command = new InstallPluginForVerifiedUsersCommand(
            $this->entityManager,
            $this->pluginManager,
        );

        $application = new Application();
        $application->add($command);

        $this->commandTester = new CommandTester($application->find('app:plugin:install-verified-users'));
    }

    public function testFailsForInvalidPluginName(): void
    {
        $this->pluginManager->expects($this->never())->method('listAvailablePlugins');

        $this->commandTester->execute(['pluginName' => '../../etc']);

        $this->assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Invalid plugin name', $this->commandTester->getDisplay());
    }

    public function testFailsWhenPluginIsUnavailable(): void
    {
        $this->pluginManager->expects($this->once())
            ->method('listAvailablePlugins')
            ->willReturn([]);

        $this->commandTester->execute(['pluginName' => 'marketeer']);

        $this->assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('not available in the central plugin repository', $this->commandTester->getDisplay());
    }

    public function testSucceedsWhenNoVerifiedUsersExist(): void
    {
        $this->pluginManager->expects($this->once())
            ->method('listAvailablePlugins')
            ->willReturn([
                new PluginManifest('marketeer', '1.0.0', 'Marketing plugin'),
            ]);

        $this->connection->expects($this->once())
            ->method('fetchFirstColumn')
            ->willReturn([]);

        $this->commandTester->execute(['pluginName' => 'marketeer']);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('No active verified users found', $this->commandTester->getDisplay());
    }

    public function testFailsWhenAnyInstallationFails(): void
    {
        $this->pluginManager->expects($this->once())
            ->method('listAvailablePlugins')
            ->willReturn([
                new PluginManifest('marketeer', '1.0.0', 'Marketing plugin'),
            ]);

        $this->connection->expects($this->once())
            ->method('fetchFirstColumn')
            ->willReturn([1, 2]);

        $this->pluginManager->expects($this->exactly(2))
            ->method('installPlugin')
            ->willReturnCallback(function (int $userId): void {
                if (2 === $userId) {
                    throw new \RuntimeException('boom');
                }
            });

        $this->commandTester->execute(['pluginName' => 'marketeer']);

        $this->assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Failed for user 2: boom', $output);
        $this->assertStringContainsString("Installed 'marketeer' for 1 users, 1 failed.", $output);
    }
}
