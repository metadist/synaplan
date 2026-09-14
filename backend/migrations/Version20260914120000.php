<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Wave 5 B2: BCOMPUTERUNS.BID must be signed BIGINT so doctrine:schema:validate
 * matches the ComputeRun mapping (same shape as BAPPROVALS and later tables).
 *
 * The first draft of Version20260913220000 used BIGINT UNSIGNED. Fresh installs
 * get the corrected CREATE; this ALTER covers databases that already applied
 * the unsigned column.
 *
 * Galera-safe: raw addSql only, no Schema API.
 */
final class Version20260914120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align BCOMPUTERUNS.BID with Doctrine bigint (signed, not UNSIGNED)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BCOMPUTERUNS MODIFY BID BIGINT NOT NULL AUTO_INCREMENT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BCOMPUTERUNS MODIFY BID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
    }
}
