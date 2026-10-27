<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One increasing revision per cloud, so the watch index can tell "changed"
 * apart from "seen in the same second", and a tombstone that stays after the
 * account — and its watches — are erased.
 */
final class Version20261027193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a monotonic watch-index revision and tombstones that survive account erasure';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BCLOUDWATCHES ADD COLUMN IF NOT EXISTS BREVISION BIGINT NOT NULL DEFAULT 0');
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BCLOUDWATCHFEED (
              BINSTANCEID VARCHAR(191) NOT NULL,
              BREVISION BIGINT NOT NULL DEFAULT 0,
              PRIMARY KEY (BINSTANCEID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BCLOUDWATCHTOMBSTONES (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BINSTANCEID VARCHAR(191) NOT NULL,
              BWATCHID BIGINT NOT NULL,
              BREVISION BIGINT NOT NULL,
              BCREATED BIGINT NOT NULL,
              INDEX idx_cloudwatch_tombstone_feed (BINSTANCEID, BREVISION),
              PRIMARY KEY (BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BCLOUDWATCHTOMBSTONES');
        $this->addSql('DROP TABLE IF EXISTS BCLOUDWATCHFEED');
        $this->addSql('ALTER TABLE BCLOUDWATCHES DROP COLUMN IF EXISTS BREVISION');
    }
}
