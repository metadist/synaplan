<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The same model is reported at most once a day. The quiet period used to live
 * in Redis for 60 minutes and was wiped by every all-clear, so a model that
 * stayed offline (or flickered) paged Discord and email on every health check.
 *
 * BLASTALERTED survives a cache flush and is not cleared when the model
 * recovers. BLASTALERTEDKIND lets a worse problem of the same model (a
 * rejected key after a flaky hour) still be reported inside that period.
 * Existing installs keep a custom quiet period; only the shipped 60 minutes
 * becomes a day.
 *
 * Galera-safe: raw addSql only, ADD COLUMN IF NOT EXISTS, no Schema API.
 */
final class Version20261015170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Report each unavailable model at most once a day, and remember that on the health row.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BMODELHEALTH ADD COLUMN IF NOT EXISTS BLASTALERTED BIGINT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE BMODELHEALTH ADD COLUMN IF NOT EXISTS BLASTALERTEDKIND VARCHAR(16) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE BCONFIG SET BVALUE = '1440'
             WHERE BOWNERID = 0
               AND BGROUP = 'MODELHEALTH'
               AND BSETTING = 'ALERT_THROTTLE_MINUTES'
               AND BVALUE = '60'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE BCONFIG SET BVALUE = '60'
             WHERE BOWNERID = 0
               AND BGROUP = 'MODELHEALTH'
               AND BSETTING = 'ALERT_THROTTLE_MINUTES'
               AND BVALUE = '1440'
            SQL);
        $this->addSql('ALTER TABLE BMODELHEALTH DROP COLUMN IF EXISTS BLASTALERTEDKIND');
        $this->addSql('ALTER TABLE BMODELHEALTH DROP COLUMN IF EXISTS BLASTALERTED');
    }
}
