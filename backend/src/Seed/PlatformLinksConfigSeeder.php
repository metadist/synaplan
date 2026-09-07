<?php

declare(strict_types=1);

namespace App\Seed;

use App\Entity\PlatformInstance;
use App\Service\PlatformLink\PlatformLinksConfig;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for platform links: the global flag (BCONFIG, ownerId=0)
 * and the built-in Outlook instance row that carries the Synamail relay
 * allow-list.
 *
 * Insert-if-missing only — operator overrides are never touched. The flag
 * seeds OFF (`0`) so existing installs stay unchanged until an operator
 * enables it. The Outlook row is also created by the migration; re-asserting
 * it here restores it after `doctrine:fixtures:load` purged the table
 * (dev/test) and heals an install where the row went missing.
 */
final readonly class PlatformLinksConfigSeeder
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function seed(): SeedResult
    {
        $rows = [
            ['ownerId' => 0, 'group' => PlatformLinksConfig::CONFIG_GROUP, 'setting' => PlatformLinksConfig::KEY_ENABLED, 'value' => '0'],
        ];

        $config = BConfigSeeder::insertIfMissing($this->connection, 'platform_links_config', $rows);
        $instances = $this->seedOutlookBuiltin();

        return new SeedResult(
            'platform_links',
            inserted: $config->inserted + $instances->inserted,
            skipped: $config->skipped + $instances->skipped,
        );
    }

    private function seedOutlookBuiltin(): SeedResult
    {
        $exists = $this->connection->fetchOne(
            'SELECT 1 FROM BPLATFORMINSTANCES WHERE BINSTANCEID = :id LIMIT 1',
            ['id' => PlatformInstance::OUTLOOK_BUILTIN_ID],
        );
        if (false !== $exists) {
            return new SeedResult('platform_instances', inserted: 0, skipped: 1);
        }

        $this->connection->executeStatement(
            'INSERT INTO BPLATFORMINSTANCES
                (BCLIENT, BINSTANCEID, BHOST, BSECRETHASH, BREDIRECTURIS, BREGISTEREDBY, BSTATUS, BCREATED, BLASTSEEN)
             VALUES (:client, :id, :host, :secret, :uris, 0, :status, :created, 0)',
            [
                'client' => PlatformInstance::CLIENT_OUTLOOK,
                'id' => PlatformInstance::OUTLOOK_BUILTIN_ID,
                'host' => '*',
                'secret' => '',
                'uris' => json_encode(PlatformInstance::OUTLOOK_BUILTIN_REDIRECT_URIS, \JSON_THROW_ON_ERROR),
                'status' => PlatformInstance::STATUS_ACTIVE,
                'created' => time(),
            ],
        );

        return new SeedResult('platform_instances', inserted: 1);
    }
}
