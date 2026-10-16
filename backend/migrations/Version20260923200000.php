<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Make self-imported models selectable for chat (#2110).
 *
 * ModelImportApplier used to write imported rows with the entity defaults
 * BPRICEIN/BPRICEOUT = 0 and BSHOWWHENFREE = 0, which is exactly what
 * Model::isHiddenBecauseFree() hides from /config/models — the chat dropdown
 * and the Chat-Default picker. New imports now set BSHOWWHENFREE = 1 (same as
 * the seeded Ollama rows); this backfills rows imported before that fix.
 *
 * Idempotent: a repaired row has BSHOWWHENFREE = 1 and no longer matches.
 * Galera-safe: single raw UPDATE, no Schema API introspection.
 *
 * An operator who deliberately hid an imported row via BSHOWWHENFREE = 0
 * cannot be distinguished from the old default, so that row becomes visible
 * again and can be re-hidden in the admin UI. Hiding via BSELECTABLE/BACTIVE
 * is untouched.
 */
final class Version20260923200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Show self-imported free models in the model pickers (BSHOWWHENFREE=1 for imported rows, #2110)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE BMODELS
            SET BSHOWWHENFREE = 1
            WHERE BSHOWWHENFREE = 0
              AND BPRICEIN = 0
              AND BPRICEOUT = 0
              AND LOWER(BSERVICE) IN ('ollama', 'openaicompatible')
              AND JSON_EXTRACT(COALESCE(BJSON, '{}'), '$.meta.import.source') IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        // One-way visibility repair: there is no record of which rows an
        // operator had deliberately hidden, so down() must not re-hide them.
        $this->addSql('UPDATE BMODELS SET BSHOWWHENFREE = BSHOWWHENFREE WHERE 1 = 0');
    }
}
