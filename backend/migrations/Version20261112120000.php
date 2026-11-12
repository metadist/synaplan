<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Require sustained consecutive health-check failures before paging operators,
 * and remember whether a reported incident is still active so an unfixed key or
 * retired model does not re-page every 24 hours.
 *
 * Galera-safe: raw idempotent SQL only, no Schema API.
 */
final class Version20261112120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add consecutive failure counter and active-alert flag to BMODELHEALTH and seed MODELHEALTH.CONSECUTIVE_FAILURES.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BMODELHEALTH ADD COLUMN IF NOT EXISTS BCONSECUTIVEFAILURES INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE BMODELHEALTH ADD COLUMN IF NOT EXISTS BALERTACTIVE INT NOT NULL DEFAULT 0');

        $this->addSql(<<<'SQL'
            INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
            SELECT 0, 'MODELHEALTH', 'CONSECUTIVE_FAILURES', '5'
              FROM DUAL
             WHERE NOT EXISTS (
                 SELECT 1 FROM BCONFIG
                  WHERE BOWNERID = 0
                    AND BGROUP = 'MODELHEALTH'
                    AND BSETTING = 'CONSECUTIVE_FAILURES'
             )
            SQL);

        // Rows that already paged and have not recovered yet stay marked active
        // so the first check after deploy does not send a duplicate daily page.
        $this->addSql(<<<'SQL'
            UPDATE BMODELHEALTH
               SET BALERTACTIVE = 1,
                   BCONSECUTIVEFAILURES = 5
             WHERE BSTATE IN ('offline', 'degraded')
               AND BLASTALERTED > 0
               AND BLASTSUCCESS <= BLASTALERTED
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = 'MODELHEALTH' AND BSETTING = 'CONSECUTIVE_FAILURES'");
        $this->addSql('ALTER TABLE BMODELHEALTH DROP COLUMN IF EXISTS BALERTACTIVE');
        $this->addSql('ALTER TABLE BMODELHEALTH DROP COLUMN IF EXISTS BCONSECUTIVEFAILURES');
    }
}
