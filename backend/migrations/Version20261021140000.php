<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BMCPSERVERS.BWRITEAUTO — this connection's mutating tools may run in chat
 * without an approval card. OFF by default. Destructive tools stay blocked.
 *
 * Comparator-free + idempotent (ADD COLUMN IF NOT EXISTS, no Schema reads)
 * per the Galera production rules in AGENTS.md.
 */
final class Version20261021140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BMCPSERVERS.BWRITEAUTO: skip the chat approval card for mutating MCP tools on this server';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BMCPSERVERS ADD COLUMN IF NOT EXISTS BWRITEAUTO TINYINT(1) DEFAULT 0 NOT NULL AFTER BALLOWWRITE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BMCPSERVERS DROP COLUMN IF EXISTS BWRITEAUTO');
    }
}
