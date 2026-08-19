<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Model health monitoring: the BMODELHEALTH state table plus the MODELHEALTH
 * configuration defaults.
 *
 * Rolling success/failure counters stay in Redis (written on every AI call).
 * This table holds only what has to survive a cache flush, above all the
 * auto-disable provenance — without it nobody can tell a model an operator
 * switched off from one the automation retired.
 *
 * Written with raw idempotent SQL and no Schema API calls on purpose: the DBAL
 * comparator throws "There is no table with name ..." on the production Galera
 * cluster, so `$schema->hasTable()` / `createTable()` are off limits here.
 *
 * BCONFIG rows are inserted rather than left to the seeder because seeder
 * values are bootstrap-only and never reach installs that already exist.
 * AUTO_DISABLE_ENABLED ships as '0': alerting is safe from day one, retiring a
 * model on a heuristic is not, and it gets switched on by its own migration
 * once the thresholds have proven themselves against real traffic.
 */
final class Version20260819120000 extends AbstractMigration
{
    /**
     * Global (ownerId 0) defaults for the MODELHEALTH group.
     *
     * @var array<string, string>
     */
    private const DEFAULTS = [
        // Master switch for probing and traffic evaluation.
        'ENABLED' => '1',
        // Share of failing calls (percent) above which a model counts as degraded.
        'ERROR_RATE_PERCENT' => '50',
        // Below this many recorded calls the rate is noise and is ignored.
        'MIN_SAMPLE_SIZE' => '5',
        // Rolling window for the traffic counters, in minutes.
        'WINDOW_MINUTES' => '30',
        // How often the scheduler runs the free catalog probe, in minutes.
        'PROBE_INTERVAL_MINUTES' => '15',
        // At most one alert per model (and per provider) in this many minutes.
        'ALERT_THROTTLE_MINUTES' => '60',
        // Retiring a model automatically. Off until the thresholds are proven.
        'AUTO_DISABLE_ENABLED' => '0',
        // After a manual re-enable the automation only reports for this long.
        'SUPPRESSION_DAYS' => '7',
    ];

    public function getDescription(): string
    {
        return 'Add BMODELHEALTH state table and MODELHEALTH configuration defaults for AI model outage detection.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BMODELHEALTH (
                BID BIGINT AUTO_INCREMENT NOT NULL,
                BMODELID BIGINT NOT NULL,
                BSTATE VARCHAR(16) NOT NULL DEFAULT 'unknown',
                BSOURCE VARCHAR(16) NOT NULL DEFAULT 'probe',
                BKIND VARCHAR(16) DEFAULT NULL,
                BMESSAGE TEXT DEFAULT NULL,
                BLASTCHECK BIGINT NOT NULL DEFAULT 0,
                BLASTSUCCESS BIGINT NOT NULL DEFAULT 0,
                BLASTFAILURE BIGINT NOT NULL DEFAULT 0,
                BAUTODISABLED INT NOT NULL DEFAULT 0,
                BAUTODISABLEDAT BIGINT NOT NULL DEFAULT 0,
                BSUPPRESSUNTIL BIGINT NOT NULL DEFAULT 0,
                BUPDATED BIGINT NOT NULL DEFAULT 0,
                UNIQUE INDEX uniq_modelhealth_model (BMODELID),
                INDEX idx_modelhealth_state (BSTATE),
                PRIMARY KEY (BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        foreach (self::DEFAULTS as $setting => $value) {
            // INSERT ... SELECT ... WHERE NOT EXISTS keeps an operator's own
            // value intact on re-run, which ON DUPLICATE KEY UPDATE would not.
            $this->addSql(<<<'SQL'
                INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
                SELECT 0, 'MODELHEALTH', :setting, :value
                  FROM DUAL
                 WHERE NOT EXISTS (
                     SELECT 1 FROM BCONFIG
                      WHERE BOWNERID = 0 AND BGROUP = 'MODELHEALTH' AND BSETTING = :settingCheck
                 )
            SQL, [
                'setting' => $setting,
                'value' => $value,
                'settingCheck' => $setting,
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = 'MODELHEALTH'");
        $this->addSql('DROP TABLE IF EXISTS BMODELHEALTH');
    }
}
