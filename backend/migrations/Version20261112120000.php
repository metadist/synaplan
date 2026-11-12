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

        // Active (or auto-disabled), non-retired rows that already paged and
        // have not recovered yet stay latched so deploy does not re-page them.
        $this->addSql(<<<'SQL'
            UPDATE BMODELHEALTH h
              INNER JOIN BMODELS m ON m.BID = h.BMODELID
               SET h.BALERTACTIVE = 1,
                   h.BCONSECUTIVEFAILURES = 5
             WHERE h.BSTATE IN ('offline', 'degraded')
               AND h.BLASTALERTED > 0
               AND h.BLASTSUCCESS <= h.BLASTALERTED
               AND (m.BACTIVE = 1 OR h.BAUTODISABLED = 1)
               AND m.BRETIREDON IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = 'MODELHEALTH' AND BSETTING = 'CONSECUTIVE_FAILURES'");
        $this->addSql('ALTER TABLE BMODELHEALTH DROP COLUMN IF EXISTS BALERTACTIVE');
        $this->addSql('ALTER TABLE BMODELHEALTH DROP COLUMN IF EXISTS BCONSECUTIVEFAILURES');
    }
}
