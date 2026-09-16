<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BFILES.BUPDATEDAT — last status change, so the stuck-file reaper can age
 * from process start rather than original upload time (issue #1913).
 *
 * Galera-safe: raw ADD COLUMN IF NOT EXISTS, no Schema API.
 */
final class Version20260916180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BFILES.BUPDATEDAT for stuck extracting/vectorizing reaper';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BFILES ADD COLUMN IF NOT EXISTS BUPDATEDAT BIGINT NULL AFTER BCREATEDAT');
        $this->addSql('UPDATE BFILES SET BUPDATEDAT = BCREATEDAT WHERE BUPDATEDAT IS NULL');
        // Mapping is NOT NULL; ADD … NULL is only so existing rows can be
        // backfilled before the column is tightened (schema:validate).
        $this->addSql('ALTER TABLE BFILES MODIFY COLUMN BUPDATEDAT BIGINT NOT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_file_status_updated ON BFILES (BSTATUS, BUPDATEDAT)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_message_status_unix ON BMESSAGES (BSTATUS, BUNIXTIMES)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BFILES DROP INDEX IF EXISTS idx_file_status_updated');
        $this->addSql('ALTER TABLE BMESSAGES DROP INDEX IF EXISTS idx_message_status_unix');
        $this->addSql('ALTER TABLE BFILES DROP COLUMN IF EXISTS BUPDATEDAT');
    }
}
