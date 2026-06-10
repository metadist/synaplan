<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Normalize Synapse Routing v2 columns so `doctrine:schema:validate`
 * stays green on every install.
 *
 * This migration ALTERS columns introduced by:
 *   - Version20260430000000  (BPROMPTS.BKEYWORDS)
 *   - Version20260430120000  (BREVECTORIZE_RUNS.{BSTATUS, BCOST_ESTIMATED_USD,
 *                            BSEVERITY, BCOST_ACTUAL_USD, BMODEL_FROM_ID,
 *                            BCHUNKS_*, BTOKENS_*, BSTARTED_AT, BFINISHED_AT,
 *                            BERROR})
 *
 * Why a separate migration instead of editing the originals:
 *   - The originals were already merged onto feat/synapse-routing and may
 *     have run on staging/dev databases. Per Doctrine policy ("never modify
 *     applied migrations") we never rewrite them in place — we ship a
 *     follow-up migration that brings every install to the same final
 *     state, regardless of the path it took to get there.
 *   - Each ALTER below is idempotent at the schema level: re-running it
 *     against a column that is already in the target shape produces the
 *     same column definition (MariaDB CHANGE COLUMN with identical type
 *     is a no-op). Safe to run on:
 *         * fresh installs (column was created with the wrong shape →
 *           normalized here)
 *         * existing installs that ran the originals (column normalized
 *           here)
 *         * any future install that ran an in-place corrected version
 *           (column already correct, MariaDB still emits the ALTER but
 *           does not rewrite data)
 *
 * Drift to fix:
 *   - BPROMPTS.BKEYWORDS  TEXT → LONGTEXT (Doctrine `type: 'text'` without
 *                                 length maps to LONGTEXT)
 *   - BREVECTORIZE_RUNS columns: align "DEFAULT … NOT NULL" ordering, use
 *                                NUMERIC instead of DECIMAL, and explicit
 *                                DEFAULT NULL on nullable INT columns —
 *                                so SchemaTool::getCreateSchemaSql() and
 *                                the actual column definitions match
 *                                byte-for-byte.
 *
 * Down() intentionally does nothing — there is no "less normalized" target
 * shape to roll back to that would not re-introduce the schema drift this
 * migration was created to fix.
 */
final class Version20260501000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalize Synapse Routing v2 columns to match Doctrine ORM mapping (BPROMPTS.BKEYWORDS, BREVECTORIZE_RUNS.*)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // BPROMPTS.BKEYWORDS — TEXT (max 64KB) → LONGTEXT (max 4GB).
        // The entity has `type: 'text'` without an explicit length which
        // Doctrine resolves to LONGTEXT for MariaDB.
        $this->addSql(<<<'SQL'
            ALTER TABLE BPROMPTS
                MODIFY COLUMN BKEYWORDS LONGTEXT DEFAULT NULL
        SQL);

        // BREVECTORIZE_RUNS — re-issue every column whose definition diverges
        // from what Doctrine emits. We use a single ALTER TABLE with multiple
        // CHANGE COLUMN clauses so MariaDB only rewrites the table once.
        // Each clause is idempotent: if the column is already in the target
        // shape, MariaDB still validates the new definition but does not
        // rewrite the row data.
        $this->addSql(<<<'SQL'
            ALTER TABLE BREVECTORIZE_RUNS
                MODIFY COLUMN BMODEL_FROM_ID INT DEFAULT NULL,
                MODIFY COLUMN BSTATUS VARCHAR(16) DEFAULT 'queued' NOT NULL,
                MODIFY COLUMN BCHUNKS_TOTAL INT DEFAULT NULL,
                MODIFY COLUMN BCHUNKS_PROCESSED INT DEFAULT 0 NOT NULL,
                MODIFY COLUMN BCHUNKS_FAILED INT DEFAULT 0 NOT NULL,
                MODIFY COLUMN BTOKENS_ESTIMATED BIGINT DEFAULT NULL,
                MODIFY COLUMN BTOKENS_PROCESSED BIGINT DEFAULT 0 NOT NULL,
                MODIFY COLUMN BCOST_ESTIMATED_USD NUMERIC(10, 4) DEFAULT NULL,
                MODIFY COLUMN BCOST_ACTUAL_USD NUMERIC(10, 4) DEFAULT '0.0000' NOT NULL,
                MODIFY COLUMN BSEVERITY VARCHAR(16) DEFAULT 'info' NOT NULL,
                MODIFY COLUMN BSTARTED_AT BIGINT DEFAULT NULL,
                MODIFY COLUMN BFINISHED_AT BIGINT DEFAULT NULL,
                MODIFY COLUMN BERROR LONGTEXT DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // No-op: the "old" definitions were drifty (Doctrine reported
        // them as out-of-sync). Rolling back would just re-introduce the
        // exact mismatch this migration was built to remove.
    }
}
