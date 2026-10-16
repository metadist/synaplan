<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Archive flag and tags on a chat, plus saved slash prompts.
 *
 * Galera-safe: raw addSql only, ADD COLUMN IF NOT EXISTS / CREATE TABLE IF NOT EXISTS,
 * no Schema API and no foreign keys.
 */
final class Version20261007120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add chat archive, chat tags, and the saved prompt library';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BCHATS ADD COLUMN IF NOT EXISTS BARCHIVED TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql("ALTER TABLE BCHATS ADD COLUMN IF NOT EXISTS BTAGS VARCHAR(500) NOT NULL DEFAULT '[]'");
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BSAVEDPROMPTS (
                BID INT AUTO_INCREMENT NOT NULL,
                BUSERID INT NOT NULL,
                BNAME VARCHAR(120) NOT NULL,
                BCOMMAND VARCHAR(64) NOT NULL,
                BBODY LONGTEXT NOT NULL,
                BTAGS VARCHAR(255) NOT NULL DEFAULT '',
                BCREATEDAT DATETIME NOT NULL,
                BUPDATEDAT DATETIME NOT NULL,
                UNIQUE INDEX uniq_saved_prompt_user_command (BUSERID, BCOMMAND),
                INDEX idx_saved_prompt_user (BUSERID),
                PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BSAVEDPROMPTS');
        $this->addSql('ALTER TABLE BCHATS DROP COLUMN IF EXISTS BTAGS');
        $this->addSql('ALTER TABLE BCHATS DROP COLUMN IF EXISTS BARCHIVED');
    }
}
