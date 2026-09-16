<?php

declare(strict_types=1);

namespace App\Tests\Unit\Eval;

use App\Plug\Rerank\RagRerankEvalCorpus;
use PHPUnit\Framework\TestCase;

final class RagRerankEvalCorpusTest extends TestCase
{
    public function testCorpusIdsAreUniqueAndFilesExistWithoutSecrets(): void
    {
        $path = dirname(__DIR__, 2).'/Eval/rag_rerank_eval_corpus.json';
        $corpus = RagRerankEvalCorpus::load($path);
        $ids = [];
        $filesDir = dirname(__DIR__, 2).'/Fixtures/extraction/files';

        $this->assertGreaterThanOrEqual(20, count($corpus['questions']));
        foreach ($corpus['questions'] as $row) {
            $this->assertArrayNotHasKey($row['id'], $ids, 'duplicate id '.$row['id']);
            $ids[$row['id']] = true;
            $this->assertFileExists($filesDir.'/'.$row['expected']['file']);
            $haystack = $row['question'].' '.$row['expected']['mustContain'];
            $this->assertDoesNotMatchRegularExpression(
                '/https?:\/\/|sk-|api[_-]?key|VectorSearch|MessageProcessor|localhost/i',
                $haystack,
            );
        }
    }
}
