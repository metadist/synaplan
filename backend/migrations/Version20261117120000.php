<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Projects (PJ3): the project table and the three chat columns that put a
 * chat into a project. The sources of a project are ordinary files under the
 * project's knowledge folder (`BFILES.BGROUPKEY`), so no source table exists.
 *
 * Galera-safe: raw addSql only, IF NOT EXISTS everywhere, no Schema API, no
 * foreign keys. A project holds the owner's id; deleting the account removes
 * its projects in the same place it removes its chats.
 *
 * The flag `PROJECTS.ENABLED` is seeded off: nothing changes for an install
 * until an admin turns it on.
 */
final class Version20261117120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BPROJECTS and BCHATS.BPROJECTID / BPROJECTSHARED / BMEMORYMODE, seed PROJECTS.ENABLED off';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS BPROJECTS (
            BID BIGINT AUTO_INCREMENT NOT NULL,
            BUUID CHAR(36) NOT NULL,
            BUSERID BIGINT NOT NULL,
            BNAME VARCHAR(120) NOT NULL,
            BDESCRIPTION VARCHAR(500) DEFAULT NULL,
            BINSTRUCTIONS TEXT DEFAULT NULL,
            BGROUPKEY VARCHAR(64) NOT NULL,
            BCREATED DATETIME NOT NULL,
            BUPDATED DATETIME NOT NULL,
            UNIQUE INDEX uniq_project_uuid (BUUID),
            INDEX idx_project_user (BUSERID),
            PRIMARY KEY (BID)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE BCHATS ADD COLUMN IF NOT EXISTS BPROJECTID BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE BCHATS ADD COLUMN IF NOT EXISTS BPROJECTSHARED TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE BCHATS ADD COLUMN IF NOT EXISTS BMEMORYMODE VARCHAR(20) DEFAULT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_chat_project ON BCHATS (BPROJECTID)');

        $this->addSql("INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
            SELECT 0, 'PROJECTS', 'ENABLED', '0' FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = 'PROJECTS' AND BSETTING = 'ENABLED')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = 'PROJECTS' AND BSETTING = 'ENABLED'");
        $this->addSql('DROP INDEX IF EXISTS idx_chat_project ON BCHATS');
        $this->addSql('ALTER TABLE BCHATS DROP COLUMN IF EXISTS BMEMORYMODE');
        $this->addSql('ALTER TABLE BCHATS DROP COLUMN IF EXISTS BPROJECTSHARED');
        $this->addSql('ALTER TABLE BCHATS DROP COLUMN IF EXISTS BPROJECTID');
        $this->addSql('DROP TABLE IF EXISTS BPROJECTS');
    }
}
