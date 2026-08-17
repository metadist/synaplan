<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Data heal for generated-file provenance (no schema change).
 *
 * ChatHandler::storeGeneratedFile (the non-streaming document generator used
 * by Saved Task, WhatsApp and email runs) wrote BSTATUS='generated' rows but
 * never set BSOURCE='generated' — the issue #1190 provenance fix only reached
 * the StreamController twin. Those documents therefore never appeared in the
 * file manager's Generated gallery, which filters on BSOURCE.
 *
 * Both UPDATEs are idempotent and Galera-safe (raw DML, no Schema API):
 *   1. Relabel mislabeled generated rows (BSTATUS says generated, BSOURCE
 *      still carries the 'web_upload' column default).
 *   2. Backfill BORIGINKIND for generated rows that predate the kind column
 *      being set, so the gallery's type filter matches them. Kind is derived
 *      from BFILETYPE (handler media type or file extension).
 */
final class Version20260817160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Relabel generated files stuck as web_upload; backfill BORIGINKIND for generated rows';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE BFILES
            SET BSOURCE = 'generated'
            WHERE BSTATUS = 'generated' AND BSOURCE = 'web_upload'
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE BFILES
            SET BORIGINKIND = CASE
                WHEN LOWER(BFILETYPE) IN ('image', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg') THEN 'image'
                WHEN LOWER(BFILETYPE) IN ('video', 'mp4', 'webm', 'mov', 'avi', 'mkv') THEN 'video'
                WHEN LOWER(BFILETYPE) IN ('audio', 'mp3', 'wav', 'ogg', 'm4a') THEN 'audio'
                WHEN LOWER(BFILETYPE) IN ('calendar', 'ics') THEN 'calendar'
                ELSE 'document'
            END
            WHERE BSOURCE = 'generated' AND (BORIGINKIND IS NULL OR BORIGINKIND = '')
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Data heal — the pre-heal state (missing provenance) is a bug, not a
        // schema version worth restoring. No-op.
    }
}
