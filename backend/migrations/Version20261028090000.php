<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notion app: the pages Synaplan changed (BNOTIONACTIVITY) and the pages a
 * person watches or keeps searchable (BNOTIONWATCHES).
 *
 * Galera-safe: raw addSql only, CREATE TABLE IF NOT EXISTS, no Schema API
 * and no foreign keys.
 */
final class Version20261028090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create BNOTIONACTIVITY and BNOTIONWATCHES for the Notion app';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BNOTIONACTIVITY (
                BID BIGINT AUTO_INCREMENT NOT NULL,
                BUSERID BIGINT NOT NULL,
                BACTION VARCHAR(16) NOT NULL,
                BPAGEID VARCHAR(64) DEFAULT NULL,
                BTITLE VARCHAR(255) NOT NULL DEFAULT '',
                BURL VARCHAR(1024) DEFAULT NULL,
                BCREATED BIGINT NOT NULL,
                INDEX idx_notionactivity_user (BUSERID, BCREATED),
                PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BNOTIONWATCHES (
                BID BIGINT AUTO_INCREMENT NOT NULL,
                BUSERID BIGINT NOT NULL,
                BPAGEID VARCHAR(64) NOT NULL,
                BURL VARCHAR(1024) NOT NULL,
                BTITLE VARCHAR(255) NOT NULL DEFAULT '',
                BACTION VARCHAR(16) NOT NULL,
                BGROUPKEY VARCHAR(64) DEFAULT NULL,
                BTASKID BIGINT DEFAULT NULL,
                BENABLED TINYINT(1) NOT NULL DEFAULT 1,
                BVERSION VARCHAR(64) DEFAULT NULL,
                BFILEID BIGINT DEFAULT NULL,
                BCHECKEDAT BIGINT DEFAULT NULL,
                BCHANGEDAT BIGINT DEFAULT NULL,
                BNEXTCHECKAT BIGINT NOT NULL DEFAULT 0,
                BLASTERROR VARCHAR(512) DEFAULT NULL,
                BCREATED BIGINT NOT NULL,
                UNIQUE INDEX uq_notionwatch_user_page (BUSERID, BPAGEID),
                INDEX idx_notionwatch_due (BENABLED, BNEXTCHECKAT),
                PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BNOTIONWATCHES');
        $this->addSql('DROP TABLE IF EXISTS BNOTIONACTIVITY');
    }
}
