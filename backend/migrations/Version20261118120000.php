<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 2 of retiring the per-connection write switches (BALLOWWRITE and
 * BWRITEAUTO). Per-tool permissions (BTOOLPOLICY) replaced them, and release
 * 5.8.8 no longer maps either column, so no running node selects them.
 *
 * Idempotent: DROP COLUMN IF EXISTS. Version20261117160000 reads BALLOWWRITE
 * and runs before this one.
 */
final class Version20261118120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop BMCPSERVERS.BWRITEAUTO and BMCPSERVERS.BALLOWWRITE (retired by per-tool permissions)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BMCPSERVERS DROP COLUMN IF EXISTS BWRITEAUTO');
        $this->addSql('ALTER TABLE BMCPSERVERS DROP COLUMN IF EXISTS BALLOWWRITE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BMCPSERVERS ADD COLUMN IF NOT EXISTS BALLOWWRITE TINYINT(1) DEFAULT 0 NOT NULL AFTER BENABLED');
        $this->addSql('ALTER TABLE BMCPSERVERS ADD COLUMN IF NOT EXISTS BWRITEAUTO TINYINT(1) DEFAULT 0 NOT NULL AFTER BALLOWWRITE');
    }
}
