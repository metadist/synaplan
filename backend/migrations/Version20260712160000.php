<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Knowledge-file lifecycle primitives for external sync integrations
 * (hosting-partner CORE-4). Additive, nullable/defaulted, reversible.
 *
 * Lets an integrator (e.g. the Nextcloud app) keep a Synaplan knowledge file in
 * sync with an external source of truth without creating duplicates:
 *
 *  - BSOURCEID    stable external id (e.g. the Nextcloud file id) used to find
 *                 and overwrite the same logical file in place.
 *  - BSOURCEETAG  external version/etag captured at ingest; a differing etag on
 *                 re-report means the KB copy drifted and must be re-vectorized.
 *  - BSTALE       explicit "source changed, needs re-vectorize" marker. Stale
 *                 files stay searchable (old vectors remain) until re-vectorized;
 *                 honoured by the fix-on-read vector-state derivation so it is
 *                 not clobbered by a plain "has chunks => vectorized" pass.
 *
 * Galera-safe: raw idempotent addSql only, no Schema API (see AGENTS.md
 * "Production Platform Specifics").
 */
final class Version20260712160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BSOURCEID + BSOURCEETAG + BSTALE knowledge-file lifecycle columns to BFILES';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BFILES ADD COLUMN IF NOT EXISTS BSOURCEID VARCHAR(255) DEFAULT NULL AFTER BORIGINALNAME');
        $this->addSql('ALTER TABLE BFILES ADD COLUMN IF NOT EXISTS BSOURCEETAG VARCHAR(255) DEFAULT NULL AFTER BSOURCEID');
        $this->addSql('ALTER TABLE BFILES ADD COLUMN IF NOT EXISTS BSTALE TINYINT(1) NOT NULL DEFAULT 0 AFTER BSOURCEETAG');

        // Overwrite lookup lane: (user, source, source_id) resolves the same
        // logical file for in-place replacement and bulk stale checks.
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_file_user_source_sid ON BFILES (BUSERID, BSOURCE, BSOURCEID)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_file_user_source_sid ON BFILES');
        $this->addSql('ALTER TABLE BFILES DROP COLUMN IF EXISTS BSTALE');
        $this->addSql('ALTER TABLE BFILES DROP COLUMN IF EXISTS BSOURCEETAG');
        $this->addSql('ALTER TABLE BFILES DROP COLUMN IF EXISTS BSOURCEID');
    }
}
