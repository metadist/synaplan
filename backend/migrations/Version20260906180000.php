<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * IAM S5: group policy layer and locked global defaults.
 *
 * Galera-safe: raw addSql only, CREATE TABLE IF NOT EXISTS, ADD COLUMN IF NOT EXISTS,
 * no Schema API, no foreign keys (see docs/MIGRATIONS.md).
 */
final class Version20260906180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create BGROUPCONFIG and add BCONFIG.BLOCKED (IAM S5 group policies)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BGROUPCONFIG (
              BID BIGINT NOT NULL AUTO_INCREMENT,
              BGROUPID BIGINT NOT NULL,
              BGROUP VARCHAR(64) NOT NULL,
              BSETTING VARCHAR(96) NOT NULL,
              BVALUE TEXT NOT NULL,
              BCREATED BIGINT NOT NULL,
              BUPDATED BIGINT NOT NULL,
              PRIMARY KEY (BID),
              UNIQUE KEY uniq_groupconfig_group_setting (BGROUPID, BGROUP, BSETTING),
              KEY idx_groupconfig_group (BGROUPID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);
        $this->addSql('ALTER TABLE BCONFIG ADD COLUMN IF NOT EXISTS BLOCKED TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BGROUPCONFIG');
        $this->addSql('ALTER TABLE BCONFIG DROP COLUMN IF EXISTS BLOCKED');
    }
}
