<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\ModelCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the retirement registry that {@see ModelCatalog}::RETIREMENTS holds.
 *
 * The failure this exists to prevent is a quiet one (#1515): a release drops a
 * model from the catalog because the provider switched it off, and every
 * existing install keeps the row active, selectable and billable — because
 * ModelSeeder only ever writes rows the catalog still contains. Three of the
 * five hand-written retirement migrations were cleanups for exactly that.
 *
 * So the BID list is snapshotted. Removing a model from the catalog without
 * recording its retirement now fails here, at the moment the removal is
 * written, instead of on someone's install weeks later.
 *
 * Adding models is expected and cheap: re-record with
 *   UPDATE_MODEL_BID_SNAPSHOT=1 ./vendor/bin/phpunit tests/Unit/Model/ModelCatalogRetirementTest.php
 * and review the diff — it should contain only additions.
 */
final class ModelCatalogRetirementTest extends TestCase
{
    private const SNAPSHOT_FILE = __DIR__.'/__snapshots__/model_bids.json';

    /**
     * The point of the whole registry: a BID that leaves the catalog must leave
     * behind a record of why, or installs keep serving it.
     */
    public function testEveryBidThatLeftTheCatalogHasARetirementRecord(): void
    {
        $current = self::catalogBids();
        $orphans = [];

        foreach (self::snapshotBids() as $bid) {
            if (in_array($bid, $current, true)) {
                continue;
            }
            if (ModelCatalog::isRetired($bid)) {
                continue;
            }
            $orphans[] = $bid;
        }

        self::assertSame([], $orphans, sprintf(
            "Model BID(s) %s were removed from ModelCatalog without a RETIREMENTS entry.\n"
            ."Existing installs still have those rows ACTIVE and SELECTABLE — dropping a model from the\n"
            ."catalog does not switch it off anywhere. Add an entry to ModelCatalog::RETIREMENTS (date,\n"
            .'successor key or an explicit null, reason); ModelRetirementSeeder applies it on deploy.',
            implode(', ', $orphans),
        ));
    }

    /**
     * Keeps the snapshot honest. Without this the guard above would rot: a BID
     * missing from the snapshot can never be detected as gone.
     */
    public function testTheBidSnapshotCoversEveryKnownModel(): void
    {
        $known = self::knownBids();

        if ('1' === getenv('UPDATE_MODEL_BID_SNAPSHOT')) {
            $this->record($known);
        }

        $missing = array_values(array_diff($known, self::snapshotBids()));

        self::assertSame([], $missing, sprintf(
            'New model BID(s) %s are not in the snapshot. Re-record with '
            .'UPDATE_MODEL_BID_SNAPSHOT=1 and commit %s.',
            implode(', ', $missing),
            self::SNAPSHOT_FILE,
        ));
    }

    /**
     * A retirement whose row is still active in the catalog is worse than no
     * retirement: the registry claims the model is dead while the seeder keeps
     * shipping it as usable.
     */
    public function testARetiredModelStillInTheCatalogIsSwitchedOff(): void
    {
        $live = [];

        foreach (ModelCatalog::all() as $model) {
            $bid = (int) $model['id'];
            if (!ModelCatalog::isRetired($bid)) {
                continue;
            }
            if (0 !== (int) ($model['active'] ?? 0) || 0 !== (int) ($model['selectable'] ?? 0)) {
                $live[] = $bid;
            }
        }

        self::assertSame([], $live, sprintf(
            'BID(s) %s are recorded as retired but still carry active/selectable = 1 in ModelCatalog. '
            .'Set both to 0 so a fresh install never offers them.',
            implode(', ', $live),
        ));
    }

    #[DataProvider('retirementProvider')]
    public function testRetirementDateIsAnIsoDate(int $bid, array $record): void
    {
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}$/',
            $record['retiredOn'],
            "BID {$bid}: retiredOn must be YYYY-MM-DD.",
        );
        self::assertSame(
            $record['retiredOn'],
            (new \DateTimeImmutable($record['retiredOn']))->format('Y-m-d'),
            "BID {$bid}: retiredOn is not a real calendar date.",
        );
    }

    #[DataProvider('retirementProvider')]
    public function testRetirementCarriesAReason(int $bid, array $record): void
    {
        self::assertNotSame('', trim($record['reason']), "BID {$bid}: a retirement needs a reason an operator can read.");
    }

    /**
     * Without the provider id there is no guard, and the seeder would happily
     * mark a BID dead that an operator has since pointed at a live model.
     */
    #[DataProvider('retirementProvider')]
    public function testRetirementCarriesTheProviderIdItGuardsOn(int $bid, array $record): void
    {
        self::assertNotSame('', trim($record['providerId']), "BID {$bid}: providerId is the seeder's guard and cannot be empty.");
    }

    /**
     * A successor key that no longer resolves would silently degrade to "no
     * successor", which is a different and weaker statement than the registry
     * intends.
     */
    #[DataProvider('retirementProvider')]
    public function testRecordedSuccessorResolvesToExactlyOneModel(int $bid, array $record): void
    {
        if (null === $record['successor']) {
            self::assertNull(ModelCatalog::successorBid($bid));

            return;
        }

        self::assertNotNull(
            ModelCatalog::successorBid($bid),
            "BID {$bid}: successor key '{$record['successor']}' does not resolve to exactly one catalog entry. "
            .'Either fix the key or record null, which means "no replacement" on purpose.',
        );
    }

    /**
     * Repointing to a model that is itself retired just moves the problem.
     */
    #[DataProvider('retirementProvider')]
    public function testSuccessorIsNotItselfRetired(int $bid, array $record): void
    {
        $successorBid = ModelCatalog::successorBid($bid);

        if (null === $successorBid) {
            self::assertNull($record['successor'], "BID {$bid}: unresolved successor, see the resolution test.");

            return;
        }

        self::assertFalse(
            ModelCatalog::isRetired($successorBid),
            "BID {$bid}: successor {$successorBid} is itself retired. Point at a live model instead.",
        );
    }

    public function testARetirementNeverPointsAtItself(): void
    {
        foreach (array_keys(ModelCatalog::retirements()) as $bid) {
            self::assertNotSame($bid, ModelCatalog::successorBid($bid), "BID {$bid} is its own successor.");
        }
    }

    /**
     * @return \Generator<string, array{int, array{providerId: string, retiredOn: string, successor: string|null, reason: string}}>
     */
    public static function retirementProvider(): \Generator
    {
        foreach (ModelCatalog::retirements() as $bid => $record) {
            yield "BID {$bid}" => [$bid, $record];
        }
    }

    /**
     * @return int[]
     */
    private static function catalogBids(): array
    {
        return array_map(static fn (array $model): int => (int) $model['id'], ModelCatalog::all());
    }

    /**
     * Everything the codebase knows about: shipped today, plus everything we
     * ever retired.
     *
     * @return int[]
     */
    private static function knownBids(): array
    {
        $bids = array_values(array_unique(array_merge(
            self::catalogBids(),
            array_keys(ModelCatalog::retirements()),
        )));
        sort($bids);

        return $bids;
    }

    /**
     * @return int[]
     */
    private static function snapshotBids(): array
    {
        self::assertFileExists(
            self::SNAPSHOT_FILE,
            'Missing model BID snapshot. Generate it once with UPDATE_MODEL_BID_SNAPSHOT=1 and commit '.self::SNAPSHOT_FILE,
        );

        $decoded = json_decode((string) file_get_contents(self::SNAPSHOT_FILE), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('bids', $decoded);
        self::assertIsArray($decoded['bids']);

        return array_map('intval', $decoded['bids']);
    }

    /**
     * @param int[] $bids
     */
    private function record(array $bids): void
    {
        $directory = \dirname(self::SNAPSHOT_FILE);
        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        file_put_contents(self::SNAPSHOT_FILE, json_encode([
            '_comment' => 'Every BID ModelCatalog has ever shipped. Append-only: a BID that leaves the '
                .'catalog must gain a ModelCatalog::RETIREMENTS entry, never disappear from here. '
                .'Re-record with UPDATE_MODEL_BID_SNAPSHOT=1.',
            'bids' => $bids,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }
}
