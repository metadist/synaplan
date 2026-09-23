<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Model\ModelCatalog;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Insert Grok 4.7 (BIDs 367–368) and Muse Spark 1.3 (BIDs 369–370).
 *
 * ModelSeeder inserts new BIDs on deploy, but only when the row is absent.
 * This migration inserts the same catalog snapshot so existing installs get
 * the rows even if seed is skipped. Operator flags are never updated
 * (INSERT ... WHERE NOT EXISTS). Raw SQL / parameterized writes only
 * (Galera-safe; no Schema API).
 *
 * Grok 4.7: https://docs.x.ai/developers/grok-4-7 (2026-09-21).
 * Muse Spark 1.3: https://developer.meta.com/ai/models/muse-spark/ (2026-09-23).
 */
final class Version20260923180000 extends AbstractMigration
{
    /** @var list<int> */
    private const NEW_BIDS = [367, 368, 369, 370];

    public function getDescription(): string
    {
        return 'Insert Grok 4.7 and Meta Muse Spark 1.3 catalog rows';
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

            $exists = $this->connection->fetchOne('SELECT BID FROM BMODELS WHERE BID = ?', [$bid]);
            if (false !== $exists) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $row = $byId[$bid];
            ModelCatalog::upsert($this->connection, $row);
        }
    }

    public function down(Schema $schema): void
    {
        // Rows stay: BMESSAGES / BUSELOG reference BIDs. Disable via the admin UI
        // or ModelCatalog::RETIREMENTS if a later release withdraws them.
    }
}
