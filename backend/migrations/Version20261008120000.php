<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Author the Claude Sonnet 5.5 cache-read rate (BIDs 379/380) on existing
 * installs (#2392).
 *
 * Verified 2026-10-08 against https://platform.claude.com/docs/en/about-claude/pricing:
 * the model table lists Claude Sonnet 5.5 at "$2 / MTok" base input and
 * "$0.10 / MTok" for "Cache hits and refreshes", with the footnote "Cache hits
 * and refreshes on Claude Opus 5.5 and Claude Sonnet 5.5 are priced at 0.05x
 * the base input price." The rows authored no `cache_read_price_per_1M`, so
 * CostCalculationService fell back to CACHE_READ_DISCOUNT_ANTHROPIC (0.1x) and
 * billed every cache hit at $0.20 — twice the provider rate since the rows were
 * added on 2026-09-29. Input, output and both cache-write rates are unchanged.
 *
 * A catalog edit alone does NOT reach existing installs while ModelSeeder still
 * recognises the row as unedited — and a bare JSON_SET without refreshing
 * `BJSON.__catalog_fingerprint` freezes the row out of every future catalog
 * update ({@see Version20260907120000}). Writing the full catalog snapshot with
 * a matching fingerprint keeps the row under catalog management.
 *
 * Guarded the way ModelSeeder decides it may manage a row: only a row that is
 * still exactly the previous catalog snapshot is written. Its catalog-owned
 * columns must hash to the previous catalog fingerprint, and a stored
 * fingerprint must match them too, so an admin edit of any catalog-owned field
 * (price, name, units, description, JSON) keeps the whole row as the operator
 * left it, exactly as a re-seed would. A legacy row without a stored
 * fingerprint is written only when it matches the previous snapshot
 * bit-for-bit.
 *
 * Idempotent and Galera-safe: raw addSql, no Schema API access (see AGENTS.md —
 * the DBAL comparator throws on that cluster).
 *
 * @see \App\Model\ModelCatalog
 */
final class Version20261008120000 extends AbstractMigration
{
    /** Must match ModelCatalog::FINGERPRINT_FLOAT_PRECISION. */
    private const FINGERPRINT_FLOAT_PRECISION = 6;

    public function getDescription(): string
    {
        return 'Author the Claude Sonnet 5.5 cache-read rate of $0.10/1M (BIDs 379/380) '
            .'with matching BJSON fingerprints (#2392).';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->correctedRows() as $model) {
            if (!$this->isStillThePreviousCatalogRow($model)) {
                continue;
            }

            $json = $model['json'];
            $json['__catalog_fingerprint'] = $this->fingerprint($model);

            // BSELECTABLE / BACTIVE / BISDEFAULT / BSHOWWHENFREE stay out of the SET
            // clause: they are operator-owned, so an admin's visibility choice survives
            // this correction exactly as it survives a re-seed. Every other
            // catalog-owned column IS written, because the fingerprint hashes all of
            // them — repairing only the cache key would leave the row mismatched again.
            $this->addSql(<<<'SQL'
                UPDATE BMODELS
                   SET BSERVICE = :service,
                       BNAME = :name,
                       BTAG = :tag,
                       BPROVID = :providerId,
                       BPRICEIN = :priceIn,
                       BINUNIT = :inUnit,
                       BPRICEOUT = :priceOut,
                       BOUTUNIT = :outUnit,
                       BQUALITY = :quality,
                       BRATING = :rating,
                       BJSON = :json
                 WHERE BID = :id
                   AND BPROVID = :providerId
                   AND JSON_EXTRACT(BJSON, '$.cache_read_price_per_1M') IS NULL
                SQL, [
                'service' => $model['service'],
                'name' => $model['name'],
                'tag' => $model['tag'],
                'providerId' => $model['providerId'],
                'priceIn' => $model['priceIn'],
                'inUnit' => $model['inUnit'],
                'priceOut' => $model['priceOut'],
                'outUnit' => $model['outUnit'],
                'quality' => $model['quality'],
                'rating' => $model['rating'],
                'json' => json_encode($json, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
                'id' => $model['id'],
            ]);
        }
    }

    /**
     * Mirrors ModelSeeder::decideAction(): the row's catalog-owned columns hash
     * to the previous catalog snapshot, and an admin has not edited them since
     * they were seeded (stored fingerprint, when present, equals that hash).
     *
     * @param array<string, mixed> $model corrected catalog row
     */
    private function isStillThePreviousCatalogRow(array $model): bool
    {
        $row = $this->connection->fetchAssociative(
            'SELECT BSERVICE, BNAME, BTAG, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BJSON FROM BMODELS WHERE BID = :id',
            ['id' => $model['id']],
        );
        if (false === $row) {
            return false;
        }

        $json = json_decode((string) $row['BJSON'], true);
        if (!is_array($json)) {
            return false;
        }
        $stored = $json['__catalog_fingerprint'] ?? null;
        unset($json['__catalog_fingerprint']);

        $current = $this->fingerprint([
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
            'json' => $json,
        ]);
        if (is_string($stored) && $stored !== $current) {
            return false;
        }

        $previous = $model;
        unset($previous['json']['cache_read_price_per_1M']);

        return $current === $this->fingerprint($previous);
    }

    public function down(Schema $schema): void
    {
        // Deliberately empty. Removing the override would bill every Sonnet 5.5
        // cache hit at twice the provider rate again, and re-break the fingerprint.
    }

    /**
     * Snapshots of BIDs 379/380 exactly as authored in ModelCatalog on
     * 2026-10-08 — values AND json key order, because the fingerprint hashes
     * the encoded payload.
     *
     * @return list<array<string, mixed>>
     */
    private function correctedRows(): array
    {
        return [
            [
                'id' => 379,
                'service' => 'Anthropic',
                'name' => 'Claude Sonnet 5.5',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'claude-sonnet-5-5',
                'priceIn' => 2,
                'inUnit' => 'per1M',
                'priceOut' => 10,
                'outUnit' => 'per1M',
                'quality' => 10,
                'rating' => 1,
                'json' => [
                    'description' => 'Claude Sonnet 5.5 - faster Sonnet for everyday tasks, bug fixes, and polished documents. Adaptive thinking. 1M context, 128K max output.',
                    'max_tokens' => 128000,
                    'params' => ['model' => 'claude-sonnet-5-5'],
                    'features' => ['vision', 'reasoning', 'tool_use'],
                    'meta' => ['context_window' => '1000000', 'max_output' => '128000', 'knowledge_cutoff' => '2026-06-30', 'reasoning_effort_default' => 'high'],
                    'cache_read_price_per_1M' => 0.10,
                ],
            ],
            [
                'id' => 380,
                'service' => 'Anthropic',
                'name' => 'Claude Sonnet 5.5 (Vision)',
                'tag' => 'pic2text',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'claude-sonnet-5-5',
                'priceIn' => 2,
                'inUnit' => 'per1M',
                'priceOut' => 10,
                'outUnit' => 'per1M',
                'quality' => 10,
                'rating' => 1,
                'json' => [
                    'description' => 'Claude Sonnet 5.5 for image analysis and vision tasks.',
                    'prompt' => 'Describe the image in detail. Extract any text you see.',
                    'params' => ['model' => 'claude-sonnet-5-5'],
                    'features' => ['vision'],
                    'meta' => ['supports_images' => true],
                    'cache_read_price_per_1M' => 0.10,
                ],
            ],
        ];
    }

    /**
     * Local copy of ModelCatalog::fingerprint() frozen at authoring time, so the
     * migration stays self-contained (migrations must not import app code that can
     * drift). Same contract as Version20260924120000.
     *
     * @param array<string, mixed> $row
     */
    private function fingerprint(array $row): string
    {
        $payload = [
            'service' => (string) $row['service'],
            'name' => (string) $row['name'],
            'tag' => (string) $row['tag'],
            'providerId' => (string) $row['providerId'],
            'priceIn' => round((float) $row['priceIn'], self::FINGERPRINT_FLOAT_PRECISION),
            'inUnit' => (string) $row['inUnit'],
            'priceOut' => round((float) $row['priceOut'], self::FINGERPRINT_FLOAT_PRECISION),
            'outUnit' => (string) $row['outUnit'],
            'quality' => round((float) $row['quality'], self::FINGERPRINT_FLOAT_PRECISION),
            'rating' => round((float) $row['rating'], self::FINGERPRINT_FLOAT_PRECISION),
            'json' => $row['json'],
        ];

        return hash(
            'sha256',
            (string) json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)
        );
    }
}
