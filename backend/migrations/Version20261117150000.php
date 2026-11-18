<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MCP tool permissions: one JSON column on the connection holds the tools
 * whose choice (auto / approve / block) differs from the default.
 *
 * Galera-safe: raw addSql only, IF NOT EXISTS, no Schema API. No data is
 * migrated; an empty object means "all defaults".
 */
final class Version20261117150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BMCPSERVERS.BTOOLPOLICY (per-tool permission overrides)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE BMCPSERVERS ADD COLUMN IF NOT EXISTS BTOOLPOLICY LONGTEXT NOT NULL DEFAULT '{}'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BMCPSERVERS DROP COLUMN IF EXISTS BTOOLPOLICY');
    }
}
