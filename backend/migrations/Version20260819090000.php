<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retire Groq `llama-3.3-70b-versatile` (BID 9), decommissioned upstream: the
 * provider now answers every request for it with "The model ... does not exist
 * or you do not have access to it".
 *
 * The row is removed from {@see \App\Model\ModelCatalog} in the same change, so
 * this migration is what stops it from reaching users:
 *
 *   - `ModelSeeder` never deactivates rows, so without the UPDATE below the
 *     model stays BACTIVE=1/BSELECTABLE=1 — and therefore user-pickable — in
 *     every install that already has it.
 *   - Deleting the row instead would not be durable: `ModelCatalog::upsert()`
 *     writes an explicit BID, so as long as the catalog entry existed the next
 *     `app:seed` (run on every container start) restored BID 9 verbatim.
 *
 * The BID was also the recommended CHAT/TOOLS/ANALYZE binding for Groq in
 * `ProviderDefaultsService`, which `app:provider:apply-defaults --auto` applies
 * unattended at container start. Installs that were auto-pointed at Groq
 * therefore hold a dead default and are repaired by the repoint below.
 *
 * Same contract as {@see Version20260728120000}: rows are deactivated rather
 * than deleted (BUSELOG and BMODEL_PRICE_HISTORY reference BID with ON DELETE
 * RESTRICT, and BIDs must never be reused), the BPROVID guard keeps a re-run
 * idempotent, and DEFAULTMODEL bindings are repointed for every owner behind an
 * EXISTS subquery so an install without the successor is left untouched.
 *
 * Successor: Groq gpt-oss-120b (BID 76). Note what this costs, because the
 * retired row was deliberately the MAIN tier while 76 was the cheap FAST tier
 * (see #1392 and _devextras/planning/archive/20260606_multitask-routing.md):
 * Groq's remaining catalog no longer holds a chat model above its router, so
 * MAIN and FAST collapse onto one model, and the per-answer output budget drops
 * from 32768 to 16384 tokens because that is 76's catalog max_tokens. No Groq
 * chat row combines the higher budget with a comparable tier.
 *
 * Unlike the earlier retirements this one refuses to touch VECTORIZE: swapping
 * an embedding binding without re-vectorising corrupts every stored vector
 * (issue #948), and docs/PRICING_MAINTENANCE.md forbids it. The retired row is
 * chat-tagged, so a VECTORIZE binding on it can only come from operator error —
 * which is exactly when a silent repoint would do the most damage.
 */
final class Version20260819090000 extends AbstractMigration
{
    private const RETIRED_BID = 9;
    private const RETIRED_PROVID = 'llama-3.3-70b-versatile';
    private const SUCCESSOR_BID = 76;

    public function getDescription(): string
    {
        return 'Retire Groq llama-3.3-70b-versatile (BID 9), decommissioned upstream, and repoint its DEFAULTMODEL bindings to Groq gpt-oss-120b (BID 76).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE BCONFIG
               SET BVALUE = :successor
             WHERE BGROUP = 'DEFAULTMODEL'
               AND BSETTING <> 'VECTORIZE'
               AND BVALUE = :retiredValue
               AND EXISTS (
                   SELECT 1 FROM BMODELS WHERE BID = :successor AND BACTIVE = 1
               )
        SQL, [
            'retiredValue' => (string) self::RETIRED_BID,
            'successor' => (string) self::SUCCESSOR_BID,
        ]);

        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BACTIVE = 0,
                   BSELECTABLE = 0,
                   BISDEFAULT = 0
             WHERE BID = :retired
               AND BPROVID = :providerId
        SQL, [
            'retired' => self::RETIRED_BID,
            'providerId' => self::RETIRED_PROVID,
        ]);
    }

    public function down(Schema $schema): void
    {
        // Reactivate the row so it reappears in the admin UI. The BCONFIG
        // repoint is intentionally not undone: an auto-migrated binding cannot
        // be told apart from one an operator set deliberately afterwards. Same
        // contract as Version20260728120000::down().
        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BACTIVE = 1,
                   BSELECTABLE = 1
             WHERE BID = :retired
               AND BPROVID = :providerId
        SQL, [
            'retired' => self::RETIRED_BID,
            'providerId' => self::RETIRED_PROVID,
        ]);
    }
}
