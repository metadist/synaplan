<?php

declare(strict_types=1);

namespace App\Plug\Rerank;

/**
 * Loader for {@see backend/tests/Eval/rag_rerank_eval_corpus.json}.
 *
 * @phpstan-type Question array{id: string, question: string, expected: array{file: string, mustContain: string}}
 */
final class RagRerankEvalCorpus
{
    /**
     * @return array{corpusId: string, questions: list<Question>}
     */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException('Eval corpus not found: '.$path);
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Eval corpus is not valid JSON: '.$e->getMessage(), 0, $e);
        }
        if (!\is_array($decoded) || !\is_string($decoded['corpusId'] ?? null) || !\is_array($decoded['questions'] ?? null)) {
            throw new \InvalidArgumentException('Eval corpus must have corpusId and questions');
        }

        $questions = [];
        foreach ($decoded['questions'] as $row) {
            if (!\is_array($row)
                || !\is_string($row['id'] ?? null)
                || !\is_string($row['question'] ?? null)
                || !\is_array($row['expected'] ?? null)
                || !\is_string($row['expected']['file'] ?? null)
                || !\is_string($row['expected']['mustContain'] ?? null)
            ) {
                throw new \InvalidArgumentException('Eval corpus row is missing id, question or expected');
            }
            $questions[] = [
                'id' => $row['id'],
                'question' => $row['question'],
                'expected' => [
                    'file' => $row['expected']['file'],
                    'mustContain' => $row['expected']['mustContain'],
                ],
            ];
        }

        return [
            'corpusId' => $decoded['corpusId'],
            'questions' => $questions,
        ];
    }
}
