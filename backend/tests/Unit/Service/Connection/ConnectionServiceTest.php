<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Connection;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;
use App\Repository\InboundEmailHandlerRepository;
use App\Repository\McpServerConfigRepository;
use App\Service\Connection\ConnectionService;
use App\Service\Credential\CredentialVaultInterface;
use PHPUnit\Framework\TestCase;

final class ConnectionServiceTest extends TestCase
{
    public function testCreateNeverReturnsTheSecret(): void
    {
        $repo = $this->createMock(ConnectionRepository::class);
        $repo->expects($this->atLeastOnce())
            ->method('save')
            ->willReturnCallback(function (Connection $connection): void {
                $ref = new \ReflectionProperty(Connection::class, 'id');
                $ref->setValue($connection, 5);
            });

        $vault = $this->createMock(CredentialVaultInterface::class);
        $vault->expects($this->once())->method('store')->with(3, 'webdav', 'hunter2')->willReturn(99);

        $service = new ConnectionService(
            $repo,
            $vault,
            $this->createStub(InboundEmailHandlerRepository::class),
            $this->createStub(McpServerConfigRepository::class),
        );

        $payload = $service->create(3, [
            'name' => 'Nextcloud',
            'type' => 'webdav',
            'secret' => 'hunter2',
            'config' => ['url' => 'https://cloud.example'],
        ]);

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('hunter2', $encoded);
        $this->assertArrayNotHasKey('secret', $payload);
        $this->assertTrue($payload['has_secret']);
    }

    /**
     * An OAuth connection's credential is a token set issued by the provider.
     * Accepting one here would let a client plant an arbitrary value that the
     * Graph client would then send as a Bearer token.
     */
    public function testOauthConnectionsCannotBeCreatedThroughTheGenericEndpoint(): void
    {
        $vault = $this->createMock(CredentialVaultInterface::class);
        $vault->expects($this->never())->method('store');

        $service = new ConnectionService(
            $this->createStub(ConnectionRepository::class),
            $vault,
            $this->createStub(InboundEmailHandlerRepository::class),
            $this->createStub(McpServerConfigRepository::class),
        );

        $this->expectException(\InvalidArgumentException::class);

        $service->create(3, [
            'name' => 'Fake Microsoft',
            'type' => Connection::TYPE_M365,
            'secret' => 'planted-token',
        ]);
    }
}
