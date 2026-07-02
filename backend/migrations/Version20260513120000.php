<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Align Imagen 4.0 (BID 115) catalog shape with live production.
 *
 * The previous catalog row authored Imagen 4.0 as a per-1M-token model
 * (priceIn=0.1, priceOut=0.4, per1M) and did not set pricing_mode in
 * BJSON, which made CostCalculationService::calculateMediaCost() silently
 * fall through to the per-token path with zero billed tokens. Live
 * production has been hand-curated to the correct shape:
 *   priceIn=0  / inUnit=perImage
 *   priceOut=0.04 / outUnit=perImage
 *   BJSON.pricing_mode = per_image
 *
 * This migration force-rolls those values out to existing developer /
 * staging databases so a fresh git pull && make migrate produces the
 * same row a make seed-models against the updated ModelCatalog.php
 * would. Idempotent: re-running is a no-op once the row already matches.
 *
 * Operator-owned columns (BSELECTABLE, BACTIVE, BISDEFAULT) are
 * deliberately NOT touched per the BMODELS catalog-vs-operator contract
 * documented in AGENTS.md.
 */
final class Version20260513120000 extends AbstractMigration
{
    private const IMAGEN_4_BID = 115;
    private const IMAGEN_4_PROVID = 'imagen-4.0-generate-001';

    public function getDescription(): string
    {
        return 'Align Imagen 4.0 (BID 115) catalog shape with live production: '
            .'set priceIn=0, priceOut=0.04 in perImage units, and '
            .'BJSON.pricing_mode = per_image with mode_prices.output_cost_per_image = 0.04.';
    }

    public function up(Schema $schema): void
    {
        // The BPROVID guard makes the UPDATE a no-op when an operator has
        // re-pointed BID 115 at a different provider id (e.g. a custom
        // Imagen successor or a local rename) so we do not clobber a
        // deliberate re-binding.
        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BPRICEIN  = 0,
                   BINUNIT   = 'perImage',
                   BPRICEOUT = 0.04,
                   BOUTUNIT  = 'perImage',
                   BJSON     = JSON_SET(
                                   COALESCE(BJSON, '{}'),
                                   '$.pricing_mode', 'per_image',
                                   '$.mode_prices.output_cost_per_image', 0.04
                               )
             WHERE BID = :bid
               AND BPROVID = :provid
            SQL, [
            'bid' => self::IMAGEN_4_BID,
            'provid' => self::IMAGEN_4_PROVID,
        ]);
    }

    public function down(Schema $schema): void
    {
        // Restore the pre-fix per-token-fallback shape. We cannot infer
        // the operator's previous custom values, so this rolls back to
        // the catalog default the codebase shipped before this migration:
        // priceIn=0.1, priceOut=0.4 in per1M, with no pricing_mode.
        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BPRICEIN  = 0.1,
                   BINUNIT   = 'per1M',
                   BPRICEOUT = 0.4,
                   BOUTUNIT  = 'per1M',
                   BJSON     = JSON_REMOVE(
                                   JSON_REMOVE(
                                       COALESCE(BJSON, '{}'),
                                       '$.mode_prices'
                                   ),
                                   '$.pricing_mode'
                               )
             WHERE BID = :bid
               AND BPROVID = :provid
            SQL, [
            'bid' => self::IMAGEN_4_BID,
            'provid' => self::IMAGEN_4_PROVID,
        ]);
    }
}
