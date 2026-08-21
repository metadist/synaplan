<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BMCPSERVERS.BALLOWWRITE — per-server opt-in that lets the multitask
 * `mcp_action` capability call MUTATING tools (create Confluence pages, create
 * Jira tickets, …) on that server. Default OFF: every existing and new server
 * stays read-only (`mcp_fetch`) until the owner explicitly enables writes.
 *
 * Comparator-free + idempotent (ADD COLUMN IF NOT EXISTS, no Schema reads)
 * per the Galera production rules in AGENTS.md.
 */
final class Version20260821050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BMCPSERVERS.BALLOWWRITE: per-server opt-in for mutating MCP tool calls (mcp_action)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BMCPSERVERS ADD COLUMN IF NOT EXISTS BALLOWWRITE TINYINT(1) DEFAULT 0 NOT NULL AFTER BENABLED');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BMCPSERVERS DROP COLUMN IF EXISTS BALLOWWRITE');
    }
}
