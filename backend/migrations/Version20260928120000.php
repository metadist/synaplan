<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Skills a paired computer last reported, so "Run on this computer" can offer
 * them by name (issue #2185).
 *
 * Galera-safe: raw ADD COLUMN IF NOT EXISTS, no Schema API.
 */
final class Version20260928120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the skills a paired computer last reported';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BDESKTOPDEVICES ADD COLUMN IF NOT EXISTS BENABLEDSKILLS JSON NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BDESKTOPDEVICES DROP COLUMN IF EXISTS BENABLEDSKILLS');
    }
}
