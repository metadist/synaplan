<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Flip the default to the multi-task planner for EVERYONE.
 *
 * Version20260607000000 grandfathered every user existing at that time to an
 * explicit per-user BCONFIG row MULTITASK/ROUTING_ENABLED='0' so the new
 * routing engine would not silently change their mid-workflow behaviour. The
 * engine has since proven itself (task cards, media combos, data nodes), and
 * keeping half the platform on the legacy single-topic router means combined
 * requests ("make a video invitation and write the schedule below it") lose
 * everything but their primary intent for exactly those users.
 *
 * This one-time data migration deletes the per-user OFF rows, so ALL users
 * inherit the global row again (BOWNERID=0, seeded '1' by
 * MultitaskConfigSeeder, falling back to the built-in ON default). Deleting —
 * rather than updating the rows to '1' — matters: it returns control to the
 * admin "Multi-task routing" master switch, which toggles the GLOBAL row and
 * would otherwise never reach users pinned by a per-user row.
 *
 * Only rows with BVALUE='0' are removed (exactly what the grandfather
 * migration wrote). A per-user '1' row an operator may have set by hand keeps
 * that user ON even if the global switch is later turned off.
 *
 * Comparator-free + idempotent (raw DELETE, no Schema reads) per the Galera
 * production rules in AGENTS.md — safe to run incrementally on the shared
 * prod cluster.
 */
final class Version20260706130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enable multi-task routing for grandfathered users (drop per-user MULTITASK.ROUTING_ENABLED=0 rows; global default ON applies to everyone)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM BCONFIG
            WHERE BGROUP = 'MULTITASK'
              AND BSETTING = 'ROUTING_ENABLED'
              AND BOWNERID > 0
              AND BVALUE = '0'
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Restore the grandfather state of Version20260607000000: pin every
        // existing user to an explicit OFF row. INSERT IGNORE relies on
        // UNIQUE(BOWNERID, BGROUP, BSETTING) so users with a surviving
        // per-user row are skipped.
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
            SELECT u.BID, 'MULTITASK', 'ROUTING_ENABLED', '0'
            FROM BUSER u
        SQL);
    }
}
