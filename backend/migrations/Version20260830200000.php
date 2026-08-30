<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create BDESKTOPDEVICES — the registry of computers a user has paired with
 * Synaplan Desktop (Sprint A2, DS5).
 *
 * Each row binds a paired computer to the scoped API key minted at pairing
 * ({@see \App\Security\ApiKeyScope::pairingScopes()}). Revoking a device
 * deletes/deactivates that key and flips BSTATUS to `revoked` — a stolen
 * laptop is a revoke, not an account takeover.
 *
 * No foreign key to BAPIKEYS in v1 (the app deletes the key row, then marks the
 * device revoked). The job table BDESKTOPJOBS (DS11) references BDEVICEID; a
 * later delete-devices migration must remove job rows first (no ON DELETE
 * CASCADE — Galera FKs on this cluster are limited by design, AGENTS.md).
 *
 * Galera-safe: raw idempotent DDL only, no Schema API (the production cluster's
 * DBAL comparator throws on Schema introspection — AGENTS.md).
 */
final class Version20260830200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create BDESKTOPDEVICES table for Synaplan Desktop paired-computer registry';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS BDESKTOPDEVICES (
                    BID BIGINT NOT NULL AUTO_INCREMENT,
                    BOWNERID BIGINT NOT NULL,
                    BNAME VARCHAR(128) NOT NULL DEFAULT '',
                    BAPIKEYID BIGINT NOT NULL,
                    BSTATUS VARCHAR(16) NOT NULL DEFAULT 'active',
                    BCAPABILITIES JSON NULL,
                    BLASTSEEN BIGINT NOT NULL DEFAULT 0,
                    BCREATED BIGINT NOT NULL,
                    PRIMARY KEY (BID),
                    KEY idx_desktop_owner (BOWNERID),
                    KEY idx_desktop_apikey (BAPIKEYID)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
                SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BDESKTOPDEVICES');
    }
}
