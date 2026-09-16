<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * URL watch (Wave 3 companion): one saved page snapshot per owner+URL.
 *
 * Galera-safe: raw addSql only, CREATE TABLE IF NOT EXISTS, no Schema API,
 * no foreign keys (see docs/MIGRATIONS.md).
 */
final class Version20260908150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create BURLWATCHES (one snapshot per owner and URL)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BURLWATCHES (
              BID BIGINT NOT NULL AUTO_INCREMENT,
              BOWNERID BIGINT NOT NULL,
              BURL VARCHAR(2048) NOT NULL,
              BURLHASH VARCHAR(64) NOT NULL,
              BTITLE VARCHAR(512) NOT NULL DEFAULT '',
              BTEXT LONGTEXT NULL,
              BCONTENTHASH VARCHAR(64) NULL,
              BFETCHEDAT BIGINT NULL,
              BCREATED BIGINT NOT NULL,
              BUPDATED BIGINT NOT NULL,
              PRIMARY KEY (BID),
              UNIQUE KEY uq_urlwatch_owner_hash (BOWNERID, BURLHASH),
              KEY idx_urlwatch_owner (BOWNERID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BURLWATCHES');
    }
}
