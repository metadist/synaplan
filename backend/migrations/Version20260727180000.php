<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retire two superseded catalog-orphans: OpenAI gpt-4.1 (BID 30) and Groq
 * llama-4-maverick-17b-128e-instruct (BID 49).
 *
 * Both left {@see \App\Model\ModelCatalog} in earlier releases without a companion
 * deactivation migration, so they stayed BACTIVE = 1, BSELECTABLE = 1 in existing
 * databases — offered in the model picker and billed, while no release could ever
 * update them (ModelSeeder only manages rows the catalog still contains).
 *
 * Deactivated, never deleted: BMESSAGES rows reference these BIDs via FK. Same
 * contract as {@see Version20260727120000}.
 *
 * DEFAULTMODEL bindings (global and per-user) are repointed to the current
 * catalog-managed model of the same tier, guarded by an EXISTS subquery:
 *
 *   - gpt-4.1              → GPT-5.6 Terra   (BID 253) — current mid-tier OpenAI chat
 *   - llama-4-maverick     → gpt-oss-120b    (BID 76)  — current fast/cheap Groq chat
 */
final class Version20260727180000 extends AbstractMigration
{
    private const SUCCESSOR_GPT_56_TERRA_BID = 253;
    private const SUCCESSOR_GROQ_GPT_OSS_120B_BID = 76;

    /**
     * Retired BID => [upstream API model id (guard), successor BID].
     *
     * The BPROVID guard makes the deactivation a no-op when the row was manually
     * repurposed, and makes a re-run idempotent.
     *
     * @var array<int, array{string, int}>
     */
    private const RETIRED_MODELS = [
        30 => ['gpt-4.1', self::SUCCESSOR_GPT_56_TERRA_BID],
        49 => ['meta-llama/llama-4-maverick-17b-128e-instruct', self::SUCCESSOR_GROQ_GPT_OSS_120B_BID],
    ];

    public function getDescription(): string
    {
        return 'Deactivate the superseded OpenAI gpt-4.1 (BID 30) and Groq llama-4-maverick (BID 49) rows and repoint DEFAULTMODEL bindings (any owner) to GPT-5.6 Terra / Groq gpt-oss-120b.';
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
    }

    public function down(Schema $schema): void
    {
        // Reactivate the retired rows so they reappear in the admin UI. We
        // intentionally do NOT undo the BCONFIG repoints from up(): we cannot know
        // which bindings were auto-migrated vs. deliberately set to the successor
        // afterwards. Same contract as Version20260727120000::down().
        foreach (self::RETIRED_MODELS as $retiredBid => [$providerId]) {
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
