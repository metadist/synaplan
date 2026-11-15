<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Raise Mistral Large 4 (BIDs 387/388) to the published GA rate (#2957).
 *
 * Verified 2026-11-15 against https://docs.mistral.ai/inference/pricing and
 * https://docs.mistral.ai/models/mistral-large-4-0: input $0.70, cached input
 * $0.07, output $2.10 per 1M tokens. The catalog still held the October launch
 * discount (0.68 / 0.068 / 2.09). LiteLLM now publishes the same $0.70 / $0.07
 * / $2.10, so the old LITELLM_DEVIATIONS pin of the pre-discount list rate is
 * deleted in the same change.
 *
 * A catalog edit alone does NOT reach existing installs while ModelSeeder still
 * recognises the row as unedited — and a bare price UPDATE without refreshing
 * `BJSON.__catalog_fingerprint` freezes the row out of every future catalog
 * update ({@see Version20260907120000}). Writing the full catalog snapshot with
 * a matching fingerprint keeps the row under catalog management.
 *
 * Guarded the way ModelSeeder decides it may manage a row: only a row that is
 * still exactly the previous catalog snapshot is written. Its catalog-owned
 * columns must hash to the previous catalog fingerprint, and a stored
 * fingerprint must match them too, so an admin edit of any catalog-owned field
 * keeps the whole row as the operator left it. The SQL guard on the old prices
 * is the same check a reader can see without recomputing the hash.
 *
 * Idempotent and Galera-safe: raw addSql, no Schema API access (see AGENTS.md —
 * the DBAL comparator throws on that cluster).
 *
 * @see \App\Model\ModelCatalog
 */
final class Version20261115140000 extends AbstractMigration
{
    /** Must match ModelCatalog::FINGERPRINT_FLOAT_PRECISION. */
    private const FINGERPRINT_FLOAT_PRECISION = 6;

    private const OLD_PRICE_IN = 0.68;

    private const OLD_PRICE_OUT = 2.09;

    /** @var array<int, string> */
    private const PREVIOUS_DESCRIPTIONS = [
        387 => 'Mistral Large 4 - open-weight multimodal flagship with reasoning and tool use. 512K context. OpenAI-compatible chat endpoint. Public preview, launch pricing.',
        388 => 'Mistral Large 4 vision - describe images and extract text via the chat endpoint. Same model as the chat row. Public preview, launch pricing.',
    ];

    public function getDescription(): string
    {
        return 'Raise Mistral Large 4 (BIDs 387/388) to the published $0.70 / $2.10 rate, '
            .'restore a Scaleway row a price sync wrote in euros, and refresh BJSON fingerprints (#2957).';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->correctedRows() as $model) {
            $read = $this->previousCatalogRow($model);
            if (null === $read) {
                continue;
            }

            $this->queueCatalogUpdate($model, $read);
        }

        $synced = $this->syncedEuroScalewayRow();
        if (null !== $synced) {
            $this->queueCatalogUpdate($this->scalewayCatalogRow(), $synced);
        }
    }

    /**
     * The UPDATE matches every catalog-owned column of the row that was read.
     * addSql runs after up() returns, so a price-only WHERE would still
     * overwrite a rename or JSON edit that landed in between.
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
     * The row's catalog-owned columns hash to the previous catalog snapshot,
     * and an admin has not edited them since they were seeded.
     *
     * @param array<string, mixed> $model corrected catalog row
     *
     * @return array<string, mixed>|null raw BMODELS row when it may be updated
     */
    private function previousCatalogRow(array $model): ?array
    {
        $row = $this->readCatalogRow((int) $model['id']);
        if (null === $row) {
            return null;
        }

        $json = json_decode((string) $row['BJSON'], true);
        if (!is_array($json)) {
            return null;
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
            return null;
        }

        $previous = $model;
        $previous['priceIn'] = self::OLD_PRICE_IN;
        $previous['priceOut'] = self::OLD_PRICE_OUT;
        $previous['json']['cache_read_price_per_1M'] = 0.068;
        $previous['json']['description'] = self::PREVIOUS_DESCRIPTIONS[(int) $model['id']];

        return $current === $this->fingerprint($previous) ? $row : null;
    }

    /**
     * A price sync that copied Scaleway's euro list into the USD columns.
     * An admin price, or a row whose other catalog fields were edited, is left
     * alone. The catalog price itself did not move, so the seeder would
     * otherwise keep the synced rate.
     *
     * @return array<string, mixed>|null
     */
    private function syncedEuroScalewayRow(): ?array
    {
        $row = $this->readCatalogRow(426);
        if (null === $row) {
            return null;
        }
        if (abs((float) $row['BPRICEIN'] - 0.40) >= 0.000001 || abs((float) $row['BPRICEOUT'] - 0.80) >= 0.000001) {
            return null;
        }

        $json = json_decode((string) $row['BJSON'], true);
        if (!is_array($json) || 'litellm' !== ($json['__price_owner'] ?? null)) {
            return null;
        }
        if (abs((float) ($json['cache_read_price_per_1M'] ?? -1) - 0.08) >= 0.000001) {
            return null;
        }

        $snapshot = $json['__catalog_price_at_sync'] ?? null;
        if (!is_array($snapshot)
            || abs((float) ($snapshot['priceIn'] ?? -1) - 0.4466) >= 0.000001
            || abs((float) ($snapshot['priceOut'] ?? -1) - 0.8932) >= 0.000001
            || abs((float) ($snapshot['cache_read_price_per_1M'] ?? -1) - 0.0893) >= 0.000001
        ) {
            return null;
        }

        $catalog = $this->scalewayCatalogRow();
        $live = [
            'service' => (string) $row['BSERVICE'],
            'name' => (string) $row['BNAME'],
            'tag' => (string) $row['BTAG'],
            'providerId' => (string) $row['BPROVID'],
            'quality' => (float) $row['BQUALITY'],
            'rating' => (float) $row['BRATING'],
            'json' => $json,
        ];

        return $this->fingerprint($this->withoutPrice($live)) === $this->fingerprint($this->withoutPrice($catalog))
            ? $row
            : null;
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
     * Price columns and the keys a price sync writes are ignored, so a pure
     * price write still matches the catalog.
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
     * @return array<string, mixed>
     */
    private function scalewayCatalogRow(): array
    {
        return [
            'id' => 426,
            'service' => 'Scaleway',
            'name' => 'DeepSeek V4 Flash',
            'tag' => 'chat',
            'providerId' => 'deepseek-v4-flash-0731',
            'priceIn' => 0.4466,
            'inUnit' => 'per1M',
            'priceOut' => 0.8932,
            'outUnit' => 'per1M',
            'quality' => 8,
            'rating' => 1,
            'json' => [
                'description' => 'DeepSeek V4 Flash (0731) on Scaleway (Paris). Preview. Chat and code, no images. Cached input is €0.08 per million.',
                'max_tokens' => 32768,
                'params' => ['model' => 'deepseek-v4-flash-0731'],
                'features' => ['reasoning', 'tool_use', 'code', 'multilingual'],
                'cache_read_price_per_1M' => 0.0893,
                'meta' => ['context_window' => '262144', 'max_output' => '32768', 'host' => 'api.scaleway.ai', 'jurisdiction' => 'FR', 'preview' => true],
            ],
        ];
    }

    public function down(Schema $schema): void
    {
        // Deliberately empty. Reinstating the launch discount would under-bill
        // the rate Mistral now publishes, and re-break the fingerprint.
    }

    /**
     * Snapshots of BIDs 387/388 exactly as authored in ModelCatalog on
     * 2026-11-15 — values AND json key order, because the fingerprint hashes
     * the encoded payload.
     *
     * @return list<array<string, mixed>>
     */
    private function correctedRows(): array
    {
        return [
            [
                'id' => 387,
                'service' => 'Mistral',
                'name' => 'Mistral Large 4',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'mistral-large-4',
                'priceIn' => 0.70,
                'inUnit' => 'per1M',
                'priceOut' => 2.10,
                'outUnit' => 'per1M',
                'quality' => 10,
                'rating' => 3,
                'json' => [
                    'description' => 'Mistral Large 4 - open-weight multimodal flagship with reasoning and tool use. 512K context. OpenAI-compatible chat endpoint.',
                    'max_tokens' => 131072,
                    'params' => ['model' => 'mistral-large-4'],
                    'cache_read_price_per_1M' => 0.07,
                    'meta' => ['family' => 'mistral', 'jurisdiction' => 'EU', 'context_window' => '524288', 'max_output' => '131072'],
                    'features' => ['vision', 'reasoning', 'tool_use'],
                ],
            ],
            [
                'id' => 388,
                'service' => 'Mistral',
                'name' => 'Mistral Large 4 (Vision)',
                'tag' => 'pic2text',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'mistral-large-4',
                'priceIn' => 0.70,
                'inUnit' => 'per1M',
                'priceOut' => 2.10,
                'outUnit' => 'per1M',
                'quality' => 10,
                'rating' => 2,
                'json' => [
                    'description' => 'Mistral Large 4 vision - describe images and extract text via the chat endpoint. Same model as the chat row.',
                    'prompt' => 'Describe the image in detail. Extract any text you see.',
                    'max_tokens' => 8192,
                    'params' => ['model' => 'mistral-large-4'],
                    'cache_read_price_per_1M' => 0.07,
                    'features' => ['vision', 'ocr', 'multilingual'],
                    'meta' => ['supports_images' => true],
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
