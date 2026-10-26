<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Identity rows gain a mode, a status and the moment they were revoked
 * (CF2.2). The read-scope mirror is repeated so this table takes no later
 * migration: it already exists where CF3.6 ran.
 *
 * Existing rows: link where an instance and a key are set, provision for the
 * legacy shape, NULL for oidc: rows.
 *
 * Galera-safe: raw addSql only, no Schema API.
 */
final class Version20261024160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add identity mode, status and revoked-at, and backfill the mode of existing rows.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BEXTERNALIDENTITIES ADD COLUMN IF NOT EXISTS BMODE VARCHAR(16) DEFAULT NULL');
        $this->addSql("ALTER TABLE BEXTERNALIDENTITIES ADD COLUMN IF NOT EXISTS BSTATUS VARCHAR(16) NOT NULL DEFAULT 'active'");
        $this->addSql('ALTER TABLE BEXTERNALIDENTITIES ADD COLUMN IF NOT EXISTS BREVOKEDAT BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE BEXTERNALIDENTITIES ADD COLUMN IF NOT EXISTS BREADSCOPE LONGTEXT DEFAULT NULL');
        $this->addSql("UPDATE BEXTERNALIDENTITIES SET BMODE = 'link' WHERE BMODE IS NULL AND BINSTANCEID <> '' AND BAPIKEYID IS NOT NULL");
        $this->addSql("UPDATE BEXTERNALIDENTITIES SET BMODE = 'provision' WHERE BMODE IS NULL AND BINSTANCEID = '' AND BSOURCE NOT LIKE 'oidc:%'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BEXTERNALIDENTITIES DROP COLUMN IF EXISTS BREVOKEDAT');
        $this->addSql('ALTER TABLE BEXTERNALIDENTITIES DROP COLUMN IF EXISTS BSTATUS');
        $this->addSql('ALTER TABLE BEXTERNALIDENTITIES DROP COLUMN IF EXISTS BMODE');
    }
}
