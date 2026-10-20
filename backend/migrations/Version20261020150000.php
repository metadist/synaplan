<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ledger for Copy to cloud. The delivery address is where Synaplan's servers
 * call the app; the registered host stays the address browsers use.
 *
 * Galera-safe: raw addSql only, no Schema API.
 */
final class Version20261020150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the copy-to-cloud ledger and the instance delivery address.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BCLOUDDELIVERIES (
                BID BIGINT AUTO_INCREMENT NOT NULL,
                BOWNERID BIGINT NOT NULL,
                BFILEID BIGINT NOT NULL,
                BIDENTITYID BIGINT NOT NULL,
                BTARGETKIND VARCHAR(32) NOT NULL,
                BTARGETREF VARCHAR(255) DEFAULT NULL,
                BONCONFLICT VARCHAR(16) NOT NULL,
                BSTATE VARCHAR(16) NOT NULL,
                BREMOTEPATH VARCHAR(1024) DEFAULT NULL,
                BREMOTEID VARCHAR(255) DEFAULT NULL,
                BOPENURL VARCHAR(1024) DEFAULT NULL,
                BERRORCODE VARCHAR(64) DEFAULT NULL,
                BRUNID BIGINT DEFAULT NULL,
                BWATCHID BIGINT DEFAULT NULL,
                BCREATED BIGINT NOT NULL,
                BFINISHED BIGINT DEFAULT NULL,
                INDEX idx_cloud_delivery_owner_file (BOWNERID, BFILEID),
                INDEX idx_cloud_delivery_state_created (BSTATE, BCREATED),
                PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE BPLATFORMINSTANCES ADD COLUMN IF NOT EXISTS BDELIVERYURL VARCHAR(512) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BCLOUDDELIVERIES');
        $this->addSql('ALTER TABLE BPLATFORMINSTANCES DROP COLUMN IF EXISTS BDELIVERYURL');
    }
}
