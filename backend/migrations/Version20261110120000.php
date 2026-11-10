<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A grant names the cloud account it was issued for. Databases that already
 * ran Version20261110100000 before this column existed get it here; a fresh
 * install already has it from that migration's CREATE TABLE. Galera-safe:
 * one idempotent ALTER, no Schema API.
 */
final class Version20261110120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Name the cloud account on issued OAuth tokens, codes and refresh tokens';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BOAUTHTOKENS ADD COLUMN IF NOT EXISTS BEXTERNALID VARCHAR(191) NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BOAUTHTOKENS DROP COLUMN IF EXISTS BEXTERNALID');
    }
}
