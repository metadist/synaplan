<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfill BCONFIG SETUP.COMPLETED so no EXISTING installation ever sees the
 * first-run setup wizard.
 *
 * The distinction is precise, not heuristic: on a genuine new installation the
 * whole migration chain runs against an EMPTY database, on an upgrade against a
 * populated one. Two signs of prior use are checked:
 *
 *   - any BUSER row (the normal case), and
 *   - any stored provider key (BCONFIG group `provider_keys`), which catches the
 *     edge case "instance was running but never had a user". Provider keys are
 *     only ever written at runtime by ProviderKeyStore, so on a new install the
 *     row cannot exist at migration time.
 *
 * Only BUSER and BCONFIG are touched; both exist since the initial migration.
 * Pulling in more tables would only add the risk of hitting a table an older
 * schema does not have.
 *
 * The condition is evaluated in PHP against `$this->connection` instead of a
 * self-referencing INSERT ... SELECT, because BCONFIG has NO unique index on
 * (BOWNERID, BGROUP, BSETTING) — only `idx_config_lookup` — so INSERT IGNORE
 * would not deduplicate. No reads of the injected `Schema` object, per the
 * Galera production rules in AGENTS.md.
 */
final class Version20260825120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mark existing installations as setup-complete so the first-run wizard only appears on a virgin install';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        if ($this->isSetupFlagPresent()) {
            $this->warnIf(true, 'BCONFIG SETUP.COMPLETED already present — nothing to backfill.');

            return;
        }

        if (!$this->hasSignsOfPriorUse()) {
            $this->warnIf(true, 'Empty database (no users, no provider keys) — leaving the first-run setup window open.');

            return;
        }

        $this->addSql(
            "INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) VALUES (0, 'SETUP', 'COMPLETED', '1')"
        );
    }

    /**
     * Intentionally irreversible: dropping the flag on a rollback would reopen
     * the setup window on a live installation, which is exactly the takeover
     * scenario this migration exists to prevent.
     */
    public function down(Schema $schema): void
    {
        $this->warnIf(true, 'No-op: removing SETUP.COMPLETED would reopen the first-run setup window on a live installation.');
    }

    private function isSetupFlagPresent(): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT EXISTS(SELECT 1 FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = 'SETUP' AND BSETTING = 'COMPLETED')"
        );
    }

    private function hasSignsOfPriorUse(): bool
    {
        if ((bool) $this->connection->fetchOne('SELECT EXISTS(SELECT 1 FROM BUSER)')) {
            return true;
        }

        return (bool) $this->connection->fetchOne(
            "SELECT EXISTS(SELECT 1 FROM BCONFIG WHERE BGROUP = 'provider_keys')"
        );
    }
}
