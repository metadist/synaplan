<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One row per live-voice conversation. Cost stays on BUSELOG; this table
 * holds the session the gateway and the reaper need.
 *
 * Galera-safe: raw addSql only, CREATE TABLE IF NOT EXISTS, no Schema API
 * and no foreign keys.
 */
final class Version20261012120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create BVOICESESSIONS for live voice conversations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BVOICESESSIONS (
                BID INT AUTO_INCREMENT NOT NULL,
                BUSERID INT NOT NULL,
                BCHATID INT DEFAULT NULL,
                BMODELID INT NOT NULL,
                BPROVIDER VARCHAR(32) NOT NULL,
                BPROVIDERSESSIONID VARCHAR(128) DEFAULT NULL,
                BSTATUS VARCHAR(16) NOT NULL,
                BENDREASON VARCHAR(32) DEFAULT NULL,
                BSTARTED INT NOT NULL,
                BENDED INT DEFAULT NULL,
                BSECONDS INT NOT NULL DEFAULT 0,
                BUSELOGID INT DEFAULT NULL,
                BCLIENT VARCHAR(16) NOT NULL,
                BTRANSPORT VARCHAR(16) NOT NULL,
                INDEX idx_voice_session_user_status (BUSERID, BSTATUS),
                PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BVOICESESSIONS');
    }
}
