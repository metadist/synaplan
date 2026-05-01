<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * No-op placeholder kept for migration history.
 *
 * Earlier revisions of this migration INSERTed a Cloudflare bge-m3 row into
 * BMODELS with a hard-coded BID (187). That conflicts with two newer rules:
 *
 *   1. Catalog data (BMODELS) is owned by `App\Model\ModelCatalog` and seeded
 *      idempotently via `app:seed`, which runs on every container boot and in
 *      CI right after `doctrine:migrations:migrate`. Maintaining the same data
 *      twice (catalog + migration) is an anti-pattern explicitly called out in
 *      AGENTS_DEV.md ("Catalog data: backend/src/Model/ModelCatalog.php").
 *   2. Hard-coded BIDs in INSERTs can collide on installations where BID 187
 *      is already taken by a different operator-imported model.
 *
 * The Cloudflare bge-m3 entry now lives in `ModelCatalog::MODELS` and is
 * INSERTed/UPDATEd through `ModelSeeder` (which keys by `id` and additionally
 * preserves operator overrides via fingerprinting). We keep this migration as
 * a no-op so installations that already executed it stay registered in
 * `doctrine_migration_versions`; deleting it would force them into a
 * "migration is not registered" warning loop.
 */
final class Version20260422100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'No-op — Cloudflare bge-m3 catalog row moved to ModelCatalog (seed-driven)';
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
