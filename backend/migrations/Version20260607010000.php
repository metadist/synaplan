<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create BMESSAGE_TASKS — persisted per-node state of a multi-task routing plan.
 *
 * Additive only: no existing table is touched. Existing flows ignore this table
 * entirely. It exists for observability, retries (/again), admin debugging, and
 * UI progress in later sprints. One row per plan node, keyed to the inbound
 * BMESSAGES row.
 *
 * Columns mirror the planning doc §4:
 *   - BNODEID      plan-local node id ("n1", "n2", …)
 *   - BCAPABILITY  the capability the node ran (extract_text, summarize, …)
 *   - BDEPENDSON   JSON list of node ids this node depends on
 *   - BSTATUS      pending | running | done | failed | skipped
 *   - BMODELID     nullable BMODELS id actually used (resolved by existing chain)
 *   - BRESULTREF   nullable JSON pointer to the node result (text ref / file id)
 *   - BERROR       nullable error detail for a failed node
 *   - BSTARTED / BFINISHED  nullable unix timestamps
 *
 * down() drops the table — it holds derived operational data, never the only
 * copy of user content (messages/files live in BMESSAGES / BMESSAGE_FILE_ATTACHMENTS).
 */
final class Version20260607010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create BMESSAGE_TASKS (additive) for multi-task routing plan persistence';
    }

    public function isTransactional(): bool
    {
        // MariaDB does not support DDL inside transactions.
        return false;
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('BMESSAGE_TASKS')) {
            return;
        }

        $this->addSql(<<<'SQL'
            CREATE TABLE BMESSAGE_TASKS (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BMESSAGEID BIGINT NOT NULL,
              BNODEID VARCHAR(16) NOT NULL,
              BCAPABILITY VARCHAR(32) NOT NULL,
              BDEPENDSON JSON DEFAULT NULL,
              BSTATUS VARCHAR(16) NOT NULL DEFAULT 'pending',
              BMODELID BIGINT DEFAULT NULL,
              BRESULTREF JSON DEFAULT NULL,
              BERROR TEXT DEFAULT NULL,
              BSTARTED BIGINT DEFAULT NULL,
              BFINISHED BIGINT DEFAULT NULL,
              INDEX idx_message_task_message (BMESSAGEID),
              INDEX idx_message_task_status (BSTATUS),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BMESSAGE_TASKS');
    }
}
