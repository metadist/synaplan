<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cap the pumped run log. The code uses the same 16 MiB default when the
 * row is missing; the row lets an operator lower it without a deploy.
 *
 * Galera-safe: raw idempotent SQL only, no Schema API.
 */
final class Version20261115120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed RUNTIME.RUN_STREAM_MAX_BYTES at 16 MiB when the row is absent.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
            SELECT 0, 'RUNTIME', 'RUN_STREAM_MAX_BYTES', '16777216'
              FROM DUAL
             WHERE NOT EXISTS (
                 SELECT 1 FROM BCONFIG
                  WHERE BOWNERID = 0
                    AND BGROUP = 'RUNTIME'
                    AND BSETTING = 'RUN_STREAM_MAX_BYTES'
             )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = 'RUNTIME' AND BSETTING = 'RUN_STREAM_MAX_BYTES'");
    }
}
