<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Assistants on widgets (Wave 3 / Agent Builder S5).
 *
 * Galera-safe: raw addSql only, ADD COLUMN IF NOT EXISTS, no Schema API.
 */
final class Version20260909120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable BWIDGETS.BAGENTID';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BWIDGETS ADD COLUMN IF NOT EXISTS BAGENTID BIGINT NULL AFTER BTASKPROMPT');
        $this->addSql('ALTER TABLE BWIDGETS ADD INDEX IF NOT EXISTS idx_widgets_agent (BAGENTID)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BWIDGETS DROP INDEX IF EXISTS idx_widgets_agent');
        $this->addSql('ALTER TABLE BWIDGETS DROP COLUMN IF EXISTS BAGENTID');
    }
}
