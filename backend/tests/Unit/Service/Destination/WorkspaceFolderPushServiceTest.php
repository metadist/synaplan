<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Destination;

use App\Entity\Connection;
use App\Entity\User;
use App\Repository\ConnectionRepository;
use App\Service\Compute\ComputeConfig;
use App\Service\Compute\ComputeRefusedException;
use App\Service\Compute\ComputeWorkspaceService;
use App\Service\Destination\DestinationFailureCode;
use App\Service\Destination\DestinationProvider;
use App\Service\Destination\DestinationRegistry;
use App\Service\Destination\DestinationResult;
use App\Service\Destination\ShareableFile;
use App\Service\Destination\WorkspaceFolderPushService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class WorkspaceFolderPushServiceTest extends TestCase
{
    public function testCopiesAWorkspaceFileIntoTheOwnersNextcloud(): void
    {
        $connection = $this->connection(12, 'nextcloud-Ordner (ada)', ['channel' => 'nextcloud']);
        $provider = $this->createMock(DestinationProvider::class);
        $provider->method('id')->willReturn('webdav');
        $provider->expects(self::once())
            ->method('send')
            ->willReturnCallback(function (ShareableFile $file, array $params): DestinationResult {
                self::assertSame(4, $file->ownerId);
                self::assertSame('probe.txt', $file->name);
                self::assertSame('hello from workspace', file_get_contents($file->absolutePath));
                self::assertSame(['connection_id' => 12], $params);

                return DestinationResult::success('Synaplan/probe.txt', [
                    'connection' => 'nextcloud-Ordner (ada)',
                    'newName' => 'probe.txt',
                ]);
            });

        $service = $this->service(
            workspacesOn: true,
            connection: $connection,
            download: ['contents' => 'hello from workspace', 'mime' => 'text/plain', 'name' => 'probe.txt'],
            provider: $provider,
        );

        $result = $service->push($this->user(4), 'probe.txt', 12);

        self::assertSame(200, $result['status']);
        self::assertTrue($result['body']['success']);
        self::assertSame('nextcloud', $result['body']['kind']);
        self::assertSame('Synaplan/probe.txt', $result['body']['reference']);
    }

    public function testRefusesAGenericWebDavConnection(): void
    {
        $connection = $this->connection(9, 'Archive', ['channel' => 'folder']);
        $provider = $this->createMock(DestinationProvider::class);
        $provider->method('id')->willReturn('webdav');
        $provider->expects(self::never())->method('send');

        $service = $this->service(true, $connection, null, $provider);
        $result = $service->push($this->user(4), 'probe.txt', 9);

        self::assertSame(422, $result['status']);
        self::assertSame(DestinationFailureCode::Unauthorized->value, $result['body']['code']);
    }

    public function testMissingWorkspaceFileIsNotFound(): void
    {
        $connection = $this->connection(12, 'OpenCloud', ['channel' => 'opencloud']);
        $workspaces = $this->createMock(ComputeWorkspaceService::class);
        $workspaces->method('downloadFile')->willThrowException(
            new ComputeRefusedException('workspace_not_found', 'There is no file-work folder yet.'),
        );

        $service = new WorkspaceFolderPushService(
            $this->config(true),
            $workspaces,
            $this->connections($connection),
            new DestinationRegistry([]),
        );

        $result = $service->push($this->user(4), 'gone.txt', 12);

        self::assertSame(404, $result['status']);
        self::assertSame(DestinationFailureCode::NotFound->value, $result['body']['code']);
        self::assertFalse($result['body']['success']);
    }

    public function testSidecarInternalErrorIsNotReportedAsAMissingFile(): void
    {
        $connection = $this->connection(12, 'OpenCloud', ['channel' => 'opencloud']);
        $workspaces = $this->createMock(ComputeWorkspaceService::class);
        $workspaces->method('downloadFile')->willThrowException(
            new ComputeRefusedException('internal_error', 'Compute sidecar returned HTTP 500'),
        );

        $service = new WorkspaceFolderPushService(
            $this->config(true),
            $workspaces,
            $this->connections($connection),
            new DestinationRegistry([]),
        );

        $result = $service->push($this->user(4), 'probe.txt', 12);

        self::assertSame(422, $result['status']);
        self::assertSame(DestinationFailureCode::Unreachable->value, $result['body']['code']);
        self::assertSame('internal_error', $result['body']['error']);
        self::assertSame('OpenCloud', $result['body']['context']['connection']);
    }

    public function testSidecarOutageCopiesNothing(): void
    {
        $connection = $this->connection(12, 'OpenCloud', ['channel' => 'opencloud']);
        $workspaces = $this->createMock(ComputeWorkspaceService::class);
        $workspaces->method('downloadFile')->willThrowException(
            new class extends \RuntimeException implements TransportExceptionInterface {
            },
        );

        $service = new WorkspaceFolderPushService(
            $this->config(true),
            $workspaces,
            $this->connections($connection),
            new DestinationRegistry([]),
        );

        $result = $service->push($this->user(4), 'probe.txt', 12);

        self::assertSame(503, $result['status']);
        self::assertSame('compute_unavailable', $result['body']['code']);
        self::assertStringContainsString('Nothing was copied', (string) $result['body']['message']);
    }

    /**
     * @param array{contents: string, mime: string, name: string}|null $download
     */
    private function service(
        bool $workspacesOn,
        ?Connection $connection,
        ?array $download,
        DestinationProvider $provider,
    ): WorkspaceFolderPushService {
        $workspaces = $this->createMock(ComputeWorkspaceService::class);
        if (null !== $download) {
            $workspaces->method('downloadFile')->willReturn($download);
        }

        return new WorkspaceFolderPushService(
            $this->config($workspacesOn),
            $workspaces,
            $this->connections($connection),
            new DestinationRegistry(['webdav' => $provider]),
        );
    }

    private function config(bool $on): ComputeConfig
    {
        $config = $this->createMock(ComputeConfig::class);
        $config->method('workspacesEnabled')->willReturn($on);

        return $config;
    }

    private function connections(?Connection $connection): ConnectionRepository
    {
        $repo = $this->createMock(ConnectionRepository::class);
        $repo->method('findByIdAndOwner')->willReturn($connection);

        return $repo;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function connection(int $id, string $name, array $config): Connection
    {
        $connection = new Connection(4, 'webdav', $name);
        $ref = new \ReflectionProperty(Connection::class, 'id');
        $ref->setValue($connection, $id);
        $connection->setConfig($config);

        return $connection;
    }

    private function user(int $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }
}
