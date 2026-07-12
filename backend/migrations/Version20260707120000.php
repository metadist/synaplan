<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Incognito chat: add BFILES.BEPHEMERAL.
 *
 * Files created during an incognito chat session (uploads, generated media,
 * TTS replies) are marked ephemeral so they are excluded from all file
 * listings and cleaned up automatically (frontend session-end cleanup +
 * `app:files:reap-ephemeral` safety net).
 *
 * Comparator-free + idempotent (raw `IF NOT EXISTS` DDL, no Schema reads) per
 * the Galera production rules in AGENTS.md — safe to run incrementally on the
 * shared prod cluster.
 */
final class Version20260707120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BFILES.BEPHEMERAL flag + index for incognito-chat ephemeral files';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE BFILES
                ADD COLUMN IF NOT EXISTS BEPHEMERAL TINYINT(1) NOT NULL DEFAULT 0
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE BFILES
                ADD INDEX IF NOT EXISTS idx_file_ephemeral_created (BEPHEMERAL, BCREATEDAT)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE BFILES
                DROP INDEX IF EXISTS idx_file_ephemeral_created
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE BFILES
                DROP COLUMN IF EXISTS BEPHEMERAL
        SQL);
    }
}
