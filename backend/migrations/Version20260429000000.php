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
 * Idempotency: every statement uses `ALTER TABLE ... CHANGE col col <full-def>`, which
 * MariaDB treats as absolute — re-running the migration after it has already been
 * applied is a no-op at the SQL layer. The table / column existence checks below also
 * skip statements whose targets don't exist (e.g. the messenger_messages block is a
 * no-op on an install that disabled the doctrine transport before this migration).
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
        if ($schema->hasTable('BRAG') && $schema->getTable('BRAG')->hasColumn('BEMBED')) {
            $this->addSql("ALTER TABLE BRAG CHANGE BEMBED BEMBED VECTOR(1024) NOT NULL COMMENT ''");
        }

        // plugin_data — strip datetime_immutable DC2Type comments
        if ($schema->hasTable('plugin_data')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE plugin_data
                  CHANGE created_at created_at DATETIME NOT NULL COMMENT '',
                  CHANGE updated_at updated_at DATETIME NOT NULL COMMENT ''
            SQL);
        }

        // messenger_messages — strip datetime_immutable DC2Type comments. Guarded
        // so the migration is a no-op for installs that provision messenger via a
        // different transport (e.g. redis) and therefore never created the table.
        if ($schema->hasTable('messenger_messages')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE messenger_messages
                  CHANGE created_at   created_at   DATETIME NOT NULL      COMMENT '',
                  CHANGE available_at available_at DATETIME NOT NULL      COMMENT '',
                  CHANGE delivered_at delivered_at DATETIME DEFAULT NULL  COMMENT ''
            SQL);
        }

        // BUSELOG.BERROR — some legacy hand-crafted DBs have `LONGTEXT DEFAULT ''`
        // (baseline 20260417 correctly has no default). The entity no longer declares
        // a default, so `schema:validate` would flag drift on those installs. Dropping
        // the default is a metadata-only change with no effect on existing rows —
        // writers always pass an explicit value (see RateLimitService::recordUsage).
        if ($schema->hasTable('BUSELOG') && $schema->getTable('BUSELOG')->hasColumn('BERROR')) {
            $this->addSql('ALTER TABLE BUSELOG CHANGE BERROR BERROR LONGTEXT NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        // Restore the DBAL 3.x-era comments so a rollback reaches the state that a
        // DBAL 3.x app would have generated. Down is a no-op on a fresh DBAL 4.x-
        // created DB or on installs that never ran DBAL 3.x. BUSELOG.BERROR's
        // default is intentionally NOT restored — baseline 20260417 has no default
        // and that's the correct target for a rollback.
        if ($schema->hasTable('BRAG') && $schema->getTable('BRAG')->hasColumn('BEMBED')) {
            $this->addSql("ALTER TABLE BRAG CHANGE BEMBED BEMBED VECTOR(1024) NOT NULL COMMENT '(DC2Type:vector)'");
        }

        if ($schema->hasTable('plugin_data')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE plugin_data
                  CHANGE created_at created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                  CHANGE updated_at updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'
            SQL);
        }

        if ($schema->hasTable('messenger_messages')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE messenger_messages
                  CHANGE created_at   created_at   DATETIME NOT NULL      COMMENT '(DC2Type:datetime_immutable)',
                  CHANGE available_at available_at DATETIME NOT NULL      COMMENT '(DC2Type:datetime_immutable)',
                  CHANGE delivered_at delivered_at DATETIME DEFAULT NULL  COMMENT '(DC2Type:datetime_immutable)'
            SQL);
        }
    }
}
