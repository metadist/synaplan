<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create BMESSAGEDIGESTS — the authoritative store for per-message digest
 * lines (deep-memory index over key messages, mirrored into Qdrant).
 *
 * Galera-safe: raw idempotent DDL only, no Schema API (see AGENTS.md —
 * the production cluster's DBAL comparator throws on Schema introspection).
 */
final class Version20260827140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create BMESSAGEDIGESTS table for the message digest deep-memory index';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS BMESSAGEDIGESTS (
                    BID BIGINT NOT NULL,
                    BUSERID INT NOT NULL,
                    BCHATID INT NOT NULL DEFAULT 0,
                    BMESSAGEID BIGINT NOT NULL,
                    BTITLE VARCHAR(500) NOT NULL,
                    BCHANNEL VARCHAR(20) NOT NULL DEFAULT '',
                    BSOURCEDATE BIGINT NOT NULL DEFAULT 0,
                    BACTIVE TINYINT(1) NOT NULL DEFAULT 1,
                    BCREATED BIGINT NOT NULL DEFAULT 0,
                    PRIMARY KEY (BID),
                    UNIQUE KEY uniq_digest_user_message (BUSERID, BMESSAGEID),
                    INDEX idx_digest_user_active_date (BUSERID, BACTIVE, BSOURCEDATE)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
                SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BMESSAGEDIGESTS');
    }
}
