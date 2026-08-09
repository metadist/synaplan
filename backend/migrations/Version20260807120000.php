<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bootstrap MESSAGES_GATEWAY BCONFIG defaults for existing installs.
 *
 * BCONFIG seeder values are bootstrap-only (insert-if-missing); this migration
 * uses the same INSERT IGNORE so a fresh install that already ran the seeder
 * is a no-op, while existing installs without the rows get them.
 *
 * Galera-safe: raw addSql only, no Schema API introspection.
 */
final class Version20260807120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed MESSAGES_GATEWAY BCONFIG defaults (Anthropic-compatible Messages API gateway, default OFF).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) VALUES
                (0, 'MESSAGES_GATEWAY', 'ENABLED', '0'),
                (0, 'MESSAGES_GATEWAY', 'ALLOW_OPERATOR_KEY', '0'),
                (0, 'MESSAGES_GATEWAY', 'MCP_TOOLS_ENABLED', '0'),
                (0, 'MESSAGES_GATEWAY', 'MCP_TOOLS_WITH_CLIENT_TOOLS', '0'),
                (0, 'MESSAGES_GATEWAY', 'MCP_MAX_ITERATIONS', '8'),
                (0, 'MESSAGES_GATEWAY', 'CONTEXT_INJECTION_ENABLED', '0'),
                (0, 'MESSAGES_GATEWAY', 'BUDGET_NOTICE_ENABLED', '1'),
                (0, 'MESSAGES_GATEWAY', 'SESSION_SUMMARY_ENABLED', '1'),
                (0, 'MESSAGES_GATEWAY', 'MODEL_ALIASES', '{}'),
                (0, 'MESSAGES_GATEWAY', 'UPSTREAM_URL', 'https://api.anthropic.com')
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "DELETE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = 'MESSAGES_GATEWAY'",
        );
    }
}
