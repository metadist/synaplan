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
 * would stay off without the upsert below.
 *
 * The upsert cannot tell an operator-set global `'0'` from the seeder `'0'`.
 * The flag shipped at the end of August, was never a user-facing switch, and
 * has no UI — flipping the global row is the intended rollout. Per-user rows
 * are left untouched so an individual opt-out survives.
 *
 * ONE `INSERT ... ON DUPLICATE KEY UPDATE`, not `INSERT ... SELECT ... WHERE
 * NOT EXISTS` plus a separate UPDATE: production runs migrations on every
 * backend container start of web1/web2/web3 against the same Galera schema, so
 * two nodes can pass `NOT EXISTS` at once and `uniq_config_owner_group_setting`
 * then fails the second INSERT with a duplicate key. The upsert is atomic,
 * covers the missing row and the `'0'` row in one statement, and is the form
 * AGENTS.md prescribes for seed-shaped DML. Written as raw SQL with no `Schema`
 * object reads/writes, per the Galera production rules in AGENTS.md
 * (`$schema->hasTable()` throws on that cluster).
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
            VALUES (0, 'FILE_CONTEXT', 'VISION_INCLUDE_GENERATED', '1')
            ON DUPLICATE KEY UPDATE BVALUE = '1'
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
