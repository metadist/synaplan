<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add Cloudflare Workers AI bge-m3 embedding model to BMODELS.
 *
 * Provides a fast, low-cost cloud alternative for bge-m3 embeddings.
 * Use-cases: local dev (no Ollama needed) and production auto-fallback.
 */
final class Version20260422100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Cloudflare Workers AI bge-m3 embedding model';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $json = json_encode([
            'description' => 'BAAI/bge-m3 via Cloudflare Workers AI edge network. Fast, low-cost multilingual embeddings (1024-dim). 10k neurons/day free tier.',
            'params' => ['model' => '@cf/baai/bge-m3'],
            'features' => ['embedding', 'multilingual'],
            'meta' => ['dimensions' => 1024, 'context_window' => '60000', 'provider' => 'cloudflare'],
        ]);

        $this->addSql(<<<SQL
            INSERT INTO BMODELS (BID, BSERVICE, BNAME, BTAG, BSELECTABLE, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BISDEFAULT, BACTIVE, BDESCRIPTION, BJSON)
            VALUES (187, 'Cloudflare', 'bge-m3', 'vectorize', 1, '@cf/baai/bge-m3', 0.012, 'per1M', 0, '-', 8, 1, 0, 1, NULL, '{$json}')
            ON DUPLICATE KEY UPDATE BSERVICE = 'Cloudflare', BPROVID = '@cf/baai/bge-m3', BJSON = '{$json}'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM BMODELS WHERE BID = 187');
    }
}
