<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Model\ModelCatalog;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Put Google's current image models on existing installs and turn the dead
 * preview ids off.
 *
 *   - BID 371 gemini-3.1-flash-image  (Nano Banana 2, stable) — active
 *   - BID 372 gemini-3-pro-image      (Nano Banana Pro, stable) — active
 *   - BID 190 gemini-3.1-flash-image-preview — shut down 2026-06-25
 *   - BID 228 nano-banana-pro-preview        — replaced by the stable Pro id
 *
 * Inserts run before the repoint: DEFAULTMODEL, per-prompt and per-widget
 * bindings need the successor BID to exist. Operator flags on the new rows
 * are set only on insert. The preview rows are deactivated here, not left to
 * the seeder, so an install that skips seed still stops calling the dead ids.
 * Raw SQL only (Galera-safe; no Schema API).
 */
final class Version20260923190000 extends AbstractMigration
{
    private const NANO_BANANA_2_BID = 371;
    private const NANO_BANANA_PRO_BID = 372;

    /** @var list<int> */
    private const NEW_BIDS = [self::NANO_BANANA_2_BID, self::NANO_BANANA_PRO_BID];

    /**
     * Retired BID => [upstream id guard, successor BID, shutdown date].
     *
     * @var array<int, array{0: string, 1: int, 2: string}>
     */
    private const RETIRED_MODELS = [
        190 => ['gemini-3.1-flash-image-preview', self::NANO_BANANA_2_BID, '2026-06-25'],
        228 => ['nano-banana-pro-preview', self::NANO_BANANA_PRO_BID, '2026-06-25'],
    ];

    /**
     * Imagen 4 rows already retired; their successor was the preview id.
     *
     * @var array<int, string>
     */
    private const IMAGEN_ROWS = [
        115 => 'imagen-4.0-generate-001',
        230 => 'imagen-4.0-fast-generate-001',
        231 => 'imagen-4.0-ultra-generate-001',
    ];

    public function getDescription(): string
    {
        return 'Insert stable Nano Banana 2 and Nano Banana Pro, repoint image bindings, and deactivate the shut-down Google preview image ids';
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
            if (false === $exists) {
                /** @var array<string, mixed> $row */
                $row = $byId[$bid];
                ModelCatalog::upsert($this->connection, $row);
            }
        }

        foreach (self::RETIRED_MODELS as $retiredBid => [$providerId, $successorBid, $retiredOn]) {
            $this->connection->executeStatement(
                'UPDATE BCONFIG SET BVALUE = ? WHERE BGROUP = \'DEFAULTMODEL\' AND BVALUE = ? AND EXISTS (SELECT 1 FROM BMODELS WHERE BID = ?)',
                [(string) $successorBid, (string) $retiredBid, $successorBid],
            );

            $this->connection->executeStatement(
                'UPDATE BPROMPTMETA SET BMETAVALUE = ? WHERE BMETAKEY = \'aiModel\' AND BMETAVALUE = ? AND EXISTS (SELECT 1 FROM BMODELS WHERE BID = ?)',
                [(string) $successorBid, (string) $retiredBid, $successorBid],
            );

            $this->connection->executeStatement(
                'UPDATE BWIDGETS SET BCONFIG = JSON_SET(BCONFIG, \'$.aiModelId\', :successorId) WHERE JSON_VALUE(BCONFIG, \'$.aiModelId\') = :retired AND EXISTS (SELECT 1 FROM BMODELS WHERE BID = :successorId)',
                [
                    'successorId' => $successorBid,
                    'retired' => (string) $retiredBid,
                ],
                [
                    'successorId' => ParameterType::INTEGER,
                ],
            );

            $this->connection->executeStatement(
                'UPDATE BMODELS SET BACTIVE = 0, BSELECTABLE = 0, BISDEFAULT = 0, BRETIREDON = ?, BSUCCESSORID = ? WHERE BID = ? AND BPROVID = ?',
                [$retiredOn, $successorBid, $retiredBid, $providerId],
            );
        }

        foreach (self::IMAGEN_ROWS as $bid => $providerId) {
            $this->connection->executeStatement(
                'UPDATE BMODELS SET BSUCCESSORID = ? WHERE BID = ? AND BPROVID = ?',
                [self::NANO_BANANA_2_BID, $bid, $providerId],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Rows stay. Bindings are not moved back: a later deliberate choice of
        // the stable id cannot be told apart from this migration.
    }
}
