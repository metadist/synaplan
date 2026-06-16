<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Seed\DefaultModelConfigSeeder;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sync global DEFAULTMODEL rows (ownerId=0) with the current code-recommended
 * defaults from DefaultModelConfigSeeder.
 *
 * The seeder uses INSERT IGNORE, so it never updates existing rows. When the
 * recommended defaults change in code, production databases keep the stale
 * values — new users who have no per-user overrides inherit those stale
 * defaults instead of the current recommendations.
 *
 * This migration is a one-time catch-up: it overwrites every global
 * DEFAULTMODEL row with the value from getRecommendedDefaults(). Rows that
 * already match are no-ops. Per-user overrides (ownerId > 0) are untouched.
 */
final class Version20260616000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sync global DEFAULTMODEL defaults with current code recommendations';
    }

    public function up(Schema $schema): void
    {
        $recommended = DefaultModelConfigSeeder::getRecommendedDefaults();

        foreach ($recommended as $capability => $modelId) {
            $this->addSql(
                'UPDATE BCONFIG SET BVALUE = ? WHERE BOWNERID = 0 AND BGROUP = ? AND BSETTING = ?',
                [(string) $modelId, 'DEFAULTMODEL', $capability],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('-- No automatic rollback: previous values are unknown');
    }
}
