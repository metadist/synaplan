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
        return 'Raise Mistral Large 4 (BIDs 387/388) to the published $0.70 / $2.10 rate '
            .'with matching BJSON fingerprints (#2957).';
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
                   AND ABS(BPRICEOUT - :oldPriceOut) < 0.000001
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
                'oldPriceIn' => self::OLD_PRICE_IN,
                'oldPriceOut' => self::OLD_PRICE_OUT,
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
        $previous['priceIn'] = self::OLD_PRICE_IN;
        $previous['priceOut'] = self::OLD_PRICE_OUT;
        $previous['json']['cache_read_price_per_1M'] = 0.068;
        $previous['json']['description'] = self::PREVIOUS_DESCRIPTIONS[(int) $model['id']];

        return $current === $this->fingerprint($previous);
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
