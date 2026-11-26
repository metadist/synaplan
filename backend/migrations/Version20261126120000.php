<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Record until when old-style public media names are honoured.
 *
 * Media that third parties fetch without a credential used to get names built
 * from ids and the clock. New files get random names. Links to the old names
 * must keep working, so the file route still serves an old-style name, but
 * only when the time in that name lies before the moment stored here: the
 * time of this upgrade plus one day, which covers nodes that still run the
 * previous release during a rolling deploy.
 *
 * The row is written once and never moved: `INSERT IGNORE` on the unique key
 * (BOWNERID, BGROUP, BSETTING). Galera-safe: raw idempotent SQL, no Schema API.
 */
final class Version20261126120000 extends AbstractMigration
{
    private const GRACE_SECONDS = 86400;

    public function getDescription(): string
    {
        return 'Record until when old-style public media file names are served anonymously';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) VALUES (0, 'MEDIA', 'LEGACY_PUBLIC_NAMES_UNTIL', CAST(UNIX_TIMESTAMP() + ? AS CHAR))",
            [self::GRACE_SECONDS],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = 'MEDIA' AND BSETTING = 'LEGACY_PUBLIC_NAMES_UNTIL'");
    }
}
