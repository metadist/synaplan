<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Saved Tasks + connection foundations (additive, Galera-safe).
 *
 * Tables:
 *   - BSAVEDTASKS / BSAVEDTASK_RUNS — persist a Task Prompt as a runnable task
 *   - BCONNECTIONS / BCREDENTIALS — connection registry + credential vault
 *
 * Sprint 3 scheduler columns (next_run_at, last_run_at, consecutive_failures,
 * chat_id) and the authored-graph JSON column are created now so later sprints
 * need no second migration. No Schema API introspection.
 *
 * Decided 2026-08-15 (master-plan checklist row 9): B* names, no ON DELETE CASCADE.
 */
final class Version20260815140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create BSAVEDTASKS, BSAVEDTASK_RUNS, BCONNECTIONS, BCREDENTIALS (additive)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BCREDENTIALS (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BOWNERID BIGINT NOT NULL,
              BKIND VARCHAR(32) NOT NULL,
              BSECRET LONGTEXT NOT NULL,
              BCREATED BIGINT NOT NULL,
              BUPDATED BIGINT NOT NULL,
              INDEX idx_credential_owner (BOWNERID),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BCONNECTIONS (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BOWNERID BIGINT NOT NULL,
              BTYPE VARCHAR(32) NOT NULL,
              BNAME VARCHAR(191) NOT NULL,
              BSTATUS VARCHAR(32) NOT NULL DEFAULT 'never_tested',
              BLASTCHECKED BIGINT DEFAULT NULL,
              BSCOPES JSON DEFAULT NULL,
              BCREDENTIALID BIGINT DEFAULT NULL,
              BCONFIG JSON DEFAULT NULL,
              BCREATED BIGINT NOT NULL,
              BUPDATED BIGINT NOT NULL,
              INDEX idx_connection_owner_type (BOWNERID, BTYPE),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BSAVEDTASKS (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BOWNERID BIGINT NOT NULL,
              BPROMPTID BIGINT NOT NULL,
              BNAME VARCHAR(191) NOT NULL,
              BENABLED TINYINT(1) NOT NULL DEFAULT 1,
              BTRIGGERTYPE VARCHAR(32) NOT NULL DEFAULT 'manual',
              BTRIGGERCONFIG JSON DEFAULT NULL,
              BGRAPH JSON DEFAULT NULL,
              BALLOWUNATTENDED TINYINT(1) NOT NULL DEFAULT 0,
              BCHATID BIGINT DEFAULT NULL,
              BNEXTRUNAT DATETIME DEFAULT NULL,
              BLASTRUNAT DATETIME DEFAULT NULL,
              BCONSECUTIVEFAILURES INT NOT NULL DEFAULT 0,
              BCREATED BIGINT NOT NULL,
              BUPDATED BIGINT NOT NULL,
              INDEX idx_saved_task_owner_enabled (BOWNERID, BENABLED),
              INDEX idx_saved_task_next_run (BNEXTRUNAT),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BSAVEDTASK_RUNS (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BSAVEDTASKID BIGINT NOT NULL,
              BSTATUS VARCHAR(16) NOT NULL DEFAULT 'queued',
              BTRIGGER VARCHAR(32) NOT NULL DEFAULT 'manual',
              BMESSAGEID BIGINT DEFAULT NULL,
              BPLANSNAPSHOT JSON DEFAULT NULL,
              BERROR TEXT DEFAULT NULL,
              BSTARTED DATETIME DEFAULT NULL,
              BFINISHED DATETIME DEFAULT NULL,
              BCREATED BIGINT NOT NULL,
              INDEX idx_saved_task_run_task_created (BSAVEDTASKID, BCREATED),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BSAVEDTASK_RUNS');
        $this->addSql('DROP TABLE IF EXISTS BSAVEDTASKS');
        $this->addSql('DROP TABLE IF EXISTS BCONNECTIONS');
        $this->addSql('DROP TABLE IF EXISTS BCREDENTIALS');
    }
}
