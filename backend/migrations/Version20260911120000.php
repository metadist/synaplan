<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Model\ModelCatalog;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add the 2026-09-11 Google Gemini lineup that was missing from the catalog:
 * 3.6 / 3.7 / 3.8 Flash, 3.5 Flash-Lite, Nano Banana 2 Lite, Omni Flash video,
 * and Gemini 3.5 Transcribe.
 *
 * ModelSeeder inserts new BIDs on deploy, but only when the row is absent.
 * This migration inserts the same catalog snapshot so existing installs get
 * the rows even if seed is skipped. Operator flags are never updated
 * (INSERT ... WHERE NOT EXISTS). Raw SQL / parameterized writes only
 * (Galera-safe; no Schema API).
 *
 * IDs were live-probed against the Gemini Developer API on 2026-09-11.
 */
final class Version20260911120000 extends AbstractMigration
{
    /** @var list<int> */
    private const NEW_BIDS = [350, 351, 352, 353, 354, 355, 356, 357, 358, 359, 360];

    public function getDescription(): string
    {
        return 'Insert Gemini 3.6/3.7/3.8 Flash, 3.5 Flash-Lite, Nano Banana 2 Lite, Omni Flash, and Gemini 3.5 Transcribe catalog rows';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $byId = [];
        foreach (ModelCatalog::all() as $row) {
            $byId[(int) $row['id']] = $row;
        }

        foreach (self::NEW_BIDS as $bid) {
            $this->abortIf(
                !isset($byId[$bid]),
                sprintf('ModelCatalog is missing BID %d — the migration cannot insert it.', $bid),
            );

            /** @var array<string, mixed> $row */
            $row = $byId[$bid];

            $exists = $this->connection->fetchOne('SELECT BID FROM BMODELS WHERE BID = ?', [$bid]);
            if (false !== $exists) {
                continue;
            }

            ModelCatalog::upsert($this->connection, $row);
        }
    }

    public function down(Schema $schema): void
    {
        // Rows stay: BMESSAGES / BUSELOG reference BIDs. Disable via the admin UI
        // or ModelCatalog::RETIREMENTS if a later release withdraws them.
    }
}
