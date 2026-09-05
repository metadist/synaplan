<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * IAM S1 tables: groups, memberships, audit log, external identities.
 *
 * Galera-safe: raw addSql only, CREATE TABLE IF NOT EXISTS, no Schema API,
 * no foreign keys (see docs/MIGRATIONS.md).
 *
 * Version is 20260905120000 because main already shipped
 * Version20260904120000 as the GPT-5.6 Sol fingerprint repair.
 */
final class Version20260905120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create BGROUPS, BGROUPMEMBERS, BAUDITLOG and BEXTERNALIDENTITIES (IAM S1)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BGROUPS (
              BID BIGINT NOT NULL AUTO_INCREMENT,
              BNAME VARCHAR(128) NOT NULL,
              BSLUG VARCHAR(128) NOT NULL,
              BDESCRIPTION VARCHAR(512) NOT NULL DEFAULT '',
              BKIND VARCHAR(16) NOT NULL DEFAULT 'manual',
              BEXTERNALSOURCE VARCHAR(191) NULL,
              BEXTERNALID VARCHAR(191) NULL,
              BPARENTID BIGINT NULL,
              BCREATED BIGINT NOT NULL,
              BUPDATED BIGINT NOT NULL,
              PRIMARY KEY (BID),
              UNIQUE KEY uniq_group_slug (BSLUG),
              UNIQUE KEY uniq_group_external (BEXTERNALSOURCE, BEXTERNALID),
              KEY idx_group_kind (BKIND)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BGROUPMEMBERS (
              BGROUPID BIGINT NOT NULL,
              BUSERID BIGINT NOT NULL,
              BROLE VARCHAR(16) NOT NULL DEFAULT 'member',
              BSOURCE VARCHAR(16) NOT NULL DEFAULT 'manual',
              BCREATED BIGINT NOT NULL,
              PRIMARY KEY (BGROUPID, BUSERID),
              KEY idx_groupmember_user (BUSERID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BAUDITLOG (
              BID BIGINT NOT NULL AUTO_INCREMENT,
              BACTORID BIGINT NOT NULL,
              BACTION VARCHAR(64) NOT NULL,
              BRESOURCEKIND VARCHAR(64) NOT NULL DEFAULT '',
              BRESOURCEID VARCHAR(191) NOT NULL DEFAULT '',
              BSUBJECT JSON NULL,
              BIP VARCHAR(45) NOT NULL DEFAULT '',
              BCREATED BIGINT NOT NULL,
              PRIMARY KEY (BID),
              KEY idx_audit_actor_created (BACTORID, BCREATED),
              KEY idx_audit_resource (BRESOURCEKIND, BRESOURCEID),
              KEY idx_audit_created (BCREATED)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BEXTERNALIDENTITIES (
              BID BIGINT NOT NULL AUTO_INCREMENT,
              BUSERID BIGINT NOT NULL,
              BSOURCE VARCHAR(191) NOT NULL,
              BINSTANCEID VARCHAR(191) NOT NULL DEFAULT '',
              BEXTERNALID VARCHAR(191) NOT NULL,
              BAPIKEYID BIGINT NULL,
              BCREATED BIGINT NOT NULL,
              BLASTSEEN BIGINT NOT NULL DEFAULT 0,
              PRIMARY KEY (BID),
              UNIQUE KEY uniq_extid_source (BSOURCE, BINSTANCEID, BEXTERNALID),
              KEY idx_extid_user (BUSERID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BEXTERNALIDENTITIES');
        $this->addSql('DROP TABLE IF EXISTS BAUDITLOG');
        $this->addSql('DROP TABLE IF EXISTS BGROUPMEMBERS');
        $this->addSql('DROP TABLE IF EXISTS BGROUPS');
    }
}
