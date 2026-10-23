<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A run asks a linked cloud for files. The read-scope mirror is what that
 * cloud last reported the person allows Synaplan to read.
 *
 * Galera-safe: raw addSql only, no Schema API.
 */
final class Version20261023120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the pickup ledger and the read-scope mirror.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BCLOUDPICKUPS (
                BID BIGINT AUTO_INCREMENT NOT NULL,
                BOWNERID BIGINT NOT NULL,
                BIDENTITYID BIGINT NOT NULL,
                BINSTANCEID VARCHAR(191) NOT NULL,
                BMODE VARCHAR(16) NOT NULL,
                BSELECTOR LONGTEXT NOT NULL,
                BSTATE VARCHAR(16) NOT NULL,
                BLISTED INT NOT NULL DEFAULT 0,
                BSENT INT NOT NULL DEFAULT 0,
                BSKIPPED LONGTEXT DEFAULT NULL,
                BFILEIDS LONGTEXT DEFAULT NULL,
                BNAMES LONGTEXT DEFAULT NULL,
                BERRORCODE VARCHAR(64) DEFAULT NULL,
                BCREATED BIGINT NOT NULL,
                BFINISHED BIGINT DEFAULT NULL,
                INDEX idx_pickup_owner_created (BOWNERID, BCREATED),
                PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BCLOUDPICKUPFILES (
                BID BIGINT AUTO_INCREMENT NOT NULL,
                BOWNERID BIGINT NOT NULL,
                BINSTANCEID VARCHAR(191) NOT NULL,
                BREMOTEID VARCHAR(255) NOT NULL,
                BVERSION VARCHAR(255) NOT NULL,
                BFILEID BIGINT NOT NULL,
                UNIQUE INDEX uniq_pickup_file_version (BOWNERID, BINSTANCEID, BREMOTEID, BVERSION),
                PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE BEXTERNALIDENTITIES ADD COLUMN IF NOT EXISTS BREADSCOPE LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BEXTERNALIDENTITIES DROP COLUMN IF EXISTS BREADSCOPE');
        $this->addSql('DROP TABLE IF EXISTS BCLOUDPICKUPFILES');
        $this->addSql('DROP TABLE IF EXISTS BCLOUDPICKUPS');
    }
}
