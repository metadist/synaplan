<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remember which chats a person pinned, and when, so the Pinned category
 * survives a reload and another device.
 *
 * Galera-safe: raw addSql only, ADD COLUMN IF NOT EXISTS, no Schema API.
 */
final class Version20261002140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BPINNED and BPINNEDAT to BCHATS';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BCHATS ADD COLUMN IF NOT EXISTS BPINNED TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE BCHATS ADD COLUMN IF NOT EXISTS BPINNEDAT DATETIME NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BCHATS DROP COLUMN IF EXISTS BPINNEDAT');
        $this->addSql('ALTER TABLE BCHATS DROP COLUMN IF EXISTS BPINNED');
    }
}
