<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Wave 4: BAPPROVALS, BTOOLS, BSAVEDTASK_RUNS.BWAITINGNODE.
 *
 * Galera-safe: raw addSql only, IF NOT EXISTS, no Schema API.
 */
final class Version20260910120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BAPPROVALS, BTOOLS and BSAVEDTASK_RUNS.BWAITINGNODE for tool approvals';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS BAPPROVALS (
  BID BIGINT NOT NULL AUTO_INCREMENT,
  BOWNERID BIGINT NOT NULL,
  BREQUESTEDBY VARCHAR(96) NOT NULL,
  BTOOL VARCHAR(191) NOT NULL,
  BSIDEEFFECT VARCHAR(16) NOT NULL,
  BARGS JSON NULL,
  BPREVIEW LONGTEXT NULL,
  BSTATUS VARCHAR(16) NOT NULL DEFAULT 'pending',
  BEXPIRESAT BIGINT NOT NULL,
  BDECIDEDBY BIGINT NULL,
  BDECIDEDAT BIGINT NULL,
  BRESULTREF VARCHAR(191) NULL,
  BCREATED BIGINT NOT NULL,
  PRIMARY KEY (BID),
  KEY idx_approvals_owner_status (BOWNERID, BSTATUS),
  KEY idx_approvals_expires (BSTATUS, BEXPIRESAT)
)
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS BTOOLS (
  BID BIGINT NOT NULL AUTO_INCREMENT,
  BOWNERID BIGINT NOT NULL,
  BNAME VARCHAR(64) NOT NULL,
  BTITLE VARCHAR(191) NOT NULL,
  BDESCRIPTION LONGTEXT NULL,
  BTYPE VARCHAR(16) NOT NULL DEFAULT 'http',
  BSIDEEFFECT VARCHAR(16) NOT NULL DEFAULT 'write',
  BSPEC JSON NOT NULL,
  BINPUTSCHEMA JSON NULL,
  BCREDENTIALID BIGINT NULL,
  BENABLED TINYINT(1) NOT NULL DEFAULT 1,
  BSOURCEREF VARCHAR(512) NULL,
  BCREATED BIGINT NOT NULL,
  BUPDATED BIGINT NOT NULL,
  PRIMARY KEY (BID),
  UNIQUE KEY uq_tools_owner_name (BOWNERID, BNAME),
  KEY idx_tools_credential (BCREDENTIALID)
)
SQL);

        $this->addSql('ALTER TABLE BSAVEDTASK_RUNS ADD COLUMN IF NOT EXISTS BWAITINGNODE VARCHAR(64) NULL');
        $this->addSql('ALTER TABLE BSAVEDTASK_RUNS ADD INDEX IF NOT EXISTS idx_saved_task_runs_waiting (BSTATUS, BWAITINGNODE)');
        $this->addSql('ALTER TABLE BSAVEDTASK_RUNS MODIFY COLUMN BSTATUS VARCHAR(32) NOT NULL DEFAULT \'queued\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BSAVEDTASK_RUNS DROP INDEX IF EXISTS idx_saved_task_runs_waiting');
        $this->addSql('ALTER TABLE BSAVEDTASK_RUNS DROP COLUMN IF EXISTS BWAITINGNODE');
        $this->addSql('DROP TABLE IF EXISTS BTOOLS');
        $this->addSql('DROP TABLE IF EXISTS BAPPROVALS');
    }
}
