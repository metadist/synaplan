<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The listing decision lives on the pickup row, beside the accept counters,
 * so one update records both.
 *
 * Galera-safe: raw addSql only, ADD COLUMN IF NOT EXISTS, no Schema API.
 */
final class Version20261029120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store pickup listing decisions on BCLOUDPICKUPS';
    }

    public function isTransactional(): bool
    {
        // MariaDB commits DDL on its own. A wrapping transaction is already closed.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BCLOUDPICKUPS ADD COLUMN IF NOT EXISTS BDECISIONS LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BCLOUDPICKUPS DROP COLUMN IF EXISTS BDECISIONS');
    }
}
