<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Re-adopt the GPT-5.6 Sol rows (BIDs 251, 252) into catalog management.
 *
 * {@see Version20260830190000} force-applied OpenAI's 2026-08-21 Sol price cut
 * with a bare `UPDATE BMODELS SET BPRICEIN/BPRICEOUT` and did NOT refresh
 * `BJSON.__catalog_fingerprint`. The stored fingerprint therefore still
 * describes the OLD 5/30 row while the columns hold 4/20, so ModelSeeder's
 * `stored !== recomputed` check reads the row as an operator edit and PRESERVES
 * it — permanently. Sol has been frozen out of every catalog update since, and
 * this release's cache-rate corrections (cached input $0.40, cache writes 1.25x)
 * would silently skip it too, leaving cached Sol tokens billed at the 50%
 * fallback: $2.00 per 1M instead of $0.40, a 5x overcharge resold to customers.
 *
 * Writing the full catalog snapshot plus a matching fingerprint makes stored ===
 * recomputed === desired again, so the seeder reports these rows as up to date
 * and resumes rolling future changes into them as ordinary UPDATEs.
 *
 * Only Sol needs this. Every other row this release touches still carries an
 * intact fingerprint, so `app:seed` updates it on deploy without help — and rows
 * an operator genuinely edited in the admin UI must stay preserved.
 *
 * Idempotent and Galera-safe: raw INSERT ... ON DUPLICATE KEY UPDATE, no Schema
 * API access (see AGENTS.md — the DBAL comparator throws on this cluster).
 *
 * @see \App\Model\ModelCatalog
 */
final class Version20260904120000 extends AbstractMigration
{
    /** Must match ModelCatalog::FINGERPRINT_FLOAT_PRECISION. */
    private const FINGERPRINT_FLOAT_PRECISION = 6;

    public function getDescription(): string
    {
        return 'Repair the GPT-5.6 Sol BJSON fingerprint broken by Version20260830190000 so ModelSeeder '
            .'stops preserving BIDs 251/252, and deliver the corrected OpenAI cache rates to them.';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->solRows() as $model) {
            $json = $model['json'];
            $json['__catalog_fingerprint'] = $this->fingerprint($model);

            // BSELECTABLE / BACTIVE / BISDEFAULT / BSHOWWHENFREE stay out of the
            // UPDATE clause: they are operator-owned, so an admin's visibility
            // choice survives this repair exactly as it survives a re-seed.
            $this->addSql(<<<'SQL'
                INSERT INTO BMODELS (BID, BSERVICE, BNAME, BTAG, BSELECTABLE, BACTIVE, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BISDEFAULT, BSHOWWHENFREE, BJSON)
                VALUES (:id, :service, :name, :tag, :selectable, :active, :providerId, :priceIn, :inUnit, :priceOut, :outUnit, :quality, :rating, 0, 0, :json)
                ON DUPLICATE KEY UPDATE
                    BSERVICE = VALUES(BSERVICE), BNAME = VALUES(BNAME), BTAG = VALUES(BTAG),
                    BPROVID = VALUES(BPROVID), BPRICEIN = VALUES(BPRICEIN),
                    BINUNIT = VALUES(BINUNIT), BPRICEOUT = VALUES(BPRICEOUT),
                    BOUTUNIT = VALUES(BOUTUNIT), BQUALITY = VALUES(BQUALITY),
                    BRATING = VALUES(BRATING), BJSON = VALUES(BJSON)
            SQL, [
                'id' => $model['id'],
                'service' => $model['service'],
                'name' => $model['name'],
                'tag' => $model['tag'],
                'selectable' => $model['selectable'],
                'active' => $model['active'],
                'providerId' => $model['providerId'],
                'priceIn' => $model['priceIn'],
                'inUnit' => $model['inUnit'],
                'priceOut' => $model['priceOut'],
                'outUnit' => $model['outUnit'],
                'quality' => $model['quality'],
                'rating' => $model['rating'],
                'json' => json_encode($json, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        // Deliberately empty. Reinstating the broken fingerprint would only
        // restore the bug, and the row values themselves are the current
        // published Sol pricing either way.
    }

    /**
     * Snapshot of the Sol rows exactly as authored in ModelCatalog on
     * 2026-09-04 — values AND json key order, because the fingerprint hashes
     * the encoded payload.
     *
     * @return list<array<string, mixed>>
     */
    private function solRows(): array
    {
        return [
            [
                'id' => 251,
                'service' => 'OpenAI',
                'name' => 'GPT-5.6 Sol',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gpt-5.6-sol',
                'priceIn' => 4,
                'inUnit' => 'per1M',
                'priceOut' => 20,
                'outUnit' => 'per1M',
                'quality' => 10,
                'rating' => 1,
                'json' => [
                    'description' => 'OpenAI GPT-5.6 Sol - flagship model for coding, knowledge work, cybersecurity, and science. State-of-the-art results with strong performance per dollar. Configurable reasoning effort.',
                    'max_tokens' => 128000,
                    'params' => ['model' => 'gpt-5.6-sol'],
                    'features' => ['reasoning', 'vision', 'tool_use'],
                    'cache_read_price_per_1M' => 0.40,
                    'cache_write_multiplier' => 1.25,
                    'meta' => [
                        'api' => 'responses',
                        'context_window' => '1050000',
                        'max_output' => '128000',
                        'reasoning_effort_default' => 'medium',
                    ],
                ],
            ],
            [
                'id' => 252,
                'service' => 'OpenAI',
                'name' => 'GPT-5.6 Sol (Vision)',
                'tag' => 'pic2text',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gpt-5.6-sol',
                'priceIn' => 4,
                'inUnit' => 'per1M',
                'priceOut' => 20,
                'outUnit' => 'per1M',
                'quality' => 10,
                'rating' => 1,
                'json' => [
                    'description' => 'OpenAI GPT-5.6 Sol for image analysis and vision tasks. Strong computer-use and design judgment.',
                    'prompt' => 'Describe the image in detail. Extract any text you see.',
                    'params' => ['model' => 'gpt-5.6-sol'],
                    'features' => ['reasoning', 'vision'],
                    'cache_read_price_per_1M' => 0.40,
                    'cache_write_multiplier' => 1.25,
                    'meta' => [
                        'api' => 'responses',
                        'supports_images' => true,
                        'context_window' => '1050000',
                        'max_output' => '128000',
                    ],
                ],
            ],
        ];
    }

    /**
     * Local copy of ModelCatalog::fingerprint() frozen at authoring time, so the
     * migration stays self-contained (migrations must not import app code that
     * can drift). Same contract as Version20260819080000.
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
