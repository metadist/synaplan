<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Repair step for the two-phase BKEYWORDS/BENABLED retirement.
 *
 * An earlier revision of Version20260608000000 dropped the two columns
 * outright; databases that already executed that revision (early dev/staging
 * installs) are missing them, while the phase-1 contract (see the reworked
 * Version20260608000000) keeps the columns in place until every node runs an
 * entity that no longer maps them. This migration converges both worlds:
 *
 *   - column missing (old revision ran)  → re-add it with the safe default
 *   - column present (phase-1 path)      → no-op
 *
 * The re-added columns carry only defaults (BKEYWORDS NULL, BENABLED 1) —
 * the previous contents belonged to the retired embedding-routing experiment
 * and are intentionally not restored. Phase 2 (a later release) drops the
 * columns together with the deprecated entity mapping.
 */
final class Version20260608130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ensure BPROMPTS.BKEYWORDS/BENABLED exist (phase-1 repair for installs that ran the early column drop)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // NOTE: deliberately no `$schema->hasTable()/hasColumn()` reads. Touching
        // the injected Schema forces doctrine/migrations' LazySchemaDiffProvider
        // to introspect + run the DBAL comparator, which throws TableDoesNotExist
        // on this production DB (MariaDB FK identifier-resolution quirk). MariaDB's
        // native `ADD COLUMN IF NOT EXISTS` gives the same "add only if missing"
        // semantics as raw, comparator-free SQL.
        $this->addSql('ALTER TABLE BPROMPTS ADD COLUMN IF NOT EXISTS BKEYWORDS LONGTEXT DEFAULT NULL AFTER BSELECTION_RULES');
        $this->addSql('ALTER TABLE BPROMPTS ADD COLUMN IF NOT EXISTS BENABLED TINYINT(1) NOT NULL DEFAULT 1 AFTER BKEYWORDS');
    }

    public function down(Schema $schema): void
    {
        // Intentionally a no-op: removing the columns again would re-create the
        // exact rolling-deploy breakage this repair exists to prevent.
        $this->warnIf(true, 'No-op down(): the columns stay until the phase-2 drop release.');
    }
}
