<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add Qwen3-Embedding-0.6B via Cloudflare Workers AI to BMODELS.
 *
 * Instruction-aware multilingual embedding model (1024-dim).
 * Provides superior cross-language retrieval for topic routing/sorting.
 */
final class Version20260423000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Cloudflare Qwen3-Embedding-0.6B model';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $json = json_encode([
            'description' => 'Qwen3 Embedding 0.6B via Cloudflare Workers AI. Instruction-aware multilingual embeddings (1024-dim). Superior cross-language retrieval for topic routing.',
            'params' => ['model' => '@cf/qwen/qwen3-embedding-0.6b'],
            'features' => ['embedding', 'multilingual', 'instruction-aware'],
            'meta' => ['dimensions' => 1024, 'context_window' => '8192', 'provider' => 'cloudflare'],
        ]);

        $this->addSql(<<<SQL
            INSERT INTO BMODELS (BID, BSERVICE, BNAME, BTAG, BSELECTABLE, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BISDEFAULT, BACTIVE, BDESCRIPTION, BJSON)
            VALUES (188, 'Cloudflare', 'Qwen3-Embedding-0.6B', 'vectorize', 1, '@cf/qwen/qwen3-embedding-0.6b', 0.012, 'per1M', 0, '-', 9, 1, 0, 1, NULL, '{$json}')
            ON DUPLICATE KEY UPDATE BSERVICE = 'Cloudflare', BNAME = 'Qwen3-Embedding-0.6B', BPROVID = '@cf/qwen/qwen3-embedding-0.6b', BJSON = '{$json}'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM BMODELS WHERE BID = 188');
    }
}
