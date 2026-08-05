<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove legacy `DC2Type` column comments and reconcile BUSELOG.BERROR default so the
 * schema matches DBAL 4.x expectations on both fresh and legacy databases.
 *
 * Background: DBAL 3.x annotated mapped types (vector, datetime_immutable, etc.) in the
 * SQL schema with `COMMENT '(DC2Type:…)'`. DBAL 4.x no longer emits those comments and
 * treats them as drift when introspecting an existing database.
 *
 * Combined with `doctrine.yaml` now declaring `server_version: 'mariadb-12.2.2'` (which
 * finally routes introspection through `MariaDBPlatform` and kills the string-default
 * phantom diffs described in #824), this migration closes the last bit of drift so
 * `doctrine:schema:validate` can run without `--skip-sync` in CI.
 *
 * Columns touched:
 *   - BRAG.BEMBED                                              (strip DC2Type:vector)
 *   - plugin_data.created_at/updated_at                        (strip DC2Type:datetime_immutable)
 *   - messenger_messages.{created,available,delivered}_at      (strip DC2Type:datetime_immutable)
 *   - BUSELOG.BERROR                                           (drop `DEFAULT ''` if present on legacy DBs)
 *
 * Idempotency: every statement uses `ALTER TABLE IF EXISTS ... CHANGE COLUMN IF EXISTS`,
 * so re-running the migration converges to the same metadata and missing optional
 * tables or columns are skipped by MariaDB itself.
 *
 * Failure mode: since the migration cannot run in a transaction on MariaDB, a partial
 * failure in statement N leaves statements 1..N-1 applied. That's not an integrity
 * problem because each statement is itself idempotent (see above) — re-running the
 * migration converges to the target state regardless of where it stopped.
 *
 * No data is touched; only column metadata.
 */
final class Version20260429000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Strip legacy DC2Type column comments + drop legacy BUSELOG.BERROR default (closes #824)';
    }

    public function isTransactional(): bool
    {
        // MariaDB does not support DDL inside transactions.
        return false;
    }

    public function up(Schema $schema): void
    {
        // BRAG.BEMBED — strip vector DC2Type comment
        $this->addSql("ALTER TABLE IF EXISTS BRAG CHANGE COLUMN IF EXISTS BEMBED BEMBED VECTOR(1024) NOT NULL COMMENT ''");

        // plugin_data — strip datetime_immutable DC2Type comments
        $this->addSql(<<<'SQL'
            ALTER TABLE IF EXISTS plugin_data
              CHANGE COLUMN IF EXISTS created_at created_at DATETIME NOT NULL COMMENT '',
              CHANGE COLUMN IF EXISTS updated_at updated_at DATETIME NOT NULL COMMENT ''
        SQL);

        // messenger_messages — strip datetime_immutable DC2Type comments. Guarded
        // so the migration is a no-op for installs that provision messenger via a
        // different transport (e.g. redis) and therefore never created the table.
        $this->addSql(<<<'SQL'
            ALTER TABLE IF EXISTS messenger_messages
              CHANGE COLUMN IF EXISTS created_at   created_at   DATETIME NOT NULL      COMMENT '',
              CHANGE COLUMN IF EXISTS available_at available_at DATETIME NOT NULL      COMMENT '',
              CHANGE COLUMN IF EXISTS delivered_at delivered_at DATETIME DEFAULT NULL  COMMENT ''
        SQL);

        // BUSELOG.BERROR — some legacy hand-crafted DBs have `LONGTEXT DEFAULT ''`
        // (baseline 20260417 correctly has no default). The entity no longer declares
        // a default, so `schema:validate` would flag drift on those installs. Dropping
        // the default is a metadata-only change with no effect on existing rows —
        // writers always pass an explicit value (see RateLimitService::recordUsage).
        $this->addSql('ALTER TABLE IF EXISTS BUSELOG CHANGE COLUMN IF EXISTS BERROR BERROR LONGTEXT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Restore the DBAL 3.x-era comments so a rollback reaches the state that a
        // DBAL 3.x app would have generated. Down is a no-op on a fresh DBAL 4.x-
        // created DB or on installs that never ran DBAL 3.x. BUSELOG.BERROR's
        // default is intentionally NOT restored — baseline 20260417 has no default
        // and that's the correct target for a rollback.
        $this->addSql("ALTER TABLE IF EXISTS BRAG CHANGE COLUMN IF EXISTS BEMBED BEMBED VECTOR(1024) NOT NULL COMMENT '(DC2Type:vector)'");

        $this->addSql(<<<'SQL'
            ALTER TABLE IF EXISTS plugin_data
              CHANGE COLUMN IF EXISTS created_at created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
              CHANGE COLUMN IF EXISTS updated_at updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE IF EXISTS messenger_messages
              CHANGE COLUMN IF EXISTS created_at   created_at   DATETIME NOT NULL      COMMENT '(DC2Type:datetime_immutable)',
              CHANGE COLUMN IF EXISTS available_at available_at DATETIME NOT NULL      COMMENT '(DC2Type:datetime_immutable)',
              CHANGE COLUMN IF EXISTS delivered_at delivered_at DATETIME DEFAULT NULL  COMMENT '(DC2Type:datetime_immutable)'
        SQL);
    }
}
