<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * No-op placeholder kept for migration history.
 *
 * Earlier revisions of this migration INSERTed the Qwen3 catalog row into
 * BMODELS with a hard-coded BID (188). See `Version20260422100000` for the
 * full rationale — TL;DR: catalog data is owned by `App\Model\ModelCatalog`
 * and seeded by `ModelSeeder` (`app:seed`, runs on every container boot),
 * and hard-coded BIDs can collide on operator-customised installs.
 *
 * The Qwen3-Embedding-0.6B entry now lives in `ModelCatalog::MODELS`. We keep
 * the migration shell so already-migrated installs stay registered in
 * `doctrine_migration_versions` instead of warning about an unknown version.
 */
final class Version20260423120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'No-op — Cloudflare Qwen3-Embedding-0.6B catalog row moved to ModelCatalog (seed-driven)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
    }

    public function down(Schema $schema): void
    {
    }
}
