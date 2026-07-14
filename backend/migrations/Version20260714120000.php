<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #1317: Higgsfield video models are billed PER CLIP (flat credit cost), not
 * per second. Roll the corrected flat per-generation pricing out to existing
 * installs — the catalog seeder covers fresh installs, but BMODELS pricing on
 * an already-provisioned DB only changes when a seed re-applies, so ship the
 * update explicitly here (AGENTS.md: roll out changed pricing via a migration).
 *
 * Idempotent + non-destructive: each row is only touched while it still carries
 * the old `per_second` output unit, so re-runs and operator-adjusted rows are
 * left alone. Raw addSql only (no Schema API) for Galera-cluster safety.
 */
final class Version20260714120000 extends AbstractMigration
{
    /**
     * providerId => flat per-clip USD estimate (previous per-second rate × the
     * default 5s clip, preserving billing continuity).
     */
    private const HIGGSFIELD_FLAT_PRICES = [
        'higgsfield-ai/dop/standard' => 2.50,
        'kling-video/v2.1/pro/image-to-video' => 3.00,
        'kling-video/v2.1/master/image-to-video' => 4.50,
        'higgsfield-ai/dop/lite' => 1.25,
        'higgsfield-ai/dop/turbo' => 1.75,
    ];

    public function getDescription(): string
    {
        return 'Convert Higgsfield video models to flat per-generation pricing (#1317)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        foreach (self::HIGGSFIELD_FLAT_PRICES as $providerId => $price) {
            $this->addSql(
                "UPDATE BMODELS
                    SET BPRICEOUT = {$price},
                        BOUTUNIT = 'per_generation',
                        BJSON = JSON_SET(BJSON, '$.pricing_mode', 'per_generation')
                    WHERE BPROVID = '{$providerId}'
                      AND BSERVICE = 'Higgsfield'
                      AND BOUTUNIT = 'per_second'"
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Restore the previous per-second rates (flat estimate ÷ default 5s clip).
        $perSecond = [
            'higgsfield-ai/dop/standard' => 0.50,
            'kling-video/v2.1/pro/image-to-video' => 0.60,
            'kling-video/v2.1/master/image-to-video' => 0.90,
            'higgsfield-ai/dop/lite' => 0.25,
            'higgsfield-ai/dop/turbo' => 0.35,
        ];
        foreach ($perSecond as $providerId => $price) {
            $this->addSql(
                "UPDATE BMODELS
                    SET BPRICEOUT = {$price},
                        BOUTUNIT = 'per_second',
                        BJSON = JSON_SET(BJSON, '$.pricing_mode', 'per_second')
                    WHERE BPROVID = '{$providerId}'
                      AND BSERVICE = 'Higgsfield'
                      AND BOUTUNIT = 'per_generation'"
            );
        }
    }
}
