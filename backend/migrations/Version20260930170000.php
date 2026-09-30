<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Store the Telegram "/" menu version so existing bots re-register when the
 * menu shrinks to help-only (issue #2280).
 *
 * Galera-safe: raw ADD COLUMN IF NOT EXISTS, no Schema API.
 */
final class Version20260930170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BTELEGRAMBOT.BCOMMANDSMENUVERSION for slash-menu refresh';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BTELEGRAMBOT ADD COLUMN IF NOT EXISTS BCOMMANDSMENUVERSION INT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BTELEGRAMBOT DROP COLUMN IF EXISTS BCOMMANDSMENUVERSION');
    }
}
