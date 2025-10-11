<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251011081227 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Chat tables and link Messages to Chats';
    }

    public function up(Schema $schema): void
    {
        // Create BCHATS table
        $this->addSql('CREATE TABLE BCHATS (
            BID INT AUTO_INCREMENT NOT NULL PRIMARY KEY,
            BUSERID INT NOT NULL,
            BTITLE VARCHAR(255) DEFAULT NULL,
            BCREATEDAT DATETIME NOT NULL,
            BUPDATEDAT DATETIME NOT NULL,
            BSHARETOKEN VARCHAR(64) DEFAULT NULL UNIQUE,
            BISPUBLIC TINYINT(1) DEFAULT 0 NOT NULL,
            INDEX idx_chat_user (BUSERID),
            INDEX idx_chat_share (BSHARETOKEN)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add BCHATID column to BMESSAGES
        $this->addSql('ALTER TABLE BMESSAGES ADD BCHATID INT DEFAULT NULL AFTER BUSERID');
        $this->addSql('CREATE INDEX idx_message_chat ON BMESSAGES (BCHATID)');
        
        // Add FK constraint
        $this->addSql('ALTER TABLE BMESSAGES ADD CONSTRAINT FK_BMESSAGES_CHAT 
            FOREIGN KEY (BCHATID) REFERENCES BCHATS (BID) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // Remove FK and index
        $this->addSql('ALTER TABLE BMESSAGES DROP FOREIGN KEY FK_BMESSAGES_CHAT');
        $this->addSql('DROP INDEX idx_message_chat ON BMESSAGES');
        $this->addSql('ALTER TABLE BMESSAGES DROP COLUMN BCHATID');
        
        // Drop BCHATS table
        $this->addSql('DROP TABLE BCHATS');
    }
}
