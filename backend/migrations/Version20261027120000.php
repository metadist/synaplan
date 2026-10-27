<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A cloud file remembers which platform instance it came from, so the same
 * remote id on two clouds does not collide (CF2.9).
 *
 * Galera-safe: raw addSql only, no Schema API. The backfill scopes a row
 * only when its owner has exactly one identity for that cloud and that
 * identity names an instance.
 */
final class Version20261027120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remember which cloud instance a file came from.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BFILES ADD COLUMN IF NOT EXISTS BSOURCEINSTANCE VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_file_source_instance ON BFILES (BSOURCE, BSOURCEINSTANCE, BSOURCEID)');
        $this->addSql(<<<'SQL'
            UPDATE BFILES f
            INNER JOIN (
                SELECT BUSERID AS user_id, BSOURCE AS source, MIN(BINSTANCEID) AS instance_id
                FROM BEXTERNALIDENTITIES
                WHERE BSOURCE IN ('nextcloud', 'opencloud', 'owncloud')
                GROUP BY BUSERID, BSOURCE
                HAVING COUNT(*) = 1 AND MIN(BINSTANCEID) <> ''
            ) one ON one.user_id = f.BUSERID AND one.source = f.BSOURCE
            SET f.BSOURCEINSTANCE = one.instance_id
            WHERE f.BSOURCEINSTANCE IS NULL
              AND f.BSOURCE IN ('nextcloud', 'opencloud', 'owncloud')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_file_source_instance ON BFILES');
        $this->addSql('ALTER TABLE BFILES DROP COLUMN IF EXISTS BSOURCEINSTANCE');
    }
}
