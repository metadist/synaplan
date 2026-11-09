<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Synaplan as OAuth2 authorization server for clouds: one table for issued
 * tokens, codes and seen client assertions, and the public keys a cloud
 * authenticates with (private_key_jwt) on its client row.
 *
 * Galera-safe: raw addSql only, IF NOT EXISTS, no Schema API, no foreign
 * keys (see docs/MIGRATIONS.md).
 */
final class Version20261110100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create BOAUTHTOKENS and add BJWKS to BPLATFORMINSTANCES (OAuth2 for clouds)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BOAUTHTOKENS (
              BID VARCHAR(128) NOT NULL,
              BKIND VARCHAR(16) NOT NULL,
              BCLIENTID VARCHAR(191) NOT NULL,
              BUSERID BIGINT NULL,
              BSCOPES JSON NOT NULL,
              BPARENTID VARCHAR(128) NULL,
              BEXPIRES BIGINT NOT NULL,
              BREVOKED TINYINT(1) NOT NULL DEFAULT 0,
              PRIMARY KEY (BID),
              KEY idx_oauthtoken_client_user (BCLIENTID, BUSERID),
              KEY idx_oauthtoken_expires (BEXPIRES)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);
        $this->addSql('ALTER TABLE BPLATFORMINSTANCES ADD COLUMN IF NOT EXISTS BJWKS LONGTEXT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BOAUTHTOKENS');
        $this->addSql('ALTER TABLE BPLATFORMINSTANCES DROP COLUMN IF EXISTS BJWKS');
    }
}
