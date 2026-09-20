<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Destination;

use App\Entity\Connection;
use App\Service\Destination\CloudFolderTarget;
use PHPUnit\Framework\TestCase;

final class CloudFolderTargetTest extends TestCase
{
    public function testStoredNextcloudChannelWins(): void
    {
        $connection = $this->connection('webdav', 'Office files', ['channel' => 'nextcloud']);

        self::assertSame(CloudFolderTarget::NEXTCLOUD, CloudFolderTarget::kindFor($connection));
        self::assertTrue(CloudFolderTarget::isCloudFolder($connection));
    }

    public function testOpenCloudIsDetectedFromTheUrlWhenNoChannelIsStored(): void
    {
        $connection = $this->connection(
            'webdav',
            'Work files',
            ['base_url' => 'https://cloud.example.com/remote.php/webdav'],
        );
        // Name/url without the product word must not pretend to be a cloud.
        self::assertNull(CloudFolderTarget::kindFor($connection));

        $opencloud = $this->connection(
            'webdav',
            'Team drive',
            ['base_url' => 'https://opencloud.example.com/remote.php/webdav'],
        );
        self::assertSame(CloudFolderTarget::OPENCLOUD, CloudFolderTarget::kindFor($opencloud));
    }

    public function testGenericWebDavAndDropboxAreNotCloudFolders(): void
    {
        $folder = $this->connection('webdav', 'Archive', ['channel' => 'folder']);
        $dropbox = $this->connection(Connection::TYPE_DROPBOX, 'Dropbox', ['channel' => 'dropbox']);

        self::assertNull(CloudFolderTarget::kindFor($folder));
        self::assertNull(CloudFolderTarget::kindFor($dropbox));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function connection(string $type, string $name, array $config): Connection
    {
        $connection = new Connection(4, $type, $name);
        $connection->setConfig($config);

        return $connection;
    }
}
