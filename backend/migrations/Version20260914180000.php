<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Wave 5 B3: one persistent compute workspace per user + B3 policy defaults.
 *
 * Galera-safe: raw addSql only, CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS.
 */
final class Version20260914180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BCOMPUTEWORKSPACES and seed COMPUTE workspace/egress defaults';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS BCOMPUTEWORKSPACES (
  BID INT NOT NULL AUTO_INCREMENT,
  BUSERID INT NOT NULL,
  BWORKSPACEID VARCHAR(26) NOT NULL,
  BQUOTAMB INT NOT NULL,
  BUSEDMB INT NOT NULL DEFAULT 0,
  BSTATUS VARCHAR(16) NOT NULL DEFAULT 'active',
  BCREATED DATETIME NOT NULL,
  BLASTUSED DATETIME NULL,
  BEXPIRESAT DATETIME NULL,
  PRIMARY KEY (BID),
  UNIQUE KEY uq_computews_user (BUSERID),
  UNIQUE KEY uq_computews_wsid (BWORKSPACEID),
  KEY idx_computews_expires (BEXPIRESAT)
) DEFAULT CHARSET=utf8mb4
SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
            SELECT 0, 'COMPUTE', 'WORKSPACES_ENABLED', '0'
              FROM DUAL
             WHERE NOT EXISTS (
                 SELECT 1 FROM BCONFIG
                  WHERE BOWNERID = 0 AND BGROUP = 'COMPUTE' AND BSETTING = 'WORKSPACES_ENABLED'
             )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
            SELECT 0, 'COMPUTE', 'EGRESS_ENABLED', '0'
              FROM DUAL
             WHERE NOT EXISTS (
                 SELECT 1 FROM BCONFIG
                  WHERE BOWNERID = 0 AND BGROUP = 'COMPUTE' AND BSETTING = 'EGRESS_ENABLED'
             )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
            SELECT 0, 'COMPUTE', 'EGRESS_REQUIRES_APPROVAL', '1'
              FROM DUAL
             WHERE NOT EXISTS (
                 SELECT 1 FROM BCONFIG
                  WHERE BOWNERID = 0 AND BGROUP = 'COMPUTE' AND BSETTING = 'EGRESS_REQUIRES_APPROVAL'
             )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
            SELECT 0, 'COMPUTE', 'EGRESS_MAX_HOSTS', '8'
              FROM DUAL
             WHERE NOT EXISTS (
                 SELECT 1 FROM BCONFIG
                  WHERE BOWNERID = 0 AND BGROUP = 'COMPUTE' AND BSETTING = 'EGRESS_MAX_HOSTS'
             )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
            SELECT 0, 'COMPUTE', 'WORKSPACE_TTL_DAYS', '90'
              FROM DUAL
             WHERE NOT EXISTS (
                 SELECT 1 FROM BCONFIG
                  WHERE BOWNERID = 0 AND BGROUP = 'COMPUTE' AND BSETTING = 'WORKSPACE_TTL_DAYS'
             )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BCOMPUTEWORKSPACES');
    }
}
