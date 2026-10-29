<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Model\ModelCatalog;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Insert Gemini 4 Argon (chat + vision) on installs that skip the model seeder.
 *
 * ModelSeeder inserts the same BIDs on deploy when the row is absent. This
 * migration covers the skip-seed path. Operator flags are set only on insert
 * (the existence check skips a row that is already there). Raw SQL via
 * ModelCatalog::upsert, no Schema API (Galera-safe).
 *
 * Version is 20261029150000 because 20261029140000 already records when a
 * cloud copy was downloaded.
 */
final class Version20261029150000 extends AbstractMigration
{
    /** @var list<int> */
    private const NEW_BIDS = [400, 401];

    public function getDescription(): string
    {
        return 'Insert Gemini 4 Argon chat and vision catalog rows';
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
