<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Historical API completions were stored as API_CHAT. After #1878 the live
 * path writes MESSAGES, so lifetime/monthly MESSAGES quotas would ignore
 * those older rows until they are remapped.
 *
 * Galera-safe: raw UPDATE only, no Schema API.
 */
final class Version20260916140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remap historical API_CHAT usage rows to MESSAGES';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE BUSELOG SET BACTION = 'MESSAGES' WHERE BACTION = 'API_CHAT'");
    }

    public function down(Schema $schema): void
    {
        // Irreversible: post-#1878 MESSAGES rows include both chat and API.
    }
}
