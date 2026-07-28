<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retire the remaining catalog-orphans found on the production catalog on
 * 2026-07-28: three OpenAI chat rows and four HuggingFace rows that left
 * {@see \App\Model\ModelCatalog} without a companion deactivation migration.
 *
 * Same contract as {@see Version20260727120000} and {@see Version20260727180000}:
 * rows are deactivated, never deleted (BMESSAGES references the BIDs), the
 * BPROVID guard keeps a re-run idempotent, and DEFAULTMODEL bindings (global and
 * per-user) are repointed to the current catalog-managed equivalent behind an
 * EXISTS subquery.
 *
 *   - gpt-5, gpt-5.2                → GPT-5.6 Terra   (BID 253) — current mid-tier OpenAI chat
 *   - gpt-5-mini                    → GPT-5.4 mini    (BID 232) — current small OpenAI chat
 *   - DeepSeek-R1                   → Kimi K2.6       (BID 202) — current HF reasoning chat
 *   - Qwen2.5-Coder-32B-Instruct    → Kimi K2.7 Code  (BID 242) — current HF coding chat
 *   - HF stable-diffusion-xl        → TheHive SDXL    (BID 132) — same model, catalog-managed host
 *
 * The embedding orphan (HuggingFace multilingual-e5-large, BID 129) is handled
 * separately below: an embedding model may not be swapped by a repoint alone.
 */
final class Version20260728120000 extends AbstractMigration
{
    private const SUCCESSOR_GPT_56_TERRA_BID = 253;
    private const SUCCESSOR_GPT_54_MINI_BID = 232;
    private const SUCCESSOR_KIMI_K26_BID = 202;
    private const SUCCESSOR_KIMI_K27_CODE_BID = 242;
    private const SUCCESSOR_THEHIVE_SDXL_BID = 132;

    /** HuggingFace multilingual-e5-large — deactivated only when nothing binds to it. */
    private const RETIRED_EMBEDDING_BID = 129;
    private const RETIRED_EMBEDDING_PROVID = 'intfloat/multilingual-e5-large';

    /**
     * Retired BID => [upstream API model id (guard), successor BID].
     *
     * @var array<int, array{string, int}>
     */
    private const RETIRED_MODELS = [
        70 => ['gpt-5', self::SUCCESSOR_GPT_56_TERRA_BID],
        106 => ['gpt-5.2-2025-12-11', self::SUCCESSOR_GPT_56_TERRA_BID],
        150 => ['gpt-5-mini', self::SUCCESSOR_GPT_54_MINI_BID],
        125 => ['deepseek-ai/DeepSeek-R1', self::SUCCESSOR_KIMI_K26_BID],
        128 => ['Qwen/Qwen2.5-Coder-32B-Instruct', self::SUCCESSOR_KIMI_K27_CODE_BID],
        126 => ['stabilityai/stable-diffusion-xl-base-1.0', self::SUCCESSOR_THEHIVE_SDXL_BID],
    ];

    public function getDescription(): string
    {
        return 'Deactivate the remaining OpenAI (70/106/150) and HuggingFace (125/126/128) catalog-orphans, repoint their DEFAULTMODEL bindings, and retire the unbound HuggingFace embedding row (129).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::RETIRED_MODELS as $retiredBid => [$providerId, $successorBid]) {
            $this->addSql(<<<'SQL'
                UPDATE BCONFIG
                   SET BVALUE = :successor
                 WHERE BGROUP = 'DEFAULTMODEL'
                   AND BVALUE = :retired
                   AND EXISTS (SELECT 1 FROM BMODELS WHERE BID = :successor)
            SQL, [
                'retired' => (string) $retiredBid,
                'successor' => (string) $successorBid,
            ]);

            $this->addSql(<<<'SQL'
                UPDATE BMODELS
                   SET BACTIVE = 0,
                       BSELECTABLE = 0,
                       BISDEFAULT = 0
                 WHERE BID = :retired
                   AND BPROVID = :providerId
            SQL, [
                'retired' => $retiredBid,
                'providerId' => $providerId,
            ]);
        }

        // Embeddings are not interchangeable: switching DEFAULTMODEL.VECTORIZE
        // invalidates every stored vector, which is why the admin path pairs the
        // switch with a re-vectorize run (see VectorizeBindingService and issue
        // #948). A migration cannot re-vectorize, so the orphan is only retired
        // where no binding points at it; an install that still uses it keeps a
        // working search and can switch through the admin UI.
        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BACTIVE = 0,
                   BSELECTABLE = 0,
                   BISDEFAULT = 0
             WHERE BID = :retired
               AND BPROVID = :providerId
               AND NOT EXISTS (
                   SELECT 1 FROM BCONFIG
                    WHERE BGROUP = 'DEFAULTMODEL'
                      AND BVALUE = :retiredValue
               )
        SQL, [
            'retired' => self::RETIRED_EMBEDDING_BID,
            'retiredValue' => (string) self::RETIRED_EMBEDDING_BID,
            'providerId' => self::RETIRED_EMBEDDING_PROVID,
        ]);
    }

    public function down(Schema $schema): void
    {
        // Reactivate the retired rows so they reappear in the admin UI. The
        // BCONFIG repoints from up() are intentionally not undone: we cannot tell
        // an auto-migrated binding from one an operator set deliberately
        // afterwards. Same contract as Version20260727180000::down().
        $retired = self::RETIRED_MODELS;
        $retired[self::RETIRED_EMBEDDING_BID] = [self::RETIRED_EMBEDDING_PROVID, 0];

        foreach ($retired as $retiredBid => [$providerId]) {
            $this->addSql(<<<'SQL'
                UPDATE BMODELS
                   SET BACTIVE = 1,
                       BSELECTABLE = 1
                 WHERE BID = :retired
                   AND BPROVID = :providerId
            SQL, [
                'retired' => $retiredBid,
                'providerId' => $providerId,
            ]);
        }
    }
}
