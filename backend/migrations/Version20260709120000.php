<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Server-configurable plan pricing: add BSUBSCRIPTIONS.BCURRENCY.
 *
 * The public plans endpoint now reads display prices from BSUBSCRIPTIONS
 * (operator-editable in the admin panel) instead of hardcoded controller
 * values, so each deployment needs its own display currency.
 *
 * Comparator-free + idempotent (raw `IF NOT EXISTS` DDL, no Schema reads) per
 * the Galera production rules in AGENTS.md — safe to run incrementally on the
 * shared prod cluster.
 */
final class Version20260709120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BSUBSCRIPTIONS.BCURRENCY for server-configurable plan pricing';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE BSUBSCRIPTIONS
                ADD COLUMN IF NOT EXISTS BCURRENCY VARCHAR(3) NOT NULL DEFAULT 'EUR'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE BSUBSCRIPTIONS
                DROP COLUMN IF EXISTS BCURRENCY
        SQL);
    }
}
