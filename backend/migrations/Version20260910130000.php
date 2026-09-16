<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Raise the Jina Reranker v2 Multilingual input price (BID 345) from $0.02 to
 * $0.05 per 1M tokens on existing installs.
 *
 * Jina moved every Search Foundation API — reranker included — to $0.05/1M
 * tokens in May 2025; the machine-readable catalog at
 * https://api.jina.ai/v1/models lists `pricing.prompt = 0.00000005` for this
 * model. The catalog had carried the pre-increase $0.02 since the row was added,
 * and the daily drift check could not tell: LiteLLM lists a third value (0.018),
 * which is now recorded in ModelCatalog::LITELLM_DEVIATIONS (#1772).
 *
 * The row is seeded unselectable and rerank calls are not metered yet, so no
 * install has billed this rate — the correction is so the first install that
 * does bills the real price. It is shipped as a migration anyway, following the
 * catalog rollout convention: a catalog edit only reaches an existing row while
 * ModelSeeder still recognises it as unedited, and the seeder cannot tell an
 * operator's UI edit from a stale seed. Writing the full catalog snapshot with a
 * matching `BJSON.__catalog_fingerprint` keeps the row under catalog management
 * ({@see Version20260907120000} for the trap a bare price UPDATE springs).
 *
 * Guarded on the old price, so an operator who deliberately re-priced the row in
 * the admin UI keeps their value, exactly as a re-seed would leave it.
 *
 * Idempotent and Galera-safe: raw addSql, no Schema API access (see AGENTS.md —
 * the DBAL comparator throws on that cluster).
 *
 * @see \App\Model\ModelCatalog
 */
final class Version20260910130000 extends AbstractMigration
{
    /** Must match ModelCatalog::FINGERPRINT_FLOAT_PRECISION. */
    private const FINGERPRINT_FLOAT_PRECISION = 6;

    private const BID = 345;

    /**
     * The rate the row was seeded with. BPRICEIN is a float column, so the guard
     * compares with a tolerance rather than for equality.
     */
    private const OLD_PRICE_IN = 0.02;

    public function getDescription(): string
    {
        return 'Raise the Jina Reranker v2 Multilingual input price (BID 345) to Jina\'s $0.05/1M tokens '
            .'and write a matching BJSON fingerprint so ModelSeeder keeps managing the row.';
    }

    public function up(Schema $schema): void
    {
        $model = $this->jinaRerankerRow();
        $json = $model['json'];
        $json['__catalog_fingerprint'] = $this->fingerprint($model);

        // BSELECTABLE / BACTIVE / BISDEFAULT / BSHOWWHENFREE stay out of the SET
        // clause: they are operator-owned, so an admin's visibility choice survives
        // this correction exactly as it survives a re-seed. Every other
        // catalog-owned column IS written, because the fingerprint hashes all of
        // them — repairing only the price would leave the row mismatched again.
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
               AND ABS(BPRICEIN - :oldPriceIn) < 0.000001
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
            'oldPriceIn' => self::OLD_PRICE_IN,
        ]);
    }

    public function down(Schema $schema): void
    {
        // Deliberately empty. Reinstating $0.02 would under-bill a rate Jina no
        // longer offers, and re-break the fingerprint.
    }

    /**
     * Snapshot of BID 345 exactly as authored in ModelCatalog on 2026-09-10 —
     * values AND json key order, because the fingerprint hashes the encoded
     * payload.
     *
     * @return array<string, mixed>
     */
    private function jinaRerankerRow(): array
    {
        return [
            'id' => 345,
            'service' => 'jina',
            'name' => 'Jina Reranker v2 Multilingual',
            'tag' => 'rerank',
            'selectable' => 0,
            'active' => 1,
            'providerId' => 'jina-reranker-v2-base-multilingual',
            'priceIn' => 0.05,
            'inUnit' => 'per1M',
            'priceOut' => 0,
            'outUnit' => '-',
            'quality' => 8,
            'rating' => 1,
            'json' => [
                'description' => 'Jina rerank API. Seeded unselectable; enable after adding a Jina key on the Reranking tab.',
                'params' => ['model' => 'jina-reranker-v2-base-multilingual'],
                'features' => ['rerank'],
            ],
        ];
    }

    /**
     * Local copy of ModelCatalog::fingerprint() frozen at authoring time, so the
     * migration stays self-contained (migrations must not import app code that can
     * drift). Same contract as Version20260907120000.
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
