<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Which copy features the cloud app says it has. The menu lists a cloud
 * only when that list includes deliveries.
 *
 * Galera-safe: raw addSql only, no Schema API.
 */
final class Version20261020180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the copy features a linked cloud app reports.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BPLATFORMINSTANCES ADD COLUMN IF NOT EXISTS BAPPCAPABILITIES VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BPLATFORMINSTANCES DROP COLUMN IF EXISTS BAPPCAPABILITIES');
    }
}
