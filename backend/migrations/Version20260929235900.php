<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One row per partner connection or unused invite.
 *
 * Galera-safe: raw addSql only, CREATE TABLE IF NOT EXISTS, no Schema API,
 * no foreign keys (see docs/MIGRATIONS.md).
 */
final class Version20260929235900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create BFEDERATIONPARTNER (partner connections and invites)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BFEDERATIONPARTNER (
              BID BIGINT NOT NULL AUTO_INCREMENT,
              BSTATUS VARCHAR(16) NOT NULL,
              BPEERDOMAIN VARCHAR(255) NULL,
              BPEERAPI VARCHAR(255) NULL,
              BPEERKEY VARCHAR(128) NULL,
              BPEERNAME VARCHAR(80) NULL,
              BTOKENHASH VARCHAR(64) NULL,
              BEXPIRES BIGINT NULL,
              BPAUSEDBY VARCHAR(16) NULL,
              BACCEPTEDBY BIGINT NULL,
              BCREATED BIGINT NOT NULL,
              BUPDATED BIGINT NOT NULL,
              PRIMARY KEY (BID),
              UNIQUE KEY uq_federation_partner_token (BTOKENHASH),
              INDEX idx_federation_partner_domain (BPEERDOMAIN)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BFEDERATIONPARTNER');
    }
}
