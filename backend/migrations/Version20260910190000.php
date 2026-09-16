<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Set Cohere Rerank v3.5 (BID 346) to per-search pricing on existing installs.
 *
 * The row was seeded at $2.00 / 1,000 searches with no `BJSON.pricing_mode`.
 * CostCalculationService then treated `per1K` as $0.002/token (~1000× the real
 * price) as soon as rerank usage started being recorded (#1778). A catalog-only
 * edit does not reach an un-fingerprinted legacy row: ModelSeeder preserves it.
 *
 * Guarded on a missing / non-per_request pricing_mode so an operator who already
 * set the mode (or a later catalog shape) is left alone. Operator-owned flags
 * stay out of the SET clause.
 *
 * Idempotent and Galera-safe: raw addSql, no Schema API access.
 *
 * @see \App\Model\ModelCatalog
 */
final class Version20260910190000 extends AbstractMigration
{
    /** Must match ModelCatalog::FINGERPRINT_FLOAT_PRECISION. */
    private const FINGERPRINT_FLOAT_PRECISION = 6;

    private const BID = 346;

    public function getDescription(): string
    {
        return 'Set Cohere Rerank v3.5 (BID 346) to per_request pricing and write a matching '
            .'BJSON fingerprint so ModelSeeder keeps managing the row.';
    }

    public function up(Schema $schema): void
    {
        $model = $this->cohereRerankRow();
        $json = $model['json'];
        $json['__catalog_fingerprint'] = $this->fingerprint($model);

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
               AND (
                    JSON_EXTRACT(COALESCE(BJSON, '{}'), '$.pricing_mode') IS NULL
                    OR JSON_UNQUOTE(JSON_EXTRACT(COALESCE(BJSON, '{}'), '$.pricing_mode')) <> 'per_request'
               )
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
            'id' => self::BID,
        ]);
    }

    public function down(Schema $schema): void
    {
        // Deliberately empty. Reinstating per-token billing would overcharge.
    }

    /**
     * Snapshot of BID 346 exactly as authored in ModelCatalog on 2026-09-10 —
     * values AND json key order, because the fingerprint hashes the encoded
     * payload.
     *
     * @return array<string, mixed>
     */
    private function cohereRerankRow(): array
    {
        return [
            'id' => 346,
            'service' => 'cohere',
            'name' => 'Cohere Rerank v3.5',
            'tag' => 'rerank',
            'selectable' => 0,
            'active' => 1,
            'providerId' => 'rerank-v3.5',
            'priceIn' => 2.00,
            'inUnit' => 'per1K',
            'priceOut' => 0,
            'outUnit' => '-',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'Cohere rerank v2 API. Seeded unselectable; enable after adding a Cohere key on the Reranking tab.',
                'params' => ['model' => 'rerank-v3.5'],
                'features' => ['rerank'],
                'pricing_mode' => 'per_request',
            ],
        ];
    }

    /**
     * Local copy of ModelCatalog::fingerprint() frozen at authoring time.
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
