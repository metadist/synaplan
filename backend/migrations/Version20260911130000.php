<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Repoint leftover test-stub DEFAULTMODEL.VECTORIZE bindings at Ollama bge-m3.
 *
 * DefaultModelConfigSeeder already binds VECTORIZE to `ollama:bge-m3:vectorize`
 * (BID 13), but BCONFIG defaults are bootstrap-only. A local/dev database that
 * was first seeded with TEST_DEFAULTS keeps BVALUE='-2' (test-vectorize) forever.
 * Query embeddings then miss every document indexed with a real model.
 *
 * Only `-2` is rewritten. An operator who chose OpenAI, Cloudflare, or another
 * live VECTORIZE model is left alone. Guarded on BID 13 still being Ollama
 * bge-m3 and active.
 *
 * Version number is after main's Version20260911120000 (Gemini catalog insert)
 * so both migrations keep their own class name and both run on deploy.
 */
final class Version20260911130000 extends AbstractMigration
{
    private const BGE_M3_BID = 13;
    private const BGE_M3_PROVID = 'bge-m3';
    private const TEST_STUB = '-2';

    public function getDescription(): string
    {
        return 'Repoint leftover test-stub DEFAULTMODEL.VECTORIZE (-2) at Ollama bge-m3.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE BCONFIG
               SET BVALUE = :bge
             WHERE BGROUP = 'DEFAULTMODEL'
               AND BSETTING = 'VECTORIZE'
               AND BVALUE = :stub
               AND EXISTS (
                   SELECT 1
                     FROM BMODELS
                    WHERE BID = :bgeId
                      AND BPROVID = :providerId
                      AND BTAG = 'vectorize'
                      AND BACTIVE = 1
               )
        SQL, [
            'bge' => (string) self::BGE_M3_BID,
            'stub' => self::TEST_STUB,
            'bgeId' => self::BGE_M3_BID,
            'providerId' => self::BGE_M3_PROVID,
        ], [
            'bgeId' => ParameterType::INTEGER,
        ]);
    }

    public function down(Schema $schema): void
    {
        // Not revertible: writing `-2` back would break RAG on every install
        // this migration healed.
    }
}
