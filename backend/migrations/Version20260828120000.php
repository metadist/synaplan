<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Point every DEFAULTMODEL.SORT binding at Groq gpt-oss-120b (BID 76).
 *
 * The sorter is the one call in the pipeline where the model decides whether a
 * message is routed at all: it has to answer with strict JSON, on every turn,
 * fast. The routing prompts in {@see \App\Prompt\PromptCatalog} are written
 * and regression-tested against gpt-oss-120b; a heavier chat model reasons its
 * way past the format and degrades routing for the whole install.
 *
 * DefaultModelConfigSeeder already binds SORT to `groq:openai/gpt-oss-120b:chat`,
 * but BCONFIG defaults are bootstrap-only — the seeder inserts a missing row and
 * never rewrites an existing one, so every install seeded before that value
 * still sorts with whatever it had. A migration is the documented way to roll a
 * changed default out to them.
 *
 * Per-user rows (BOWNERID > 0) are repointed too, not just the global one: a
 * user binding wins over the global default, so a global-only repoint would
 * leave exactly the accounts that once picked a different sorter on it. This is
 * a one-time correction, not a lock — the AI-models page can set another sort
 * model again afterwards.
 *
 * Guarded on the target row being present, unmodified and active. An operator
 * who deactivated the Groq row (no Groq account, self-hosted only) keeps their
 * binding and model resolution falls back exactly as before, and an install
 * that repurposed BID 76 for a different model is not touched at all.
 */
final class Version20260828120000 extends AbstractMigration
{
    private const SORTER_BID = 76;
    private const SORTER_PROVID = 'openai/gpt-oss-120b';

    public function getDescription(): string
    {
        return 'Repoint every DEFAULTMODEL.SORT binding (global and per-user) at Groq gpt-oss-120b, '
            .'the model the routing prompts are tuned for.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE BCONFIG
               SET BVALUE = :sorter
             WHERE BGROUP = 'DEFAULTMODEL'
               AND BSETTING = 'SORT'
               AND BVALUE <> :sorter
               AND EXISTS (
                   SELECT 1
                     FROM BMODELS
                    WHERE BID = :sorterId
                      AND BPROVID = :providerId
                      AND BTAG = 'chat'
                      AND BACTIVE = 1
               )
        SQL, [
            'sorter' => (string) self::SORTER_BID,
            'sorterId' => self::SORTER_BID,
            'providerId' => self::SORTER_PROVID,
        ], [
            'sorterId' => ParameterType::INTEGER,
        ]);
    }

    public function down(Schema $schema): void
    {
        // Not revertible: the per-row values this overwrites are not recorded
        // anywhere, and repointing everything back at one model would be a
        // second forced change rather than an undo. Same contract as the other
        // binding migrations (Version20260819080000).
    }
}
