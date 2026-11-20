<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Chat loop (PJ5a): seed `LOOP.ENABLED` off. A chat turn keeps running on
 * the classic path until an admin turns the loop on; a missing row is off
 * as well, so this only gives the switch a row to change.
 *
 * Galera-safe: raw idempotent SQL, no Schema API.
 */
final class Version20261120090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed LOOP.ENABLED off';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
            SELECT 0, 'LOOP', 'ENABLED', '0' FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = 'LOOP' AND BSETTING = 'ENABLED')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = 'LOOP' AND BSETTING = 'ENABLED'");
    }
}
