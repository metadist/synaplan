<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BMCPSERVERS — per-user registry of EXTERNAL MCP servers for the outbound
 * MCP client (release-4.0 plan 09 §3.2). Auth header values are stored
 * encrypted (AES-256-CBC via EncryptionService), mirroring the
 * BINBOUNDEMAILHANDLER credential pattern.
 *
 * Comparator-free + idempotent (CREATE TABLE IF NOT EXISTS, no Schema reads)
 * per the Galera production rules in AGENTS.md — safe to run
 * incrementally on the shared prod cluster.
 */
final class Version20260702180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BMCPSERVERS table: per-user external MCP server connections (outbound MCP client)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BMCPSERVERS (
                BID BIGINT AUTO_INCREMENT NOT NULL,
                BUSERID BIGINT NOT NULL,
                BNAME VARCHAR(255) NOT NULL,
                BURL VARCHAR(1024) NOT NULL,
                BAUTHHEADER VARCHAR(128) DEFAULT '' NOT NULL,
                BAUTHTOKEN LONGTEXT NOT NULL,
                BENABLED TINYINT(1) DEFAULT 1 NOT NULL,
                BCREATED VARCHAR(20) NOT NULL,
                BUPDATED VARCHAR(20) NOT NULL,
                INDEX idx_mcp_user (BUSERID),
                PRIMARY KEY (BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BMCPSERVERS');
    }
}
