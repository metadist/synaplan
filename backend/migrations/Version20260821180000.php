<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BMCPSERVERS OAuth mode — generic remote-MCP consent (Notion, Higgsfield, …).
 *
 * BAUTHMODE: none | bearer (default, today's static header) | oauth.
 * BOAUTH: encrypted JSON blob (discovered endpoints, DCR client_id, tokens).
 *
 * Comparator-free + idempotent (ADD COLUMN IF NOT EXISTS, no Schema reads)
 * per the Galera production rules in AGENTS.md.
 */
final class Version20260821180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BMCPSERVERS.BAUTHMODE and BMCPSERVERS.BOAUTH for outbound MCP OAuth connectors';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE BMCPSERVERS ADD COLUMN IF NOT EXISTS BAUTHMODE VARCHAR(16) DEFAULT 'bearer' NOT NULL AFTER BALLOWWRITE");
        $this->addSql("ALTER TABLE BMCPSERVERS ADD COLUMN IF NOT EXISTS BOAUTH LONGTEXT NOT NULL DEFAULT '' AFTER BAUTHMODE");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BMCPSERVERS DROP COLUMN IF EXISTS BOAUTH');
        $this->addSql('ALTER TABLE BMCPSERVERS DROP COLUMN IF EXISTS BAUTHMODE');
    }
}
