<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Grandfather existing users OFF the new multi-task routing.
 *
 * The multi-task routing engine ships with a GLOBAL default of ON (BCONFIG
 * ownerId=0, group=MULTITASK, setting=ROUTING_ENABLED='1', seeded by
 * MultitaskConfigSeeder). That is what we want for OSS clones, fresh installs,
 * dev environments, and NEW signups — they get the new routing automatically.
 *
 * But on the LIVE platform we must NOT silently flip users who are mid-workflow
 * on the legacy routing. So this one-time data migration writes an explicit
 * per-user OFF row for every user that exists AT MIGRATION TIME. That row both
 * (a) pins them to the old behaviour and (b) is the switch they later flip ON
 * themselves via the settings UI.
 *
 * Idempotent: relies on the UNIQUE(BOWNERID, BGROUP, BSETTING) index
 * (uniq_config_owner_group_setting). INSERT IGNORE skips any user who already
 * has a row, so re-running the migration — or running it after some users have
 * already toggled their switch — never clobbers an existing value.
 *
 * Fresh-install / dev safety: on an empty DB the BUSER table has no rows yet
 * (DataFixtures load demo users AFTER migrations), so this writes ZERO rows and
 * everyone correctly inherits the global ON default.
 */
final class Version20260607000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Grandfather existing users to MULTITASK.ROUTING_ENABLED=off (new routing defaults ON for new/OSS installs)';
    }

    public function up(Schema $schema): void
    {
        // Guard: if neither table exists (unexpected), do nothing rather than fail.
        if (!$schema->hasTable('BUSER') || !$schema->hasTable('BCONFIG')) {
            return;
        }

        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
            SELECT u.BID, 'MULTITASK', 'ROUTING_ENABLED', '0'
            FROM BUSER u
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Remove the per-user grandfather rows. This also removes any per-user
        // ROUTING_ENABLED row a user set themselves — acceptable for a down
        // migration (reverts to the global default); the global ownerId=0 row
        // is left untouched.
        $this->addSql(<<<'SQL'
            DELETE FROM BCONFIG
            WHERE BGROUP = 'MULTITASK'
              AND BSETTING = 'ROUTING_ENABLED'
              AND BOWNERID > 0
        SQL);
    }
}
