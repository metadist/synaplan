<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Persist the last URL-watch diff and the last failed check (#1805, #1806).
 *
 * Galera-safe: raw addSql only, ADD COLUMN IF NOT EXISTS, no Schema API.
 */
final class Version20260910200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add last-diff and last-failure columns to BURLWATCHES';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BURLWATCHES ADD COLUMN IF NOT EXISTS BLASTDIFFTEXT LONGTEXT NULL');
        $this->addSql('ALTER TABLE BURLWATCHES ADD COLUMN IF NOT EXISTS BLASTERROR VARCHAR(512) NULL');
        $this->addSql('ALTER TABLE BURLWATCHES ADD COLUMN IF NOT EXISTS BLASTFAILEDAT BIGINT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BURLWATCHES DROP COLUMN IF EXISTS BLASTDIFFTEXT');
        $this->addSql('ALTER TABLE BURLWATCHES DROP COLUMN IF EXISTS BLASTERROR');
        $this->addSql('ALTER TABLE BURLWATCHES DROP COLUMN IF EXISTS BLASTFAILEDAT');
    }
}
