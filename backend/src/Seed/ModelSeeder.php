<?php

declare(strict_types=1);

namespace App\Seed;

use App\Model\ModelCatalog;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for the AI model catalog (BMODELS).
 *
 * Behaviour per row (decided in {@see decideAction()}):
 *
 *   1. **No DB row yet** → INSERT the catalog values + fingerprint.
 *      New models added to ModelCatalog land in the database on next deploy.
 *   2. **DB row exists, fingerprint stored, row still matches stored fingerprint**:
 *        - if catalog values differ → UPDATE catalog-owned columns + fingerprint
 *          ("code change rolled out to a row that nobody touched").
 *        - if catalog values are identical → SKIP (already in sync, no write).
 *   3. **DB row exists but values differ from the stored fingerprint** → PRESERVE.
 *      An admin edited the row via /config/ai-models after we last seeded it; we
 *      MUST NOT overwrite their changes on container restart.
 *   4. **DB row exists with no fingerprint at all** (legacy row predating the
 *      fingerprint mechanism):
 *        - if the row already matches the catalog exactly → silently adopt by
 *          writing the fingerprint (no visible change to the user).
 *        - otherwise → PRESERVE (assume the operator customised it manually,
 *          err on the side of not destroying data).
 *
 * Result: container startup only touches BMODELS for new models or genuine
 * code-side changes against untouched rows. Operator overrides survive every
 * restart, and we no longer issue dozens of redundant writes per boot.
 *
 * In dev/test the seeder additionally upserts mock test-models with negative IDs
 * (so they cannot collide with the auto-increment range used by real catalog
 * rows). The same fingerprint protection applies to them. In `test`, the seeder
 * also flips test models to selectable/showWhenFree so the admin UI exposes them
 * in the E2E stack.
 */
final readonly class ModelSeeder
{
    /**
     * Mock models for E2E/CI stacks. Negative IDs cannot collide with auto-increment.
     * Routed through TestProvider/OllamaProvider stubs.
     */
    public const TEST_MODELS = [
        ['id' => -1, 'service' => 'test', 'name' => 'test-model',      'tag' => 'chat',       'providerId' => 'test-model'],
        ['id' => -2, 'service' => 'test', 'name' => 'test-vectorize',  'tag' => 'vectorize',  'providerId' => 'test-vectorize'],
        ['id' => -3, 'service' => 'test', 'name' => 'test-pic2text',   'tag' => 'pic2text',   'providerId' => 'test-pic2text'],
        ['id' => -4, 'service' => 'test', 'name' => 'test-text2pic',   'tag' => 'text2pic',   'providerId' => 'test-text2pic'],
        ['id' => -5, 'service' => 'test', 'name' => 'test-text2vid',   'tag' => 'text2vid',   'providerId' => 'test-text2vid'],
        ['id' => -6, 'service' => 'test', 'name' => 'test-sound2text', 'tag' => 'sound2text', 'providerId' => 'test-sound2text'],
        ['id' => -7, 'service' => 'test', 'name' => 'test-text2sound', 'tag' => 'text2sound', 'providerId' => 'test-text2sound'],
        ['id' => -10, 'service' => 'ollama', 'name' => 'stub-chat-model',  'tag' => 'chat',      'providerId' => 'stub-chat-model'],
        ['id' => -11, 'service' => 'ollama', 'name' => 'stub-embed-model', 'tag' => 'vectorize', 'providerId' => 'stub-embed-model'],
    ];

    private const ACTION_INSERT = 'insert';
    private const ACTION_UPDATE = 'update';
    private const ACTION_PRESERVE = 'preserve';
    private const ACTION_SKIP = 'skip';

    public function __construct(
        private Connection $connection,
        private string $environment,
    ) {
    }

    public function seed(): SeedResult
    {
        $existing = $this->loadExistingRowsById();
        $counters = [
            self::ACTION_INSERT => 0,
            self::ACTION_UPDATE => 0,
            self::ACTION_PRESERVE => 0,
            self::ACTION_SKIP => 0,
        ];

        if (in_array($this->environment, ['dev', 'test'], true)) {
            foreach (self::TEST_MODELS as $base) {
                $row = array_merge($base, [
                    'selectable' => 0,
                    'active' => 1,
                    'priceIn' => 0,
                    'inUnit' => '-',
                    'priceOut' => 0,
                    'outUnit' => '-',
                    'quality' => 1,
                    'rating' => 0,
                    'json' => ['description' => 'Mock model for E2E/CI. No API key required.'],
                ]);
                $action = $this->processRow($row, $existing[$row['id']] ?? null);
                ++$counters[$action];
            }

            if ('test' === $this->environment) {
                $this->connection->executeStatement(
                    'UPDATE BMODELS SET BSELECTABLE = 1, BSHOWWHENFREE = 1 WHERE BID < 0'
                );
            }
        }

        foreach (ModelCatalog::all() as $catalog) {
            $action = $this->processRow($catalog, $existing[$catalog['id']] ?? null);
            ++$counters[$action];
        }

        return new SeedResult(
            'models',
            inserted: $counters[self::ACTION_INSERT],
            updated: $counters[self::ACTION_UPDATE],
            skipped: $counters[self::ACTION_SKIP],
            preserved: $counters[self::ACTION_PRESERVE],
        );
    }

    /**
     * Apply the decision returned by {@see decideAction()} and return its label
     * so the caller can tally per-bucket counts.
     *
     * @param array<string, mixed>      $catalog  catalog-side row (source of truth)
     * @param array<string, mixed>|null $existing DB row in catalog shape (null → not in DB)
     */
    private function processRow(array $catalog, ?array $existing): string
    {
        $action = $this->decideAction($catalog, $existing);

        if (self::ACTION_INSERT === $action || self::ACTION_UPDATE === $action) {
            ModelCatalog::upsert($this->connection, $catalog);
        }

        return $action;
    }

    /**
     * Pure decision function — does NOT touch the database. Extracted to keep
     * the table at the top of this class auditable and to make the algorithm
     * trivially unit-testable (see ModelSeederTest).
     *
     * @param array<string, mixed>      $catalog
     * @param array<string, mixed>|null $existing
     */
    private function decideAction(array $catalog, ?array $existing): string
    {
        if (null === $existing) {
            return self::ACTION_INSERT;
        }

        $stored = $existing['json'][ModelCatalog::FINGERPRINT_KEY] ?? null;
        $current = ModelCatalog::fingerprint($existing);
        $desired = ModelCatalog::fingerprint($catalog);

        if (!is_string($stored)) {
            // Legacy row predating the fingerprint mechanism. We can only safely
            // claim it as catalog-managed if it already matches the catalog
            // values bit-for-bit; otherwise we have no way of telling apart a
            // forgotten manual edit from a stale catalog version, so we leave
            // the row untouched.
            return $current === $desired ? self::ACTION_UPDATE : self::ACTION_PRESERVE;
        }

        if ($stored !== $current) {
            // Row was edited via the admin UI after we last seeded it. Preserve.
            return self::ACTION_PRESERVE;
        }

        if ($desired === $stored) {
            return self::ACTION_SKIP;
        }

        // Row matches the previously-seeded fingerprint, but the catalog has
        // moved on (e.g. price update shipped in a release). Roll the change in.
        return self::ACTION_UPDATE;
    }

    /**
     * Load every BMODELS row in catalog shape so the per-row decision logic does
     * not have to issue N SELECTs.
     *
     * @return array<int, array<string, mixed>> indexed by BID
     */
    private function loadExistingRowsById(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT BID, BSERVICE, BNAME, BTAG, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BJSON FROM BMODELS'
        );

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['BID']] = [
                'id' => (int) $row['BID'],
                'service' => (string) $row['BSERVICE'],
                'name' => (string) $row['BNAME'],
                'tag' => (string) $row['BTAG'],
                'providerId' => (string) $row['BPROVID'],
                'priceIn' => (float) $row['BPRICEIN'],
                'inUnit' => (string) $row['BINUNIT'],
                'priceOut' => (float) $row['BPRICEOUT'],
                'outUnit' => (string) $row['BOUTUNIT'],
                'quality' => (float) $row['BQUALITY'],
                'rating' => (float) $row['BRATING'],
                'json' => $this->decodeJson($row['BJSON'] ?? null),
            ];
        }

        return $byId;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw) || '' === $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
