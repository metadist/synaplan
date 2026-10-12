<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Direct browser WebRTC for OpenAI starts off. An admin turns it on.
 * Existing installs do not pick up a seeder default, so the row is inserted here.
 *
 * Galera-safe: raw idempotent DML, no Schema API.
 */
final class Version20261012150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Default the OpenAI live-voice direct connection to off';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
            SELECT 0, 'LIVE_VOICE', 'WEBRTC_OPENAI', '0'
              FROM DUAL
             WHERE NOT EXISTS (
                 SELECT 1 FROM BCONFIG
                  WHERE BOWNERID = 0 AND BGROUP = 'LIVE_VOICE' AND BSETTING = 'WEBRTC_OPENAI'
             )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = 'LIVE_VOICE' AND BSETTING = 'WEBRTC_OPENAI'");
    }
}
