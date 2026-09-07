<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Correct the Veo 3.1 Fast per-second price (BID 195) on existing installs.
 *
 * {@see Version20260713190000} raised the row to $0.15/sec with 720p/1080p/4K
 * tiers of 0.15/0.18/0.45. Google's published rate for
 * `veo-3.1-fast-generate-preview` is 0.10/0.12/0.30 with audio, on both the
 * Gemini API and Vertex AI, and it never was 0.15: the value came from LiteLLM's
 * registry, which listed a flat 0.15 for the Gemini keys until BerriAI corrected
 * it to 0.10 + tier keys on 2026-08-31, and our 0.18/0.45 were the previous
 * (correct) tiers scaled by the same 1.5. Costs are resold at raw + markup, so
 * every Veo Fast second has been billed 50% over cost since 2026-07-13.
 *
 * A catalog edit alone does NOT reach existing installs. Version20260713190000
 * force-applied the wrong price with a bare `UPDATE BMODELS SET BPRICEOUT/BJSON`
 * and did not refresh `BJSON.__catalog_fingerprint`, so ModelSeeder reads
 * `stored !== recomputed`, takes the row for an operator edit and PRESERVES it
 * permanently — the identical breakage {@see Version20260904120000} had to repair
 * for the GPT-5.6 Sol rows. Writing the full catalog snapshot together with a
 * matching fingerprint makes stored === recomputed === desired again, so the
 * seeder resumes rolling future catalog changes into this row on its own.
 *
 * Guarded on the wrong price instead of applied unconditionally: an operator who
 * deliberately re-priced Veo Fast in the admin UI keeps their value, exactly as a
 * re-seed would leave it.
 *
 * Idempotent and Galera-safe: raw addSql, no Schema API access (see AGENTS.md —
 * the DBAL comparator throws on that cluster).
 *
 * @see \App\Model\ModelCatalog
 */
final class Version20260907120000 extends AbstractMigration
{
    /** Must match ModelCatalog::FINGERPRINT_FLOAT_PRECISION. */
    private const FINGERPRINT_FLOAT_PRECISION = 6;

    private const BID = 195;

    /**
     * The headline rate Version20260713190000 wrote. BPRICEOUT is a float column,
     * so the guard compares with a tolerance rather than for equality.
     */
    private const WRONG_PRICE_OUT = 0.15;

    public function getDescription(): string
    {
        return 'Correct the Veo 3.1 Fast per-second price (BID 195) to Google\'s published 0.10/0.12/0.30 and '
            .'repair the BJSON fingerprint Version20260713190000 broke, so ModelSeeder stops preserving the row.';
    }

    public function up(Schema $schema): void
    {
        $model = $this->veoFastRow();
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
               AND ABS(BPRICEOUT - :wrongPriceOut) < 0.000001
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
            'wrongPriceOut' => self::WRONG_PRICE_OUT,
        ]);
    }

    public function down(Schema $schema): void
    {
        // Deliberately empty. Reinstating $0.15/sec would restore a 50% overcharge
        // on a rate Google never published, and re-break the fingerprint.
    }

    /**
     * Snapshot of BID 195 exactly as authored in ModelCatalog on 2026-09-07 —
     * values AND json key order, because the fingerprint hashes the encoded
     * payload.
     *
     * @return array<string, mixed>
     */
    private function veoFastRow(): array
    {
        return [
            'id' => 195,
            'service' => 'Google',
            'name' => 'Veo 3.1 Fast',
            'tag' => 'text2vid',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'veo-3.1-fast-generate-preview',
            'priceIn' => 0,
            'inUnit' => '-',
            'priceOut' => 0.10,
            'outUnit' => 'persec',
            'quality' => 8,
            'rating' => 1,
            'json' => [
                'description' => 'Google Veo 3.1 Fast - quicker generations with audio. 720p: $0.10/sec, 1080p: $0.12/sec, 4K: $0.30/sec.',
                'params' => ['model' => 'veo-3.1-fast-generate-preview'],
                'pricing_mode' => 'per_second',
                'allowed_resolutions' => ['720p', '1080p', '4K'],
                'default_resolution' => '1080p',
                'resolution_prices' => [
                    '720p' => 0.10,
                    '1080p' => 0.12,
                    '4K' => 0.30,
                ],
            ],
        ];
    }

    /**
     * Local copy of ModelCatalog::fingerprint() frozen at authoring time, so the
     * migration stays self-contained (migrations must not import app code that can
     * drift). Same contract as Version20260904120000.
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
