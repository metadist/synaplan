<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Citation precision (06 §3): the share of cited statements whose cited
 * passages support them. Pairs from every arm are shuffled together and the
 * judges never see the arm. Two judges vote; a split goes to the third judge
 * when one is given, and stays "uncertain" without a majority.
 *
 * Until people have labelled the sample and the judges reach Cohen's kappa
 * 0.7 (06 §4), the numbers are a signal and gate nothing.
 *
 * @phpstan-type CitationPair array{pair: string, arm: string, item: string, run: int, statement: string, refs: list<string>, status: string, passage: string, votes: array<string, string>, verdict: string}
 */
final readonly class CitationRunner
{
    public function __construct(
        private AnswerFile $answers,
        private CitationExtractor $extractor,
        private CitationPassages $passages,
        private JudgeClient $judges,
        private CitationReport $report,
    ) {
    }

    /**
     * @param list<string>                  $answerPaths
     * @param list<string>                  $judgeKeys
     * @param (\Closure(string): void)|null $progress
     */
    public function run(array $answerPaths, array $judgeKeys, int $userId, int $seed, int $sampleSize, ?int $limit, string $outDir, ?\Closure $progress = null): string
    {
        if (\count($judgeKeys) < 2 || \count($judgeKeys) > 3) {
            throw new \RuntimeException('Pass two judges, or three with a tie-breaker. Nothing was sent.');
        }
        $randomizer = new Randomizer(new Mt19937($seed));
        $pairs = $randomizer->shuffleArray($this->pairs($answerPaths));
        if (null !== $limit) {
            $pairs = \array_slice($pairs, 0, $limit);
        }
        $tokens = array_fill_keys($judgeKeys, 0);
        foreach ($pairs as $index => $pair) {
            if ('ok' === $pair['status']) {
                $pairs[$index] = $this->judge($pair, $judgeKeys, $userId, $tokens);
            }
            if (null !== $progress && 0 === ($index + 1) % 20) {
                $progress(sprintf('%d of %d pairs judged', $index + 1, \count($pairs)));
            }
        }

        return $this->report->write($outDir, $pairs, $judgeKeys, $tokens, $randomizer->shuffleArray($pairs), $sampleSize, $seed);
    }

    /**
     * @param list<string> $answerPaths
     *
     * @return list<CitationPair>
     */
    private function pairs(array $answerPaths): array
    {
        $pairs = [];
        foreach ($answerPaths as $path) {
            foreach ($this->answers->load($path) as $answer) {
                foreach ($this->extractor->statements($answer['text']) as $statement) {
                    $found = $this->passages->forStatement($statement['statement'], $statement['refs'], $answer['evidence']);
                    $pairs[] = [
                        'pair' => 'p'.(\count($pairs) + 1),
                        'arm' => $answer['arm'],
                        'item' => $answer['id'],
                        'run' => $answer['run'],
                        'statement' => $statement['statement'],
                        'refs' => $statement['refs'],
                        'status' => $found['status'],
                        'passage' => $found['passage'],
                        'votes' => [],
                        'verdict' => 'unknown_ref' === $found['status'] ? 'does_not_support' : 'not_judged',
                    ];
                }
            }
        }

        return $pairs;
    }

    /**
     * @param CitationPair       $pair
     * @param list<string>       $judgeKeys
     * @param array<string, int> $tokens
     *
     * @return CitationPair
     */
    private function judge(array $pair, array $judgeKeys, int $userId, array &$tokens): array
    {
        foreach (\array_slice($judgeKeys, 0, 2) as $key) {
            $pair['votes'][$key] = $this->vote($pair, $key, $userId, $tokens);
        }
        $votes = array_values($pair['votes']);
        if ($votes[0] === $votes[1]) {
            $pair['verdict'] = $votes[0];

            return $pair;
        }
        if (!isset($judgeKeys[2])) {
            $pair['verdict'] = 'uncertain';

            return $pair;
        }
        $third = $this->vote($pair, $judgeKeys[2], $userId, $tokens);
        $pair['votes'][$judgeKeys[2]] = $third;
        $pair['verdict'] = \in_array($third, $votes, true) && 'uncertain' !== $third ? $third : 'uncertain';

        return $pair;
    }

    /**
     * @param array{statement: string, passage: string} $pair
     * @param array<string, int>                        $tokens
     */
    private function vote(array $pair, string $key, int $userId, array &$tokens): string
    {
        try {
            $answer = $this->judges->judgeCitation($pair['statement'], $pair['passage'], $key, $userId);
        } catch (\Throwable) {
            return 'judge_error';
        }
        $tokens[$key] += $answer['tokens'];

        return $answer['verdict'];
    }
}
