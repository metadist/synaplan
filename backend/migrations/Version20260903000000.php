<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Turn on FILE_CONTEXT.VISION_INCLUDE_GENERATED for existing installations
 * so a follow-up about a generated image can see the pixels (#1596).
 *
 * {@see \App\Seed\FileContextConfigSeeder} inserts the same `1` row, but
 * BCONFIG defaults are bootstrap-only (AGENTS.md): the seeder only fills in a
 * MISSING row. Installs that already have the Sprint-4 bootstrap value `'0'`
 * would stay off without the UPDATE below.
 *
 * The UPDATE cannot tell an operator-set global `'0'` from the seeder `'0'`.
 * The flag shipped at the end of August, was never a user-facing switch, and
 * has no UI — flipping the global row is the intended rollout. Per-user rows
 * are left untouched so an individual opt-out survives.
 *
 * INSERT ... SELECT ... WHERE NOT EXISTS, not INSERT IGNORE: idempotent same
 * as {@see Version20260901000000}, and safe to re-run. Written as raw SQL
 * with no `Schema` object reads/writes, per the Galera production rules in
 * AGENTS.md (`$schema->hasTable()` throws on that cluster).
 */
final class Version20260903000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enable FILE_CONTEXT.VISION_INCLUDE_GENERATED globally so generated '
            .'images are visible to the chat model on follow-up turns (#1596).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
            SELECT 0, 'FILE_CONTEXT', 'VISION_INCLUDE_GENERATED', '1'
              FROM DUAL
             WHERE NOT EXISTS (
                 SELECT 1 FROM BCONFIG
                  WHERE BOWNERID = 0 AND BGROUP = 'FILE_CONTEXT' AND BSETTING = 'VISION_INCLUDE_GENERATED'
             )
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE BCONFIG
               SET BVALUE = '1'
             WHERE BOWNERID = 0
               AND BGROUP = 'FILE_CONTEXT'
               AND BSETTING = 'VISION_INCLUDE_GENERATED'
               AND BVALUE = '0'
        SQL);
    }

    /**
     * Intentionally irreversible: {@see \App\Service\File\GeneratedImageVisionFlag}
     * now defaults to on when no row is present, so deleting the row would not
     * turn the feature off. An operator who wants it off sets BVALUE=0.
     */
    public function down(Schema $schema): void
    {
        $this->warnIf(true, 'No-op: removing the row would not disable generated-image vision (code default is ON) — set BVALUE=0 instead.');
    }
}
