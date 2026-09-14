<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Wave 5 B2: approval id on compute runs + policy defaults for existing installs.
 *
 * Galera-safe: raw addSql only, ADD COLUMN IF NOT EXISTS, INSERT … WHERE NOT EXISTS.
 */
final class Version20260914010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BCOMPUTERUNS.BAPPROVALID and seed COMPUTE policy defaults';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BCOMPUTERUNS ADD COLUMN IF NOT EXISTS BAPPROVALID BIGINT NULL');
        $this->addSql(<<<'SQL'
            INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
            SELECT 0, 'COMPUTE', 'POLICY_INTERACTIVE', 'auto'
              FROM DUAL
             WHERE NOT EXISTS (
                 SELECT 1 FROM BCONFIG
                  WHERE BOWNERID = 0 AND BGROUP = 'COMPUTE' AND BSETTING = 'POLICY_INTERACTIVE'
             )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
            SELECT 0, 'COMPUTE', 'POLICY_UNATTENDED', 'approve'
              FROM DUAL
             WHERE NOT EXISTS (
                 SELECT 1 FROM BCONFIG
                  WHERE BOWNERID = 0 AND BGROUP = 'COMPUTE' AND BSETTING = 'POLICY_UNATTENDED'
             )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BCOMPUTERUNS DROP COLUMN IF EXISTS BAPPROVALID');
    }
}
