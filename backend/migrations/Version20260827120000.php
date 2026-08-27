<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BCHATSUMMARIES — durable store for the rolling conversation summary
 * (one row per chat, behind the Redis hot cache). Gives slow channels
 * (email, WhatsApp) continuity beyond the cache TTL.
 *
 * Comparator-free + idempotent (CREATE TABLE IF NOT EXISTS, no Schema reads)
 * per the Galera production rules in AGENTS.md. No FK constraint on BCHATID —
 * the delete path cleans up explicitly (ChatController::delete).
 */
final class Version20260827120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BCHATSUMMARIES for durable per-chat rolling conversation summaries';
    }

    public function isTransactional(): bool
    {
        // Raw DDL implicitly commits on MariaDB — opt out of the migration
        // transaction wrapper (same as the other CREATE TABLE migrations).
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BCHATSUMMARIES (
                BID INT AUTO_INCREMENT NOT NULL,
                BCHATID INT NOT NULL,
                BUSERID INT NOT NULL,
                BSUMMARY LONGTEXT NOT NULL,
                BUPTOMESSAGEID BIGINT NOT NULL,
                BSUMMARIZEDCOUNT INT NOT NULL,
                BFINGERPRINT VARCHAR(32) NOT NULL,
                BUPDATED INT NOT NULL,
                UNIQUE INDEX uniq_chatsummary_chat (BCHATID),
                INDEX idx_chatsummary_user (BUSERID),
                PRIMARY KEY (BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BCHATSUMMARIES');
    }
}
