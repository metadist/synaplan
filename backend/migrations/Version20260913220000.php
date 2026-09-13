<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Wave 5 B1: BCOMPUTERUNS audit table.
 *
 * Galera-safe: raw addSql only, CREATE TABLE IF NOT EXISTS, no Schema API.
 */
final class Version20260913220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BCOMPUTERUNS audit table for secure compute runs';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS BCOMPUTERUNS (
  BID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  BUSERID INT NOT NULL,
  BPROMPTID INT NULL,
  BMESSAGEID BIGINT NULL,
  BSAVEDTASKRUNID BIGINT NULL,
  BRUNID VARCHAR(26) NOT NULL,
  BINVOKEDVIA VARCHAR(32) NOT NULL,
  BIMAGE VARCHAR(32) NOT NULL,
  BPROGRAM VARCHAR(32) NOT NULL,
  BLIMITS JSON NOT NULL,
  BSTATUS VARCHAR(16) NOT NULL,
  BEXITCODE INT NULL,
  BREASON VARCHAR(32) NULL,
  BDURATIONMS INT NULL,
  BBYTESIN BIGINT NOT NULL DEFAULT 0,
  BBYTESOUT BIGINT NOT NULL DEFAULT 0,
  BARTEFACTIDS JSON NULL,
  BEGRESSHOSTS JSON NULL,
  BWORKSPACEID VARCHAR(26) NULL,
  BCREATED DATETIME NOT NULL,
  BFINISHED DATETIME NULL,
  PRIMARY KEY (BID),
  UNIQUE KEY uq_computerun_runid (BRUNID),
  KEY idx_computerun_user_created (BUSERID, BCREATED),
  KEY idx_computerun_status (BSTATUS)
) DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BCOMPUTERUNS');
    }
}
