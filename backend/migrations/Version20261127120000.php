<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Project items (PJ30): the board's store. One table for tasks, dates,
 * decisions, costs and notes (`BKIND`), one log row per change so a change
 * can be undone and the undo and accept rates can be counted without
 * content (`BSTAGE`, `BACTION`). Two project columns: the time zone for a
 * date without a zone and the currency of the costs.
 *
 * Galera-safe: raw addSql only, IF NOT EXISTS everywhere, no Schema API, no
 * foreign keys. Items go with their project (ProjectService::delete) and
 * with the account (ProjectEraser).
 *
 * The flag `PROJECTS.BOARD_ENABLED` is seeded off: nothing changes for an
 * install until an admin turns it on.
 */
final class Version20261127120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BPROJECTITEMS, BPROJECTITEMLOG, BPROJECTS.BTIMEZONE / BCURRENCY, seed PROJECTS.BOARD_ENABLED off';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BPROJECTS ADD COLUMN IF NOT EXISTS BTIMEZONE VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE BPROJECTS ADD COLUMN IF NOT EXISTS BCURRENCY CHAR(3) DEFAULT NULL');

        $this->addSql('CREATE TABLE IF NOT EXISTS BPROJECTITEMS (
            BID BIGINT AUTO_INCREMENT NOT NULL,
            BUUID CHAR(36) NOT NULL,
            BPROJECTID BIGINT NOT NULL,
            BKIND VARCHAR(16) NOT NULL,
            BSTATE VARCHAR(16) NOT NULL,
            BPARENTID BIGINT DEFAULT NULL,
            BSORT INT DEFAULT NULL,
            BVISIBILITY VARCHAR(8) NOT NULL DEFAULT \'project\',
            BWAITINGSINCE DATETIME DEFAULT NULL,
            BTITLE VARCHAR(200) NOT NULL,
            BDETAIL VARCHAR(1000) DEFAULT NULL,
            BSTARTAT DATETIME DEFAULT NULL,
            BENDAT DATETIME DEFAULT NULL,
            BALLDAY TINYINT(1) NOT NULL DEFAULT 0,
            BRRULE VARCHAR(255) DEFAULT NULL,
            BASSIGNEEID BIGINT DEFAULT NULL,
            BCONTACT VARCHAR(200) DEFAULT NULL,
            BAMOUNT DECIMAL(12,2) DEFAULT NULL,
            BESTIMATED TINYINT(1) NOT NULL DEFAULT 0,
            BSOURCEKIND VARCHAR(16) NOT NULL,
            BSOURCEID BIGINT DEFAULT NULL,
            BCREATEDBY BIGINT NOT NULL,
            BCREATED DATETIME NOT NULL,
            BUPDATED DATETIME NOT NULL,
            UNIQUE INDEX uniq_project_item_uuid (BUUID),
            INDEX idx_project_item_kind_state (BPROJECTID, BKIND, BSTATE),
            INDEX idx_project_item_start (BPROJECTID, BSTARTAT),
            INDEX idx_project_item_parent (BPARENTID),
            PRIMARY KEY (BID)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE IF NOT EXISTS BPROJECTITEMLOG (
            BID BIGINT AUTO_INCREMENT NOT NULL,
            BPROJECTID BIGINT NOT NULL,
            BITEMID BIGINT NOT NULL,
            BACTION VARCHAR(16) NOT NULL,
            BSTAGE SMALLINT DEFAULT NULL,
            BBEFORE JSON DEFAULT NULL,
            BBYUSERID BIGINT DEFAULT NULL,
            BMESSAGEID BIGINT DEFAULT NULL,
            BRUNID BIGINT DEFAULT NULL,
            BCREATED DATETIME NOT NULL,
            INDEX idx_project_item_log_item (BITEMID),
            INDEX idx_project_item_log_project (BPROJECTID, BCREATED),
            INDEX idx_project_item_log_message (BMESSAGEID),
            PRIMARY KEY (BID)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql("INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
            SELECT 0, 'PROJECTS', 'BOARD_ENABLED', '0' FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = 'PROJECTS' AND BSETTING = 'BOARD_ENABLED')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = 'PROJECTS' AND BSETTING = 'BOARD_ENABLED'");
        $this->addSql('DROP TABLE IF EXISTS BPROJECTITEMLOG');
        $this->addSql('DROP TABLE IF EXISTS BPROJECTITEMS');
        $this->addSql('ALTER TABLE BPROJECTS DROP COLUMN IF EXISTS BCURRENCY');
        $this->addSql('ALTER TABLE BPROJECTS DROP COLUMN IF EXISTS BTIMEZONE');
    }
}
