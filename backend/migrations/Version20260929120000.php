<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-user Telegram bot (one row per owner). The bot token itself lives in
 * BCREDENTIALS; this table keeps the webhook key, pairing state, and the
 * Synaplan chat the thread belongs to.
 *
 * Galera-safe: raw addSql only, CREATE TABLE IF NOT EXISTS, no Schema API,
 * no foreign keys (see docs/MIGRATIONS.md).
 */
final class Version20260929120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create BTELEGRAMBOT (one Telegram bot per user)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BTELEGRAMBOT (
              BID BIGINT NOT NULL AUTO_INCREMENT,
              BOWNERID BIGINT NOT NULL,
              BBOTKEY VARCHAR(64) NOT NULL,
              BBOTID BIGINT NOT NULL,
              BBOTUSERNAME VARCHAR(64) NOT NULL,
              BCREDENTIALID BIGINT NULL,
              BSECRETHASH VARCHAR(64) NOT NULL,
              BPAIRCODE VARCHAR(16) NULL,
              BPAIRCODEHASH VARCHAR(64) NULL,
              BTGUSERID VARCHAR(32) NULL,
              BTGCHATID VARCHAR(32) NULL,
              BCHATID INT NULL,
              BSTATUS VARCHAR(24) NOT NULL,
              BERRORCODE VARCHAR(64) NULL,
              BCREATED BIGINT NOT NULL,
              BUPDATED BIGINT NOT NULL,
              PRIMARY KEY (BID),
              UNIQUE KEY uq_telegrambot_owner (BOWNERID),
              UNIQUE KEY uq_telegrambot_key (BBOTKEY)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BTELEGRAMBOT');
    }
}
