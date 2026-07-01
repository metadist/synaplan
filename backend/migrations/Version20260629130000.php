<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Complete the file-world data model on BFILES (Release 4.0 Feature 2,
 * 03_file-management.md §3.1). Additive, nullable/defaulted, reversible.
 *
 * Builds on the earlier provenance subset (Version20260627120000 added BSOURCE
 * + BORIGINALNAME). This adds the remaining columns + indexes so BFILES becomes
 * the single registry the file manager renders from with no per-row vector call:
 *
 *  - BORIGINKIND  generated artefact kind (image/video/audio/calendar/document)
 *  - BINCOMING    1 while an external push awaits triage in the Incoming inbox
 *  - BSTAGEPATH   path in the staging area before promotion (null once promoted)
 *  - BMESSAGEID   link to the originating BMESSAGES.BID (generated/attachments)
 *  - BVECTORSTATE authoritative vector state (none/pending/vectorized/failed/
 *                 not_applicable), decoupled from BSTATUS
 *  - BCHUNKCOUNT  cached chunk count so the list needs no per-row Qdrant call
 *  - BPROVIDER    generating provider/model for generated media
 *  - BTHUMBPATH   optional generated thumbnail/poster for fast grids
 *
 * Plus the filter/sort indexes from §3.1. BVECTORSTATE/BCHUNKCOUNT are
 * backfilled fix-on-read by FileController::listFiles.
 */
final class Version20260629130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add file-world columns (origin kind, incoming, staging, message link, vector state, chunk count, provider, thumb) + indexes to BFILES';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BFILES ADD COLUMN IF NOT EXISTS BORIGINKIND VARCHAR(24) DEFAULT NULL AFTER BORIGINALNAME');
        $this->addSql('ALTER TABLE BFILES ADD COLUMN IF NOT EXISTS BINCOMING TINYINT(1) NOT NULL DEFAULT 0 AFTER BORIGINKIND');
        $this->addSql('ALTER TABLE BFILES ADD COLUMN IF NOT EXISTS BSTAGEPATH VARCHAR(255) DEFAULT NULL AFTER BINCOMING');
        $this->addSql('ALTER TABLE BFILES ADD COLUMN IF NOT EXISTS BMESSAGEID BIGINT DEFAULT NULL AFTER BSTAGEPATH');
        $this->addSql("ALTER TABLE BFILES ADD COLUMN IF NOT EXISTS BVECTORSTATE VARCHAR(16) NOT NULL DEFAULT 'none' AFTER BMESSAGEID");
        $this->addSql('ALTER TABLE BFILES ADD COLUMN IF NOT EXISTS BCHUNKCOUNT INT NOT NULL DEFAULT 0 AFTER BVECTORSTATE');
        $this->addSql('ALTER TABLE BFILES ADD COLUMN IF NOT EXISTS BPROVIDER VARCHAR(48) DEFAULT NULL AFTER BCHUNKCOUNT');
        $this->addSql('ALTER TABLE BFILES ADD COLUMN IF NOT EXISTS BTHUMBPATH VARCHAR(255) DEFAULT NULL AFTER BPROVIDER');

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_file_user_group ON BFILES (BUSERID, BGROUPKEY)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_file_user_vstate ON BFILES (BUSERID, BVECTORSTATE)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_file_user_incoming ON BFILES (BUSERID, BINCOMING)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_file_user_created ON BFILES (BUSERID, BCREATEDAT)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_file_user_created ON BFILES');
        $this->addSql('DROP INDEX IF EXISTS idx_file_user_incoming ON BFILES');
        $this->addSql('DROP INDEX IF EXISTS idx_file_user_vstate ON BFILES');
        $this->addSql('DROP INDEX IF EXISTS idx_file_user_group ON BFILES');

        $this->addSql('ALTER TABLE BFILES DROP COLUMN IF EXISTS BTHUMBPATH');
        $this->addSql('ALTER TABLE BFILES DROP COLUMN IF EXISTS BPROVIDER');
        $this->addSql('ALTER TABLE BFILES DROP COLUMN IF EXISTS BCHUNKCOUNT');
        $this->addSql('ALTER TABLE BFILES DROP COLUMN IF EXISTS BVECTORSTATE');
        $this->addSql('ALTER TABLE BFILES DROP COLUMN IF EXISTS BMESSAGEID');
        $this->addSql('ALTER TABLE BFILES DROP COLUMN IF EXISTS BSTAGEPATH');
        $this->addSql('ALTER TABLE BFILES DROP COLUMN IF EXISTS BINCOMING');
        $this->addSql('ALTER TABLE BFILES DROP COLUMN IF EXISTS BORIGINKIND');
    }
}
