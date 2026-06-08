<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Make the self-hosted Ollama bge-m3 (BMODELS BID 13) visible in the
 * user-facing model list at /config/ai-models by setting BSHOWWHENFREE = 1.
 *
 * Why this is needed:
 *   bge-m3 is the default VECTORIZE (file-embedding) model for self-hosted and
 *   local-dev installs (see DefaultModelConfigSeeder), but it has no per-token
 *   price (BPRICEIN = BPRICEOUT = 0). `Model::isHiddenBecauseFree()` hides any
 *   zero-price model from the selection list unless BSHOWWHENFREE = 1, so RAG
 *   was silently using a model the operator could never see or pick in the UI.
 *
 *   Version20260506000000 fixed BSELECTABLE for the same row but not
 *   BSHOWWHENFREE, which is the flag the user-facing list actually checks.
 *
 * Why a migration (not just the catalog):
 *   `ModelCatalog::upsert()` seeds BSHOWWHENFREE on INSERT only (operator-owned,
 *   never touched by ON DUPLICATE KEY UPDATE). The catalog change therefore only
 *   reaches FRESH installs. Existing installs (local dev, web1/web2/web3) keep
 *   the stored BSHOWWHENFREE = 0 and need this migration to flip it.
 *
 * Idempotent: re-running on a row that is already 1 is a 0-row UPDATE. Running
 * before the row exists (very fresh DB before app:seed) is also a 0-row UPDATE —
 * app:seed then INSERTs the row with BSHOWWHENFREE = 1 from the updated catalog.
 *
 * down() flips it back to 0 for symmetry.
 */
final class Version20260608120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Show self-hosted Ollama bge-m3 (BID 13) in the model list (BSHOWWHENFREE = 1)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BSHOWWHENFREE = 1
             WHERE BID = 13
               AND BSERVICE = 'Ollama'
               AND BTAG = 'vectorize'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BSHOWWHENFREE = 0
             WHERE BID = 13
               AND BSERVICE = 'Ollama'
               AND BTAG = 'vectorize'
        SQL);
    }
}
