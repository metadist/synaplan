<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A cloud file can be hidden when its source is deleted, and remembered as
 * "keep up to date". Raw SQL: this cluster rejects the schema comparator.
 */
final class Version20261027140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BFILES.BSOURCEDELETEDAT and BFILES.BKEEPSYNCED for cloud file state';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BFILES ADD COLUMN IF NOT EXISTS BSOURCEDELETEDAT BIGINT NULL');
        $this->addSql('ALTER TABLE BFILES ADD COLUMN IF NOT EXISTS BKEEPSYNCED TINYINT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BFILES DROP COLUMN IF EXISTS BKEEPSYNCED');
        $this->addSql('ALTER TABLE BFILES DROP COLUMN IF EXISTS BSOURCEDELETEDAT');
    }
}
