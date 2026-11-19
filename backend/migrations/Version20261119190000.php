<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pin Mistral Large 3 (BID 245) to `mistral-large-2512` (#3187).
 *
 * `mistral-large-latest` is an API name of Mistral Large 4 as of 2026-11-19
 * ($0.70 in / $0.07 cached / $2.10 out). The row is Large 3, whose card still
 * lists $0.50 / $1.50, and LiteLLM's `mistral/mistral-large-2512` agrees,
 * including cache at $0.05. Sending the alias billed Large 3 rates for a
 * Large 4 call.
 *
 * A catalog edit alone does not repair a row whose price the sync already
 * replaced with the alias rate: that stamp keeps the LiteLLM price across a
 * non-price catalog change. This migration rewrites both the untouched
 * snapshot and that synced price, and refreshes `BJSON.__catalog_fingerprint`
 * so ModelSeeder keeps managing the row. An admin edit of any other field is
 * left alone.
 *
 * Idempotent and Galera-safe: raw addSql, no Schema API access (see AGENTS.md —
 * the DBAL comparator throws on that cluster).
 *
 * @see \App\Model\ModelCatalog
 */
final class Version20261119190000 extends AbstractMigration
{
    /** Must match ModelCatalog::FINGERPRINT_FLOAT_PRECISION. */
    private const FINGERPRINT_FLOAT_PRECISION = 6;

    public function getDescription(): string
    {
        return 'Pin Mistral Large 3 (BID 245) to mistral-large-2512 so it stops sending '
            .'the Large 4 alias, and refresh the BJSON fingerprint (#3187).';
    }

    public function up(Schema $schema): void
    {
        $row = $this->readCatalogRow(245);
        if (null === $row) {
            return;
        }

        $decoded = json_decode((string) $row['BJSON'], true);
        if (!is_array($decoded)) {
            return;
        }

        $shape = $this->shape($row, $decoded);
        $previous = $this->previousCatalogRow();
        $stored = $decoded['__catalog_fingerprint'] ?? null;
        $current = $this->fingerprint($this->withoutFingerprint($shape));
        $untouched = (!is_string($stored) || $stored === $current)
            && $current === $this->fingerprint($previous);
        $synced = 'litellm' === ($decoded['__price_owner'] ?? null)
            && 'mistral-large-latest' === (string) $row['BPROVID']
            && abs((float) $row['BPRICEIN'] - 0.70) < 0.000001
            && abs((float) $row['BPRICEOUT'] - 2.10) < 0.000001
            && $this->fingerprint($this->withoutPrice($shape)) === $this->fingerprint($this->withoutPrice($previous));

        if (!$untouched && !$synced) {
            return;
        }

        $this->queueCatalogUpdate($this->correctedRow(), $row);
    }

    public function down(Schema $schema): void
    {
        // Deliberately empty. Pointing the row back at mistral-large-latest
        // would send Large 4 and bill Large 3 rates again.
    }

    /**
     * The UPDATE matches every catalog-owned column of the row that was read.
     * addSql runs after up() returns, so a narrower WHERE would still
     * overwrite an edit that landed in between.
     *
     * BSELECTABLE / BACTIVE / BISDEFAULT / BSHOWWHENFREE stay out of both
     * clauses: they are operator-owned.
     *
     * @param array<string, mixed> $model corrected catalog row
     * @param array<string, mixed> $read  raw BMODELS row
     */
    private function queueCatalogUpdate(array $model, array $read): void
    {
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
               AND BSERVICE = :prevService
               AND BNAME = :prevName
               AND BTAG = :prevTag
               AND BPROVID = :prevProviderId
               AND BINUNIT = :prevInUnit
               AND BOUTUNIT = :prevOutUnit
               AND ABS(BPRICEIN - :prevPriceIn) < 0.000001
               AND ABS(BPRICEOUT - :prevPriceOut) < 0.000001
               AND ABS(BQUALITY - :prevQuality) < 0.000001
               AND ABS(BRATING - :prevRating) < 0.000001
               AND BJSON = :prevJson
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
            'prevService' => (string) $read['BSERVICE'],
            'prevName' => (string) $read['BNAME'],
            'prevTag' => (string) $read['BTAG'],
            'prevProviderId' => (string) $read['BPROVID'],
            'prevInUnit' => (string) $read['BINUNIT'],
            'prevOutUnit' => (string) $read['BOUTUNIT'],
            'prevPriceIn' => (float) $read['BPRICEIN'],
            'prevPriceOut' => (float) $read['BPRICEOUT'],
            'prevQuality' => (float) $read['BQUALITY'],
            'prevRating' => (float) $read['BRATING'],
            'prevJson' => (string) $read['BJSON'],
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>
     */
    private function shape(array $row, array $json): array
    {
        return [
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
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function withoutFingerprint(array $row): array
    {
        $copy = $row;
        $json = is_array($copy['json'] ?? null) ? $copy['json'] : [];
        unset($json['__catalog_fingerprint']);
        $copy['json'] = $json;

        return $copy;
    }

    /**
     * Price columns and the keys a price sync writes are ignored, so a pure
     * price write still matches the previous catalog snapshot.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function withoutPrice(array $row): array
    {
        $copy = $row;
        $copy['priceIn'] = 0.0;
        $copy['priceOut'] = 0.0;
        $copy['inUnit'] = '';
        $copy['outUnit'] = '';

        $json = is_array($copy['json'] ?? null) ? $copy['json'] : [];
        unset(
            $json['__catalog_fingerprint'],
            $json['__price_owner'],
            $json['__catalog_price_at_sync'],
            $json['pricePinned'],
            $json['pricing_mode'],
            $json['cache_read_price_per_1M'],
            $json['mode_prices'],
        );
        $copy['json'] = $json;

        return $copy;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCatalogRow(int $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT BSERVICE, BNAME, BTAG, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BJSON FROM BMODELS WHERE BID = :id',
            ['id' => $id],
        );

        return false === $row ? null : $row;
    }

    /**
     * BID 245 exactly as authored before this migration.
     *
     * @return array<string, mixed>
     */
    private function previousCatalogRow(): array
    {
        $row = $this->correctedRow();
        $row['providerId'] = 'mistral-large-latest';
        $row['json']['params'] = ['model' => 'mistral-large-latest'];

        return $row;
    }

    /**
     * BID 245 exactly as authored in ModelCatalog on 2026-11-19 — values AND
     * json key order, because the fingerprint hashes the encoded payload.
     *
     * @return array<string, mixed>
     */
    private function correctedRow(): array
    {
        return [
            'id' => 245,
            'service' => 'Mistral',
            'name' => 'Mistral Large 3',
            'tag' => 'chat',
            'providerId' => 'mistral-large-2512',
            'priceIn' => 0.50,
            'inUnit' => 'per1M',
            'priceOut' => 1.50,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 3,
            'json' => [
                'description' => 'Mistral Large 3 - state-of-the-art, open-weight, general-purpose multimodal model. OpenAI-compatible chat endpoint.',
                'max_tokens' => 8192,
                'params' => ['model' => 'mistral-large-2512'],
                'cache_read_price_per_1M' => 0.05,
                'meta' => ['context_window' => '262144', 'max_output' => '8192'],
                'features' => ['tool_use'],
            ],
        ];
    }

    /**
     * Local copy of ModelCatalog::fingerprint() frozen at authoring time.
     * Migrations must not import app code that can drift.
     *
     * @param array<string, mixed> $row
     */
    private function fingerprint(array $row): string
    {
        $json = is_array($row['json'] ?? null) ? $row['json'] : [];
        unset($json['__catalog_fingerprint']);

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
            'json' => $json,
        ];

        return hash(
            'sha256',
            (string) json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)
        );
    }
}
