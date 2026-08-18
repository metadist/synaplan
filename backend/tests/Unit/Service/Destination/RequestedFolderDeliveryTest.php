<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Destination;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;
use App\Service\Connection\PlannerChannelCatalog;
use App\Service\Destination\DestinationProvider;
use App\Service\Destination\DestinationRegistry;
use App\Service\Destination\DestinationResult;
use App\Service\Destination\RequestedFolderDelivery;
use App\Service\Destination\ShareableFile;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class RequestedFolderDeliveryTest extends TestCase
{
    public function testDetectsExplicitNextcloudSaveRequests(): void
    {
        $delivery = $this->delivery();

        self::assertTrue($delivery->userAskedToSaveToFolder(
            'Erstelle das Bild einer Katze und lege es in meinen Nextcloud-Account'
        ));
        self::assertTrue($delivery->userAskedToSaveToFolder('save this image to my Nextcloud'));
        self::assertTrue($delivery->userAskedToSaveToFolder('create a document and put it into my Dropbox'));
        self::assertFalse($delivery->userAskedToSaveToFolder('Erstelle das Bild einer Katze'));
        self::assertFalse($delivery->userAskedToSaveToFolder('What is Nextcloud?'));
        self::assertFalse($delivery->userAskedToSaveToFolder('Do you support Dropbox?'));
    }

    public function testSendsToTheOnlyWebDavConnection(): void
    {
        $connection = $this->connection(7, 'nextcloud-Ordner (admin)');
        $provider = new class($connection) implements DestinationProvider {
            public function __construct(private Connection $connection)
            {
            }

            public function id(): string
            {
                return 'webdav';
            }

            public function send(ShareableFile $file, array $params): DestinationResult
            {
                TestCase::assertSame(7, $params['connection_id']);
                TestCase::assertSame('cat.png', $file->name);

                return DestinationResult::success('Synaplan/cat.png', ['connection' => $this->connection->getName()]);
            }
        };

        $dir = sys_get_temp_dir().'/folder_delivery_'.uniqid();
        mkdir($dir, 0777, true);
        $path = $dir.'/cat.png';
        file_put_contents($path, 'png');

        $result = $this->delivery([$connection], $provider)->send(1, [
            ['path' => $path, 'name' => 'cat.png'],
        ]);

        self::assertTrue($result['ok']);
        self::assertSame(1, $result['sent']);
        self::assertSame('nextcloud', $result['channel']);
        self::assertStringContainsString('nextcloud', $result['message']);

        @unlink($path);
        @rmdir($dir);
    }

    public function testResolvesTheNamedFolderChannel(): void
    {
        $nextcloud = $this->connection(7, 'nextcloud-Ordner (admin)', ['channel' => 'nextcloud']);
        $other = $this->connection(8, 'Archive', ['channel' => 'folder']);
        $provider = new class implements DestinationProvider {
            public function id(): string
            {
                return 'webdav';
            }

            public function send(ShareableFile $file, array $params): DestinationResult
            {
                TestCase::assertSame(7, $params['connection_id']);

                return DestinationResult::success('Synaplan/cat.png');
            }
        };

        $dir = sys_get_temp_dir().'/folder_delivery_'.uniqid();
        mkdir($dir, 0777, true);
        $path = $dir.'/cat.png';
        file_put_contents($path, 'png');

        $result = $this->delivery([$nextcloud, $other], $provider)->send(1, [
            ['path' => $path, 'name' => 'cat.png'],
        ], 'nextcloud');

        self::assertTrue($result['ok']);
        self::assertSame('nextcloud', $result['channel']);

        @unlink($path);
        @rmdir($dir);
    }

    public function testFailsWhenNoFolderIsConnected(): void
    {
        $result = $this->delivery([])->send(1, [['path' => '/tmp/x.png', 'name' => 'x.png']]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('no folder is connected', $result['message']);
    }

    /**
     * The provider is picked by connection TYPE: a `dropbox` connection must
     * go through the dropbox provider, never through webdav.
     */
    public function testRoutesADropboxChannelToTheDropboxProvider(): void
    {
        $dropbox = $this->connection(9, 'user@example.com', ['channel' => 'dropbox'], Connection::TYPE_DROPBOX);
        $nextcloud = $this->connection(7, 'nextcloud-Ordner (admin)', ['channel' => 'nextcloud']);

        $dropboxProvider = new class implements DestinationProvider {
            public bool $called = false;

            public function id(): string
            {
                return 'dropbox';
            }

            public function send(ShareableFile $file, array $params): DestinationResult
            {
                $this->called = true;
                TestCase::assertSame(9, $params['connection_id']);

                return DestinationResult::success('Synaplan/plan.docx', ['newName' => 'plan.docx']);
            }
        };

        $dir = sys_get_temp_dir().'/folder_delivery_'.uniqid();
        mkdir($dir, 0777, true);
        $path = $dir.'/plan.docx';
        file_put_contents($path, 'docx');

        $result = $this->delivery([$nextcloud, $dropbox], null, $dropboxProvider)->send(1, [
            ['path' => $path, 'name' => 'plan.docx'],
        ], 'dropbox');

        self::assertTrue($result['ok']);
        self::assertTrue($dropboxProvider->called);
        self::assertSame('dropbox', $result['channel']);

        @unlink($path);
        @rmdir($dir);
    }

    /**
     * @param list<Connection> $connections
     */
    private function delivery(array $connections = [], ?DestinationProvider $provider = null, ?DestinationProvider $dropboxProvider = null): RequestedFolderDelivery
    {
        $repo = $this->createMock(ConnectionRepository::class);
        $repo->method('findByOwner')->willReturn($connections);

        $webdav = $provider ?? new class implements DestinationProvider {
            public function id(): string
            {
                return 'webdav';
            }

            public function send(ShareableFile $file, array $params): DestinationResult
            {
                return DestinationResult::failure(\App\Service\Destination\DestinationFailureCode::Unreachable);
            }
        };

        $providers = [$webdav];
        if (null !== $dropboxProvider) {
            $providers[] = $dropboxProvider;
        }

        return new RequestedFolderDelivery(
            $repo,
            new DestinationRegistry($providers),
            $this->createMock(LoggerInterface::class),
            new PlannerChannelCatalog($repo),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function connection(int $id, string $name, array $config = [], string $type = 'webdav'): Connection
    {
        $connection = new Connection(1, $type, $name);
        $ref = new \ReflectionProperty(Connection::class, 'id');
        $ref->setValue($connection, $id);
        if ([] !== $config) {
            $connection->setConfig($config);
        }

        return $connection;
    }
}
