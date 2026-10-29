<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A copy counts as sent only after the cloud took the bytes. The mark is the
 * moment the download ticket is consumed and the file is opened.
 *
 * Galera-safe: raw addSql only, no Schema API.
 */
final class Version20261029140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record when a cloud copy was actually downloaded';
    }

    public function isTransactional(): bool
    {
        // MariaDB implicit-commits DDL, so this statement cannot sit in a transaction.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BCLOUDDELIVERIES ADD COLUMN IF NOT EXISTS BDOWNLOADED BIGINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BCLOUDDELIVERIES DROP COLUMN IF EXISTS BDOWNLOADED');
    }
}
