<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Invitation codes are handed out by an administrator and are not secret
 * credentials: the administrator copies them again from the list. The code
 * is kept in BCODE next to the hash the lookup still uses. Codes created
 * before this migration have no BCODE and cannot be shown again.
 *
 * Galera-safe: raw idempotent addSql only, no Schema API.
 */
final class Version20261025150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the invitation code in BSIGNUP_CODES.BCODE so an administrator can show it again.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BSIGNUP_CODES ADD COLUMN IF NOT EXISTS BCODE VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BSIGNUP_CODES DROP COLUMN IF EXISTS BCODE');
    }
}
