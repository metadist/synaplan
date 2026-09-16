<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * IAM S2: share rows (who may view/use/edit/manage a resource).
 *
 * Galera-safe: raw addSql only, CREATE TABLE IF NOT EXISTS, no Schema API,
 * no foreign keys (see docs/MIGRATIONS.md).
 */
final class Version20260905140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create BSHARES (IAM S2 sharing MVP)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BSHARES (
              BID BIGINT NOT NULL AUTO_INCREMENT,
              BRESOURCEKIND VARCHAR(64) NOT NULL,
              BRESOURCEID VARCHAR(191) NOT NULL,
              BSUBJECTTYPE VARCHAR(16) NOT NULL,
              BSUBJECTID BIGINT NOT NULL DEFAULT 0,
              BPERMISSION VARCHAR(16) NOT NULL,
              BGRANTEDBY BIGINT NOT NULL,
              BCREATED BIGINT NOT NULL,
              PRIMARY KEY (BID),
              UNIQUE KEY uniq_share_subject (BRESOURCEKIND, BRESOURCEID, BSUBJECTTYPE, BSUBJECTID),
              KEY idx_share_lookup (BSUBJECTTYPE, BSUBJECTID, BRESOURCEKIND),
              KEY idx_share_grantedby (BGRANTEDBY)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);
        // Dev databases that ran an earlier draft of this migration lack the index.
        $this->addSql('ALTER TABLE BSHARES ADD INDEX IF NOT EXISTS idx_share_grantedby (BGRANTEDBY)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BSHARES');
    }
}
