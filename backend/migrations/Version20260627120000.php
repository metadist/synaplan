<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add file provenance columns to BFILES (Release 4.0 Feature 2 / Feature 7
 * joint "provenance" sprint). Additive, nullable/defaulted, reversible.
 *
 *  - BSOURCE       where the file came from (web_upload, chat_attachment,
 *                  outlook, nextcloud, opencloud, whatsapp, widget, api,
 *                  generated). Defaults to web_upload so legacy rows read sanely.
 *  - BORIGINALNAME the file's original name at the source (e.g. the Nextcloud
 *                  basename or Outlook attachment name) — preserved even when
 *                  the stored name is normalised. Null falls back to BFILENAME.
 *
 * This is the minimal subset of 03_file-management.md §3.1 needed to thread
 * provenance through the upload contract; the remaining columns
 * (BINCOMING, BSTAGEPATH, BVECTORSTATE, …) land with the full file-world feature.
 */
final class Version20260627120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BSOURCE + BORIGINALNAME provenance columns to BFILES';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE BFILES ADD COLUMN IF NOT EXISTS BSOURCE VARCHAR(32) NOT NULL DEFAULT 'web_upload' AFTER BSTATUS");
        $this->addSql('ALTER TABLE BFILES ADD COLUMN IF NOT EXISTS BORIGINALNAME VARCHAR(255) DEFAULT NULL AFTER BSOURCE');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_file_user_source ON BFILES (BUSERID, BSOURCE)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_file_user_source ON BFILES');
        $this->addSql('ALTER TABLE BFILES DROP COLUMN IF EXISTS BORIGINALNAME');
        $this->addSql('ALTER TABLE BFILES DROP COLUMN IF EXISTS BSOURCE');
    }
}
