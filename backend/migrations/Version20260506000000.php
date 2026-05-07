<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Make the self-hosted Ollama bge-m3 (BMODELS BID 13) selectable in the
 * admin "switch embedding model" dropdown.
 *
 * Why this is a migration, not just a catalog change:
 *   `ModelCatalog::upsert()` deliberately treats `BSELECTABLE` as
 *   operator-owned — the column is set on INSERT only and never touched by
 *   the ON DUPLICATE KEY UPDATE clause. That is the right policy for free /
 *   paid model toggling via the admin UI, but it also means flipping
 *   `selectable => 1` in `App\Model\ModelCatalog` only takes effect on
 *   FRESH installs. Existing installs (local dev, web1/web2/web3) keep the
 *   stored `BSELECTABLE = 0` from when the row was first seeded under the
 *   old "RAG only via Cloudflare/OpenAI" defaults.
 *
 *   This migration brings every install — fresh or existing — to the same
 *   state: Ollama bge-m3 listed alongside the Cloudflare and OpenAI
 *   embedding options in `/api/v1/admin/embedding/synapse/status` and the
 *   matching switch endpoint, so operators with their own GPU server can
 *   pin RAG and Synapse routing to it instead of paying per-token to a
 *   cloud provider.
 *
 * Idempotent: running on an install where BID=13 already has BSELECTABLE=1
 * is a 0-row UPDATE and a no-op. Running on an install where the row does
 * not exist yet (very fresh DB before `app:seed` ever ran) is also a 0-row
 * UPDATE — `app:seed` will then seed the row with BSELECTABLE=1 from the
 * updated catalog.
 *
 * Down() flips it back to 0 for symmetry, so a `migrations:migrate prev`
 * restores the pre-migration listing.
 */
final class Version20260506000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make Ollama bge-m3 (BID 13) selectable for embedding-model switch';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BSELECTABLE = 1
             WHERE BID = 13
               AND BSERVICE = 'Ollama'
               AND BTAG = 'vectorize'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BSELECTABLE = 0
             WHERE BID = 13
               AND BSERVICE = 'Ollama'
               AND BTAG = 'vectorize'
        SQL);
    }
}
