<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove legacy `DC2Type` column comments so the schema matches DBAL 4.x expectations.
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
 * Only five columns carry stale comments:
 *   - BRAG.BEMBED                        (DC2Type:vector)
 *   - plugin_data.created_at/updated_at  (DC2Type:datetime_immutable)
 *   - messenger_messages.{created,available,delivered}_at (DC2Type:datetime_immutable)
 *
 * No data is touched; only column metadata.
 */
final class Version20260429000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Strip legacy DC2Type column comments so schema matches DBAL 4.x introspection (closes #824)';
    }

    public function isTransactional(): bool
    {
        // MariaDB does not support DDL inside transactions.
        return false;
    }

    public function up(Schema $schema): void
    {
        // BRAG.BEMBED — vector type comment is redundant in DBAL 4.x
        $this->addSql("ALTER TABLE BRAG CHANGE BEMBED BEMBED VECTOR(1024) NOT NULL COMMENT ''");

        // plugin_data — datetime_immutable columns
        $this->addSql(<<<'SQL'
            ALTER TABLE plugin_data
              CHANGE created_at created_at DATETIME NOT NULL COMMENT '',
              CHANGE updated_at updated_at DATETIME NOT NULL COMMENT ''
        SQL);

        // messenger_messages — datetime_immutable columns
        $this->addSql(<<<'SQL'
            ALTER TABLE messenger_messages
              CHANGE created_at   created_at   DATETIME NOT NULL      COMMENT '',
              CHANGE available_at available_at DATETIME NOT NULL      COMMENT '',
              CHANGE delivered_at delivered_at DATETIME DEFAULT NULL  COMMENT ''
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Restore the baseline comments so a rollback reaches the state that a DBAL 3.x
        // app would have generated. Down is a no-op on a fresh DBAL 4.x-created DB.
        $this->addSql("ALTER TABLE BRAG CHANGE BEMBED BEMBED VECTOR(1024) NOT NULL COMMENT '(DC2Type:vector)'");

        $this->addSql(<<<'SQL'
            ALTER TABLE plugin_data
              CHANGE created_at created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
              CHANGE updated_at updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE messenger_messages
              CHANGE created_at   created_at   DATETIME NOT NULL      COMMENT '(DC2Type:datetime_immutable)',
              CHANGE available_at available_at DATETIME NOT NULL      COMMENT '(DC2Type:datetime_immutable)',
              CHANGE delivered_at delivered_at DATETIME DEFAULT NULL  COMMENT '(DC2Type:datetime_immutable)'
        SQL);
    }
}
