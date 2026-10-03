<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seed;

use App\Model\CatalogPriceOwnership;
use App\Model\ModelCatalog;
use App\Seed\ModelSeeder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Drives ModelSeeder's per-row decision logic against a mocked Connection so we
 * can assert which BMODELS rows actually get touched in each scenario.
 *
 * The scenarios exercised here mirror ModelSeeder's full decision table,
 * including both fingerprinted rows and legacy rows with no stored fingerprint:
 *   - row missing                       → INSERT
 *   - fingerprint matches,
 *     catalog code changed              → UPDATE
 *   - row was UI-edited
 *     (fingerprint mismatch)            → PRESERVE (no write)
 *   - row matches catalog and
 *     fingerprint identical             → SKIP (no write)
 *   - legacy row matches catalog
 *     (no fingerprint)                  → adopt via UPDATE
 *   - legacy row diverges from catalog
 *     (no fingerprint)                  → PRESERVE (no write)
 *
 * The mock counts each executeStatement() invocation so we can verify both the
 * SeedResult counters AND that no surprise writes leak through.
 */
final class ModelSeederTest extends TestCase
{
    public function testInsertsCatalogRowsWhenDatabaseIsEmpty(): void
    {
        $connection = $this->buildConnection(existing: [], expectedWrites: count(ModelCatalog::all()));
        $seeder = new ModelSeeder($connection, 'prod');

        $result = $seeder->seed();

        $this->assertSame(count(ModelCatalog::all()), $result->inserted);
        $this->assertSame(0, $result->updated);
        $this->assertSame(0, $result->preserved);
        $this->assertSame(0, $result->skipped);
    }

    public function testSkipsRowsAlreadyInSyncWithStoredFingerprint(): void
    {
        $existing = [];
        foreach (ModelCatalog::all() as $row) {
            $existing[] = $this->stampFingerprint($row);
        }

        $connection = $this->buildConnection(existing: $existing, expectedWrites: 0);
        $seeder = new ModelSeeder($connection, 'prod');

        $result = $seeder->seed();

        $this->assertSame(0, $result->inserted);
        $this->assertSame(0, $result->updated);
        $this->assertSame(0, $result->preserved);
        $this->assertSame(count(ModelCatalog::all()), $result->skipped);
    }

    public function testUpdatesRowWhenCatalogValueChangesAndRowIsUntouched(): void
    {
        // Simulate the situation right after a catalog code change: every row
        // in the DB still carries the previously-seeded fingerprint, but the
        // first model now differs from what's in code (newer release shipped a
        // price update).
        $catalog = ModelCatalog::all();
        $existing = [];
        foreach ($catalog as $row) {
            $existing[] = $this->stampFingerprint($row);
        }

        $stale = array_merge($catalog[0], ['priceIn' => 999.99]);
        $existing[0] = $this->stampFingerprint($stale);

        $connection = $this->buildConnection(existing: $existing, expectedWrites: 1);
        $seeder = new ModelSeeder($connection, 'prod');

        $result = $seeder->seed();

        $this->assertSame(1, $result->updated);
        $this->assertSame(0, $result->inserted);
        $this->assertSame(0, $result->preserved);
        $this->assertSame(count($catalog) - 1, $result->skipped);
    }

    public function testPreservesRowEditedViaAdminUi(): void
    {
        // Same starting point as above, but this time the divergence comes from
        // an admin edit on /config/ai-models — the row's price changed AFTER
        // the fingerprint was recorded, so we must leave it alone.
        $catalog = ModelCatalog::all();
        $existing = [];
        foreach ($catalog as $row) {
            $existing[] = $this->stampFingerprint($row);
        }

        $existing[0]['priceIn'] = 7.77;

        $connection = $this->buildConnection(existing: $existing, expectedWrites: 0);
        $seeder = new ModelSeeder($connection, 'prod');

        $result = $seeder->seed();

        $this->assertSame(1, $result->preserved);
        $this->assertSame(0, $result->inserted);
        $this->assertSame(0, $result->updated);
        $this->assertSame(count($catalog) - 1, $result->skipped);
    }

    public function testKeepsLiteLlmPriceWhenCatalogPriceHasNotMoved(): void
    {
        $catalog = ModelCatalog::all();
        $target = $this->pricedCatalogRow($catalog);
        $synced = $target;
        $synced['priceIn'] = 123.456;
        $synced['priceOut'] = 654.321;
        $synced['json'][CatalogPriceOwnership::PRICE_OWNER_KEY] = CatalogPriceOwnership::PRICE_OWNER_LITELLM;
        $synced['json'][CatalogPriceOwnership::CATALOG_PRICE_AT_SYNC_KEY] = CatalogPriceOwnership::snapshot($target);
        $synced = $this->stampFingerprint($synced);

        $existing = [];
        foreach ($catalog as $row) {
            $existing[] = ((int) $row['id'] === (int) $target['id'])
                ? $synced
                : $this->stampFingerprint($row);
        }

        $connection = $this->buildConnection(existing: $existing, expectedWrites: 0);
        $result = (new ModelSeeder($connection, 'prod'))->seed();

        $this->assertSame(0, $result->updated);
        $this->assertSame(0, $result->preserved);
        $this->assertSame(count($catalog), $result->skipped);
    }

    public function testAppliesCatalogPriceWhenItMovedSinceTheSync(): void
    {
        $catalog = ModelCatalog::all();
        $target = $this->pricedCatalogRow($catalog);
        $synced = $target;
        $synced['priceIn'] = 123.456;
        $synced['priceOut'] = 654.321;
        $snapshot = CatalogPriceOwnership::snapshot($target);
        $snapshot['priceIn'] = 0.01;
        $synced['json'][CatalogPriceOwnership::PRICE_OWNER_KEY] = CatalogPriceOwnership::PRICE_OWNER_LITELLM;
        $synced['json'][CatalogPriceOwnership::CATALOG_PRICE_AT_SYNC_KEY] = $snapshot;
        $synced = $this->stampFingerprint($synced);

        $existing = [];
        foreach ($catalog as $row) {
            $existing[] = ((int) $row['id'] === (int) $target['id'])
                ? $synced
                : $this->stampFingerprint($row);
        }

        $writtenPrice = null;
        $connection = $this->buildConnection(
            existing: $existing,
            expectedWrites: 1,
            onWrite: function (array $params) use (&$writtenPrice): void {
                $writtenPrice = $params[7];
            },
        );
        $result = (new ModelSeeder($connection, 'prod'))->seed();

        $this->assertSame(1, $result->updated);
        $this->assertSame(0, $result->preserved);
        $this->assertEqualsWithDelta((float) $target['priceIn'], (float) $writtenPrice, 0.000001);
    }

    public function testAppliesNonPriceCatalogChangeWithoutClobberingLiteLlmPrice(): void
    {
        $catalog = ModelCatalog::all();
        $target = $this->pricedCatalogRow($catalog);
        $synced = $target;
        $synced['name'] = 'Stale display name';
        $synced['priceIn'] = 123.456;
        $synced['priceOut'] = 654.321;
        $synced['json'][CatalogPriceOwnership::PRICE_OWNER_KEY] = CatalogPriceOwnership::PRICE_OWNER_LITELLM;
        $synced['json'][CatalogPriceOwnership::CATALOG_PRICE_AT_SYNC_KEY] = CatalogPriceOwnership::snapshot($target);
        $synced = $this->stampFingerprint($synced);

        $existing = [];
        foreach ($catalog as $row) {
            $existing[] = ((int) $row['id'] === (int) $target['id'])
                ? $synced
                : $this->stampFingerprint($row);
        }

        $written = null;
        $connection = $this->buildConnection(
            existing: $existing,
            expectedWrites: 1,
            onWrite: function (array $params) use (&$written): void {
                $written = $params;
            },
        );
        $result = (new ModelSeeder($connection, 'prod'))->seed();

        $this->assertSame(1, $result->updated);
        $this->assertSame(0, $result->preserved);
        self::assertIsArray($written);
        $this->assertSame($target['name'], $written[2]);
        $this->assertEqualsWithDelta(123.456, (float) $written[7], 0.000001);
        $this->assertEqualsWithDelta(654.321, (float) $written[9], 0.000001);
    }

    public function testPinnedCatalogPriceWinsOverAPriceOnlyDrift(): void
    {
        $seeder = new ModelSeeder($this->createStub(Connection::class), 'prod');
        $decide = new \ReflectionMethod(ModelSeeder::class, 'decideAction');
        $catalog = $this->pricedCatalogRow(ModelCatalog::all());
        $existing = $this->stampFingerprint($catalog);
        $existing['priceIn'] = 42.0;
        $catalog['json'][CatalogPriceOwnership::PRICE_PINNED_KEY] = true;

        $this->assertSame('update', $decide->invoke($seeder, $catalog, $existing));

        $existing['name'] = 'Operator renamed this model';
        $this->assertSame('preserve', $decide->invoke($seeder, $catalog, $existing));
    }

    public function testAdoptsLegacyRowThatMatchesCatalogExactly(): void
    {
        // Legacy row predating the fingerprint: same values as the catalog,
        // BJSON has no fingerprint key. Safe to silently claim by writing the
        // fingerprint — no user-visible field changes.
        $target = ModelCatalog::all()[0];
        $legacy = $target;
        unset($legacy['json'][ModelCatalog::FINGERPRINT_KEY]);

        $existing = $this->seedExistingFromCatalog([$target['id'] => $legacy]);

        $connection = $this->buildConnection(existing: $existing, expectedWrites: 1);
        $seeder = new ModelSeeder($connection, 'prod');

        $result = $seeder->seed();

        $this->assertSame(1, $result->updated);
        $this->assertSame(0, $result->preserved);
    }

    public function testPreservesLegacyRowThatDivergesFromCatalog(): void
    {
        // Legacy row whose price differs from the catalog AND has no
        // fingerprint — could be a forgotten manual edit OR a stale seed; we
        // can't tell, so we err on the side of preserving operator data.
        $target = ModelCatalog::all()[0];
        $legacy = $target;
        $legacy['priceIn'] = 13.13;
        unset($legacy['json'][ModelCatalog::FINGERPRINT_KEY]);

        $existing = $this->seedExistingFromCatalog([$target['id'] => $legacy]);

        $connection = $this->buildConnection(existing: $existing, expectedWrites: 0);
        $seeder = new ModelSeeder($connection, 'prod');

        $result = $seeder->seed();

        $this->assertSame(1, $result->preserved);
        $this->assertSame(0, $result->updated);
    }

    /**
     * Build a Connection mock that returns the given rows for the seeder's
     * SELECT and asserts the expected number of write calls.
     *
     * @param list<array<string, mixed>>               $existing rows in catalog shape
     * @param (callable(array<int, mixed>): void)|null $onWrite
     */
    private function buildConnection(array $existing, int $expectedWrites, ?callable $onWrite = null): Connection
    {
        $mock = $this->createMock(Connection::class);

        $rows = array_map([$this, 'toDbRow'], $existing);

        // @phpstan-ignore-next-line method.notFound
        $mock->method('fetchAllAssociative')->willReturn($rows);

        // @phpstan-ignore-next-line method.notFound
        $expectation = $mock->expects($this->exactly($expectedWrites))->method('executeStatement');
        if (null === $onWrite) {
            $expectation->willReturn(1);
        } else {
            $expectation->willReturnCallback(function (string $sql, array $params = []) use ($onWrite): int {
                $onWrite($params);

                return 1;
            });
        }

        return $mock;
    }

    /**
     * @param list<array<string, mixed>> $catalog
     *
     * @return array<string, mixed>
     */
    private function pricedCatalogRow(array $catalog): array
    {
        foreach ($catalog as $row) {
            if ((float) ($row['priceIn'] ?? 0) > 0) {
                return $row;
            }
        }

        self::fail('Catalog has no positively priced row');
    }

    /**
     * Build a list of "existing" rows by overlaying the given catalog-shape
     * overrides on top of the full catalog. Indexes the override map by ID so
     * the calling test can target a specific catalog entry.
     *
     * @param array<int, array<string, mixed>> $overridesById
     *
     * @return list<array<string, mixed>>
     */
    private function seedExistingFromCatalog(array $overridesById): array
    {
        $rows = [];
        foreach (ModelCatalog::all() as $catalogRow) {
            $id = (int) $catalogRow['id'];
            if (isset($overridesById[$id])) {
                $rows[] = $overridesById[$id];
                continue;
            }
            $rows[] = $this->stampFingerprint($catalogRow);
        }

        return $rows;
    }

    /**
     * Translate a catalog-shape row into the BMODELS column shape returned by
     * Connection::fetchAllAssociative().
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function toDbRow(array $row): array
    {
        return [
            'BID' => $row['id'],
            'BSERVICE' => $row['service'],
            'BNAME' => $row['name'],
            'BTAG' => $row['tag'],
            'BPROVID' => $row['providerId'],
            'BPRICEIN' => $row['priceIn'],
            'BINUNIT' => $row['inUnit'],
            'BPRICEOUT' => $row['priceOut'],
            'BOUTUNIT' => $row['outUnit'],
            'BQUALITY' => $row['quality'],
            'BRATING' => $row['rating'],
            'BJSON' => json_encode($row['json'] ?? [], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * Embed the catalog fingerprint of $row into BJSON, mirroring what
     * ModelCatalog::upsert() persists at write time.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function stampFingerprint(array $row): array
    {
        $row['json'][ModelCatalog::FINGERPRINT_KEY] = ModelCatalog::fingerprint($row);

        return $row;
    }
}
