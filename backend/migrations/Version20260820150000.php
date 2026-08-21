<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Give BMODELS a place to record WHY a model is inactive and WHAT replaces it.
 *
 * Until now a provider shutdown produced a hand-written migration that flipped
 * BACTIVE/BSELECTABLE to 0 and left no trace of the reasoning (#1515). The row
 * became indistinguishable from one an operator switched off on purpose, so:
 *
 *   - The next release could not tell "dead upstream" from "not wanted here",
 *     and the seeder had to preserve both, which is why three of the five
 *     retirement migrations existed only to clean up models a previous release
 *     had dropped from the catalog while leaving them active in every install.
 *   - A stored BID pointing at a retired model could not be resolved to its
 *     replacement, so every shutdown needed bespoke repointing SQL.
 *
 * Two nullable columns, no behavior change on their own:
 *
 *   BRETIREDON   DATE — the day the retirement shipped. NULL means "not
 *                       retired": an inactive row with NULL here was switched
 *                       off by an operator and must stay untouched.
 *   BSUCCESSORID INT  — BID that replaces it, or NULL when the provider shipped
 *                       no replacement (both are meaningful; see
 *                       {@see \App\Model\ModelCatalog}::RETIREMENTS).
 *
 * Backfilled from the catalog registry by ModelRetirementSeeder on the same
 * deploy, not here: the registry is the single source of truth and the seeder is
 * re-runnable, so an install that skips a release still converges.
 *
 * Galera notes: raw idempotent DDL only, never the Schema API — the DBAL
 * comparator throws "There is no table with name" against the production
 * cluster. BSUCCESSORID intentionally carries NO foreign key: successors are
 * themselves retired over time, and an FK would turn a future retirement into a
 * constraint failure on a table that BMESSAGES already pins.
 */
final class Version20260820150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BMODELS.BRETIREDON and BMODELS.BSUCCESSORID so model retirements carry their date and '
            .'replacement in data instead of a hand-written migration per provider shutdown.';
    }

    public function up(Schema $schema): void
    {
        // No column COMMENT on purpose: the entity mapping declares none, and
        // `doctrine:schema:validate` (a CI gate) reports a comment that only
        // exists in the database as schema drift. The documentation lives on
        // App\Entity\Model instead.
        $this->addSql('ALTER TABLE BMODELS ADD COLUMN IF NOT EXISTS BRETIREDON DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE BMODELS ADD COLUMN IF NOT EXISTS BSUCCESSORID INT DEFAULT NULL');

        // Retirement lookups are always "is this BID dead" (single-row, already
        // covered by the PK) or "list what died", which is a small scan today
        // but the column is the natural filter for the admin surface and the
        // resolution chain that follow.
        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS IDX_BMODELS_RETIREDON ON BMODELS (BRETIREDON)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS IDX_BMODELS_RETIREDON ON BMODELS');
        $this->addSql('ALTER TABLE BMODELS DROP COLUMN IF EXISTS BSUCCESSORID');
        $this->addSql('ALTER TABLE BMODELS DROP COLUMN IF EXISTS BRETIREDON');
    }
}
