<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Connection;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;
use App\Service\Connection\PlannerChannel;
use App\Service\Connection\PlannerChannelCatalog;
use PHPUnit\Framework\TestCase;

final class PlannerChannelCatalogTest extends TestCase
{
    public function testPreferredKeyUsesProductNamesNotDisplayLabels(): void
    {
        self::assertSame('nextcloud', PlannerChannelCatalog::preferredKey(
            'webdav',
            'nextcloud-Ordner (admin)',
            ['base_url' => 'http://nextcloud/remote.php/dav/files/admin'],
        ));
        self::assertSame('folder', PlannerChannelCatalog::preferredKey('webdav', 'Archive', []));
        self::assertSame('calendar', PlannerChannelCatalog::preferredKey('caldav', 'Work calendar', []));
        self::assertSame('m365', PlannerChannelCatalog::preferredKey(Connection::TYPE_M365, 'Outlook', []));
    }

    public function testSanitizeMakesAPromptSafeSlug(): void
    {
        self::assertSame('nextcloud', PlannerChannelCatalog::sanitize(' NextCloud '));
        self::assertSame('my-folder', PlannerChannelCatalog::sanitize('My Folder!!!'));
        self::assertSame('', PlannerChannelCatalog::sanitize('***'));
    }

    public function testUniqueAppendsANumberOnCollision(): void
    {
        self::assertSame('nextcloud', PlannerChannelCatalog::unique('nextcloud', []));
        self::assertSame('nextcloud-2', PlannerChannelCatalog::unique('nextcloud', ['nextcloud']));
        self::assertSame('nextcloud-3', PlannerChannelCatalog::unique('nextcloud', ['nextcloud', 'nextcloud-2']));
    }

    public function testRendersQuotedChannelNamesForThePlanner(): void
    {
        $nextcloud = $this->connection(1, 'webdav', 'nextcloud-Ordner (admin)', ['channel' => 'nextcloud']);
        $calendar = $this->connection(2, 'caldav', 'personal', ['channel' => 'calendar']);

        $repo = $this->createMock(ConnectionRepository::class);
        $repo->method('findByOwner')->willReturn([$nextcloud, $calendar]);
        $repo->expects(self::never())->method('save');

        $catalog = new PlannerChannelCatalog($repo);

        $named = $catalog->find(1, 'nextcloud');
        self::assertNotNull($named);
        self::assertSame('nextcloud', $named->key);
        self::assertSame(PlannerChannel::KIND_FOLDER, $named->kind);
        self::assertNull($catalog->find(1, 'invented'));

        $rendered = $catalog->renderForPlanner(1);
        self::assertStringContainsString('- "nextcloud": folder', $rendered);
        self::assertStringContainsString('params.channel="nextcloud"', $rendered);
        self::assertStringContainsString('- "calendar": calendar', $rendered);
        self::assertSame('(none)', $catalog->renderForPlanner(null));
    }

    public function testPersistsADerivedKeyTheFirstTimeAConnectionIsListed(): void
    {
        $connection = $this->connection(1, 'webdav', 'nextcloud-Ordner (admin)');

        $repo = $this->createMock(ConnectionRepository::class);
        $repo->method('findByOwner')->willReturn([$connection]);
        $repo->expects(self::once())->method('save')->with($connection);

        $catalog = new PlannerChannelCatalog($repo);
        $channel = $catalog->find(1, 'nextcloud');

        self::assertNotNull($channel);
        self::assertSame('nextcloud', $connection->getConfig()['channel'] ?? null);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function connection(int $id, string $type, string $name, array $config = []): Connection
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
