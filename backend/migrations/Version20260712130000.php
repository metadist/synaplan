<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Converge the remaining text2pic (image) models to per_image billing.
 *
 * Several image models were seeded with the default (missing) pricing_mode,
 * which CostCalculationService::getPricingMode() treats as `per_token`. Because
 * the image generation path never captures per-image token usage, a per_token
 * image model bills $0 in BUSELOG (the taximeter). This is the same class of
 * bug fixed for the Gemini/GPT image models in Version20260712120000; this
 * migration completes the audit for the rest of the image catalog.
 *
 * Two shapes are handled:
 *
 *  1. Models that already carry the correct per-image price in BPRICEOUT
 *     (authored as `perpic`, which CostCalculationService normalises to a
 *     per-1 price): only the JSON pricing_mode + mode_prices are added so
 *     calculateMediaCost() is reached. BPRICEIN/BPRICEOUT/units are left
 *     untouched so no price actually changes.
 *
 *  2. gpt-image-1.5, which was copied from a chat-style per-1M-token price
 *     ($5 in / $10 out per 1M) and therefore billed $0: repriced to
 *     per_image at $0.04/image (standard 1024x1024, per OpenAI pricing).
 *
 * Video (text2vid) models are already per_second and are intentionally NOT
 * touched. Operator-owned columns (BSELECTABLE, BACTIVE, BISDEFAULT) are never
 * touched, per the BMODELS catalog-vs-operator contract in AGENTS.md.
 *
 * Idempotent (JSON_SET / fixed UPDATEs re-run to the same result) and guarded
 * by BPROVID so an operator re-binding is left alone. Uses only raw addSql()
 * with no Schema API, so it is safe on the shared MariaDB Galera cluster.
 *
 * @see \App\Model\ModelCatalog
 * @see \App\Service\CostCalculationService::calculateMediaCost()
 * @see Version20260712120000
 */
final class Version20260712130000 extends AbstractMigration
{
    /**
     * Image models that already hold the right per-image price in BPRICEOUT;
     * they only need the pricing_mode flag flipped so billing is reached.
     *
     * providerId => per-image price (mirrored into mode_prices for parity with
     * the rest of the catalog; flat billing still uses BPRICEOUT).
     *
     * @var array<string, float>
     */
    private const FLAG_ONLY_MODELS = [
        'stabilityai/stable-diffusion-xl-base-1.0' => 0.02,
        'flux-schnell' => 0.01,
        'flux-schnell-enhanced' => 0.02,
        'sdxl' => 0.02,
        'sdxl-enhanced' => 0.05,
        'emoji' => 0.01,
        'higgsfield-ai/soul/standard' => 0.05,
        'reve/text-to-image' => 0.05,
    ];

    private const GPT_IMAGE_15_PROVID = 'gpt-image-1.5';
    private const GPT_IMAGE_15_PRICE = 0.04;

    public function getDescription(): string
    {
        return 'Bill the remaining text2pic image models per_image so the taximeter records a '
            .'real cost instead of $0 (flag-only for perpic models; reprice gpt-image-1.5 off its '
            .'copied per-token chat pricing).';
    }

    public function up(Schema $schema): void
    {
        // 1. Flag-only: keep the existing (correct) per-image price, just make
        //    the model reach the media cost path instead of the per_token one.
        foreach (self::FLAG_ONLY_MODELS as $provid => $price) {
            $this->addSql(<<<'SQL'
                UPDATE BMODELS
                   SET BJSON = JSON_SET(
                                   COALESCE(BJSON, '{}'),
                                   '$.pricing_mode', 'per_image',
                                   '$.mode_prices', JSON_OBJECT('output_cost_per_image', :price)
                               )
                 WHERE BPROVID = :provid
                SQL, [
                'price' => $price,
                'provid' => $provid,
            ]);
        }

        // 2. gpt-image-1.5 was mispriced with chat-style per-1M-token units.
        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BPRICEIN  = 0,
                   BINUNIT   = 'perImage',
                   BPRICEOUT = :price,
                   BOUTUNIT  = 'perImage',
                   BJSON     = JSON_SET(
                                   COALESCE(BJSON, '{}'),
                                   '$.pricing_mode', 'per_image',
                                   '$.mode_prices', JSON_OBJECT('output_cost_per_image', :price)
                               )
             WHERE BPROVID = :provid
            SQL, [
            'price' => self::GPT_IMAGE_15_PRICE,
            'provid' => self::GPT_IMAGE_15_PROVID,
        ]);
    }

    public function down(Schema $schema): void
    {
        // Reverse the flag flip (drops pricing_mode + mode_prices, restoring the
        // pre-migration per_token fallthrough).
        foreach (array_keys(self::FLAG_ONLY_MODELS) as $provid) {
            $this->addSql(<<<'SQL'
                UPDATE BMODELS
                   SET BJSON = JSON_REMOVE(
                                   JSON_REMOVE(COALESCE(BJSON, '{}'), '$.mode_prices'),
                                   '$.pricing_mode'
                               )
                 WHERE BPROVID = :provid
                SQL, [
                'provid' => $provid,
            ]);
        }

        // Restore gpt-image-1.5 to its previous per-1M-token catalog shape.
        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BPRICEIN  = 5,
                   BINUNIT   = 'per1M',
                   BPRICEOUT = 10,
                   BOUTUNIT  = 'per1M',
                   BJSON     = JSON_REMOVE(
                                   JSON_REMOVE(COALESCE(BJSON, '{}'), '$.mode_prices'),
                                   '$.pricing_mode'
                               )
             WHERE BPROVID = :provid
            SQL, [
            'provid' => self::GPT_IMAGE_15_PROVID,
        ]);
    }
}
