<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One row per reason an account has a level. Unique per account and source
 * so a webhook and an administrator cannot overwrite each other.
 *
 * Galera-safe: raw addSql only, CREATE TABLE IF NOT EXISTS, no Schema API
 * and no foreign keys. Account deletion removes the rows.
 */
final class Version20261110160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BUSER_LEVEL_GRANTS, one level grant per account and source';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS BUSER_LEVEL_GRANTS (
            BID BIGINT AUTO_INCREMENT NOT NULL,
            BUSERID BIGINT NOT NULL,
            BSOURCE VARCHAR(16) NOT NULL,
            BLEVEL VARCHAR(32) NOT NULL,
            BREF VARCHAR(191) DEFAULT NULL,
            BCREATED BIGINT NOT NULL,
            BCREATEDBY BIGINT DEFAULT NULL,
            UNIQUE INDEX uniq_level_grant_user_source (BUSERID, BSOURCE),
            INDEX idx_level_grant_user (BUSERID),
            PRIMARY KEY (BID)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BUSER_LEVEL_GRANTS');
    }
}
