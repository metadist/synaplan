<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Converge Gemini / GPT image models to per_image billing so the taximeter
 * (BUSELOG) records a real cost for image generation instead of $0.
 *
 * The image generation path never captures per-image token usage from the
 * provider (GoogleProvider::generateImageWithGemini() and the OpenAI image
 * path both discard usage), and MediaGenerationHandler only ever passes
 * media_usage['images'] into RateLimitService::recordUsage(). For a
 * pricing_mode = per_token image model that means:
 *   - zero token counts,
 *   - no byte-estimation fallback (no text/bytes are passed),
 *   - CostCalculationService::calculateMediaCost() short-circuits to $0.
 * => every image is billed at $0 and shows 0 tokens in the taximeter.
 *
 * The taximeter PR reclassified gemini-3.1-flash-image-preview to per_image in
 * ModelCatalog.php but shipped NO migration, so existing installs (e.g. the
 * Galera production cluster) kept their legacy per_token BMODELS rows. The
 * ModelSeeder deliberately PRESERVES fingerprint-less legacy rows whose values
 * differ from the catalog, so re-seeding does not fix them either. This
 * migration force-rolls the correct per_image shape onto the affected rows.
 *
 * Prices (paid tier, 1024px / medium 1024x1024):
 *   gemini-3.1-flash-image-preview  $0.067/image  ($60/1M image tokens, 1120 tok)
 *   gemini-2.5-flash-image          $0.039/image  ($30/1M image tokens, 1290 tok)
 *   gpt-image-1                     $0.042/image  (medium 1024x1024)
 *   gpt-image-2                     $0.040/image  (medium 1024x1024)
 *
 * Idempotent: re-running is a no-op once a row already matches. Each UPDATE is
 * guarded by BPROVID so a row an operator re-pointed at a different provider is
 * left untouched. Operator-owned columns (BSELECTABLE, BACTIVE, BISDEFAULT) are
 * never touched, per the BMODELS catalog-vs-operator contract in AGENTS.md.
 *
 * Uses only raw, idempotent addSql() (no Schema API) so it is safe to run on
 * the shared MariaDB Galera cluster.
 *
 * @see \App\Model\ModelCatalog
 * @see \App\Service\CostCalculationService::calculateMediaCost()
 */
final class Version20260712120000 extends AbstractMigration
{
    /**
     * providerId => per-image output price.
     *
     * @var array<string, float>
     */
    private const IMAGE_MODEL_PRICES = [
        'gemini-3.1-flash-image-preview' => 0.067,
        'gemini-2.5-flash-image' => 0.039,
        'gpt-image-1' => 0.042,
        'gpt-image-2' => 0.040,
    ];

    public function getDescription(): string
    {
        return 'Bill Gemini/GPT image models per_image so the taximeter records a real '
            .'cost for image generation instead of $0 (converges legacy per_token BMODELS rows).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::IMAGE_MODEL_PRICES as $provid => $price) {
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
                'price' => $price,
                'provid' => $provid,
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        // Only the two catalog-managed OpenAI/Google rows had a well-defined
        // pre-change per-token shape; restore those. gemini-3.1-flash-image-preview
        // is per_image in the shipped catalog (this migration only converged the
        // DB to it) and gpt-image-2 is an operator-authored row, so neither is
        // reverted here - flipping them back to per_token would re-introduce the
        // $0 image bug and diverge from the catalog.
        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BPRICEIN  = 5,
                   BINUNIT   = 'per1M',
                   BPRICEOUT = 40,
                   BOUTUNIT  = 'per1M',
                   BJSON     = JSON_REMOVE(
                                   JSON_REMOVE(COALESCE(BJSON, '{}'), '$.mode_prices'),
                                   '$.pricing_mode'
                               )
             WHERE BPROVID = 'gpt-image-1'
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BPRICEIN  = 0.1,
                   BINUNIT   = 'per1M',
                   BPRICEOUT = 0.4,
                   BOUTUNIT  = 'per1M',
                   BJSON     = JSON_REMOVE(
                                   JSON_REMOVE(COALESCE(BJSON, '{}'), '$.mode_prices'),
                                   '$.pricing_mode'
                               )
             WHERE BPROVID = 'gemini-2.5-flash-image'
            SQL);
    }
}
