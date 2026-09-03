<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Turn on the global structured-output flag (BCONFIG STRUCTURED_OUTPUT.ENABLED)
 * for every EXISTING installation, not just fresh ones.
 *
 * {@see \App\Seed\StructuredOutputConfigSeeder} inserts the same `1` row, but
 * BCONFIG defaults are bootstrap-only (AGENTS.md): the seeder only fills in a
 * MISSING row, so it seeds fresh OSS clones, dev, and new signups but never
 * reaches an install whose BCONFIG table pre-dates this rollout. A migration
 * is the documented way to roll a new default out to those installs too.
 *
 * `App\AI\StructuredOutput\StructuredOutputConfig::isEnabled()` already
 * defaults to `true` in code when no row exists at all, so this migration is
 * not strictly load-bearing for behaviour — its purpose is to make the switch
 * an explicit, visible, per-install-toggleable BCONFIG row (auditable in the
 * admin UI, flippable without a deploy) instead of an implicit code default
 * nobody can see or override without inserting a row by hand first.
 *
 * INSERT ... SELECT ... WHERE NOT EXISTS, not INSERT IGNORE: idempotent same
 * as {@see Version20260819120000}, and safe to re-run. Written as raw SQL
 * with no `Schema` object reads/writes, per the Galera production rules in
 * AGENTS.md (`$schema->hasTable()` throws on that cluster).
 */
final class Version20260901000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Insert the global BCONFIG STRUCTURED_OUTPUT.ENABLED=1 row for existing installations '
            .'(fresh installs already get it from StructuredOutputConfigSeeder).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
            SELECT 0, 'STRUCTURED_OUTPUT', 'ENABLED', '1'
              FROM DUAL
             WHERE NOT EXISTS (
                 SELECT 1 FROM BCONFIG
                  WHERE BOWNERID = 0 AND BGROUP = 'STRUCTURED_OUTPUT' AND BSETTING = 'ENABLED'
             )
        SQL);
    }

    /**
     * Intentionally irreversible: `StructuredOutputConfig::isEnabled()`
     * defaults to `true` in code with no row present, so deleting the row
     * would not even turn the feature off — it would just make the switch
     * invisible again. An operator who wants it off sets the row to `0`
     * explicitly instead of rolling this migration back.
     */
    public function down(Schema $schema): void
    {
        $this->warnIf(true, 'No-op: removing the row would not disable structured output (code default is ON) — set BVALUE=0 instead.');
    }
}
