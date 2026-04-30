<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create BREVECTORIZE_RUNS — audit + live-status table for embedding-model
 * change runs.
 *
 * Each row represents one user-initiated re-vectorization triggered by a
 * VECTORIZE-default-model change. Rows are written by the admin "switch
 * embedding model" endpoint and updated incrementally by the
 * ReVectorizeJob handler as it processes batches.
 *
 * Why a dedicated table (not BCONFIG):
 *   - Live progress: `chunks_processed` / `tokens_processed` are updated
 *     thousands of times during a long re-index; `BCONFIG` would suffer
 *     from row-level lock contention and is shaped for low-write configs.
 *   - Audit trail: keeping every historical run lets the admin UI render
 *     "who switched what when, and how much it cost" — useful for cost
 *     accountability and for diagnosing a misbehaving auto-trigger.
 *   - Cooldown enforcement: the 1h cooldown query needs an index on
 *     `created` per scope, which is trivial here and awkward in BCONFIG.
 *
 * Down() drops the table — re-running the migration recreates it empty,
 * which is acceptable because run history is operational telemetry, not
 * irreplaceable user data.
 */
final class Version20260430120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create BREVECTORIZE_RUNS for embedding-model change audit + live status';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE BREVECTORIZE_RUNS (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BUSERID INT NOT NULL,
              BSCOPE VARCHAR(32) NOT NULL,
              BMODEL_FROM_ID INT NULL,
              BMODEL_TO_ID INT NOT NULL,
              BSTATUS VARCHAR(16) NOT NULL DEFAULT 'queued',
              BCHUNKS_TOTAL INT NULL,
              BCHUNKS_PROCESSED INT NOT NULL DEFAULT 0,
              BCHUNKS_FAILED INT NOT NULL DEFAULT 0,
              BTOKENS_ESTIMATED BIGINT NULL,
              BTOKENS_PROCESSED BIGINT NOT NULL DEFAULT 0,
              BCOST_ESTIMATED_USD DECIMAL(10,4) NULL,
              BCOST_ACTUAL_USD DECIMAL(10,4) NOT NULL DEFAULT 0,
              BSEVERITY VARCHAR(16) NOT NULL DEFAULT 'info',
              BSTARTED_AT BIGINT NULL,
              BFINISHED_AT BIGINT NULL,
              BCREATED BIGINT NOT NULL,
              BUPDATED BIGINT NOT NULL,
              BERROR LONGTEXT NULL,
              INDEX idx_revectorize_user (BUSERID),
              INDEX idx_revectorize_status (BSTATUS),
              INDEX idx_revectorize_scope_created (BSCOPE, BCREATED),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BREVECTORIZE_RUNS');
    }
}
