<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * More Nextcloud S1 (NC1): registered partner instances + Outlook allow-list row.
 *
 * Galera-safe: raw addSql only, CREATE TABLE IF NOT EXISTS, no Schema API,
 * no foreign keys (see docs/MIGRATIONS.md).
 */
final class Version20260907180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create BPLATFORMINSTANCES and seed the Outlook built-in allow-list';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BPLATFORMINSTANCES (
              BID BIGINT NOT NULL AUTO_INCREMENT,
              BCLIENT VARCHAR(32) NOT NULL,
              BINSTANCEID VARCHAR(64) NOT NULL,
              BHOST VARCHAR(255) NOT NULL,
              BSECRETHASH VARCHAR(255) NOT NULL DEFAULT '',
              BREDIRECTURIS JSON NULL,
              BREGISTEREDBY BIGINT NOT NULL DEFAULT 0,
              BSTATUS VARCHAR(16) NOT NULL DEFAULT 'pending',
              BCREATED BIGINT NOT NULL,
              BLASTSEEN BIGINT NOT NULL DEFAULT 0,
              PRIMARY KEY (BID),
              UNIQUE KEY uq_platform_instance (BINSTANCEID),
              KEY idx_platform_client_host (BCLIENT, BHOST)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO BPLATFORMINSTANCES (BCLIENT, BINSTANCEID, BHOST, BSECRETHASH, BREDIRECTURIS, BREGISTEREDBY, BSTATUS, BCREATED)
            SELECT 'outlook', 'outlook-builtin', '*', '', '["https://localhost","https://127.0.0.1","https://addin.synaplan.com","https://*.synaplan.com"]', 0, 'active', UNIX_TIMESTAMP()
            WHERE NOT EXISTS (SELECT 1 FROM BPLATFORMINSTANCES WHERE BINSTANCEID = 'outlook-builtin')
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BPLATFORMINSTANCES');
    }
}
