<?php

declare(strict_types=1);

namespace App\Command;

use App\Plug\PlugConfigService;
use App\Plug\Rerank\RagRerankEvalCorpus;
use App\Plug\Rerank\RerankRegistry;
use App\Service\RAG\VectorSearchService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Compare RAG recall with rerank off vs on. Not part of `make test`.
 *
 *   php bin/console app:rag:eval-rerank --user=2 --k=5 --report=var/rerank-eval.md
 */
#[AsCommand(
    name: 'app:rag:eval-rerank',
    description: 'Evaluate RAG recall@k with rerank off and on (needs a vector store)',
)]
final class RagRerankEvalCommand extends Command
{
    private const DEFAULT_CORPUS = 'tests/Eval/rag_rerank_eval_corpus.json';

    public function __construct(
        private readonly VectorSearchService $vectorSearch,
        private readonly PlugConfigService $plugConfig,
        private readonly RerankRegistry $registry,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('corpus', null, InputOption::VALUE_REQUIRED, 'Path to the corpus JSON', self::DEFAULT_CORPUS)
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'User id whose vector store to search', '2')
            ->addOption('k', null, InputOption::VALUE_REQUIRED, 'Recall@k', '5')
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Markdown report path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $corpusPath = (string) $input->getOption('corpus');
        if (!str_starts_with($corpusPath, '/')) {
            $corpusPath = $this->projectDir.'/'.$corpusPath;
        }

        try {
            $corpus = RagRerankEvalCorpus::load($corpusPath);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $userId = (int) $input->getOption('user');
        $k = max(1, (int) $input->getOption('k'));
        $off = $this->runPass($corpus['questions'], $userId, $k);
        $wasEnabled = $this->plugConfig->isRerankEnabled();
        $this->plugConfig->setRerank(
            true,
            $this->plugConfig->rerankCandidatesMultiplier(),
            $this->plugConfig->rerankLatencyBudgetMs(),
            $this->plugConfig->isRerankLlmFallback(),
        );
        try {
            // Without an active adapter the "on" pass equals "off" and would
            // store misleading numbers in RERANK.LAST_EVAL.
            $active = $this->registry->active();
            if (null === $active) {
                $io->error('No rerank adapter is active: bind a rerank model (Operate → Setup → Reranking) or enable the chat-model fallback, then re-run.');

                return Command::FAILURE;
            }
            $io->note('Rerank adapter for the "on" pass: '.$active->key());
            $on = $this->runPass($corpus['questions'], $userId, $k);
        } finally {
            $this->plugConfig->setRerank(
                $wasEnabled,
                $this->plugConfig->rerankCandidatesMultiplier(),
                $this->plugConfig->rerankLatencyBudgetMs(),
                $this->plugConfig->isRerankLlmFallback(),
            );
        }

        $report = [
            'date' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d'),
            'recallOff' => $off['recall'],
            'recallOn' => $on['recall'],
            'p95Off' => $off['p95'],
            'p95On' => $on['p95'],
        ];
        $this->plugConfig->setLastRerankEval($report);

        $markdown = $this->renderMarkdown($corpus['corpusId'], $k, $off, $on, $report);
        $reportPath = $input->getOption('report');
        if (\is_string($reportPath) && '' !== $reportPath) {
            if (!str_starts_with($reportPath, '/')) {
                $reportPath = $this->projectDir.'/'.$reportPath;
            }
            $dir = \dirname($reportPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($reportPath, $markdown);
            $io->success('Wrote '.$reportPath);
        }

        $io->writeln($markdown);

        return Command::SUCCESS;
    }

    /**
     * @param list<array{id: string, question: string, expected: array{file: string, mustContain: string}}> $questions
     *
     * @return array{recall: float, mrr: float, p50: float, p95: float, fallbacks: int, hits: int}
     */
    private function runPass(array $questions, int $userId, int $k): array
    {
        $hits = 0;
        $rr = 0.0;
        $latencies = [];
        $n = count($questions);
        foreach ($questions as $row) {
            $started = hrtime(true);
            $results = $this->vectorSearch->semanticSearch($row['question'], $userId, null, $k, 0.0);
            $latencies[] = (int) ((hrtime(true) - $started) / 1_000_000);
            $rank = $this->firstMatchRank($results, $row['expected']['mustContain']);
            if (null !== $rank && $rank <= $k) {
                ++$hits;
                $rr += 1 / $rank;
            }
        }

        sort($latencies);

        return [
            'recall' => $n > 0 ? $hits / $n : 0.0,
            'mrr' => $n > 0 ? $rr / $n : 0.0,
            'p50' => $this->percentile($latencies, 0.50),
            'p95' => $this->percentile($latencies, 0.95),
            'fallbacks' => 0,
            'hits' => $hits,
        ];
    }

    /**
     * @param list<array<string, mixed>> $results
     */
    private function firstMatchRank(array $results, string $needle): ?int
    {
        $needle = mb_strtolower($needle);
        foreach ($results as $i => $row) {
            $text = mb_strtolower((string) ($row['chunk_text'] ?? ''));
            $file = mb_strtolower((string) ($row['file_name'] ?? ''));
            if (str_contains($text, $needle) || str_contains($file, $needle)) {
                return $i + 1;
            }
        }

        return null;
    }

    /**
     * @param list<int> $values
     */
    private function percentile(array $values, float $p): float
    {
        if ([] === $values) {
            return 0.0;
        }
        $idx = (int) max(0, min(count($values) - 1, (int) floor($p * (count($values) - 1))));

        return (float) $values[$idx];
    }

    /**
     * @param array{recall: float, mrr: float, p50: float, p95: float, fallbacks: int, hits: int} $off
     * @param array{recall: float, mrr: float, p50: float, p95: float, fallbacks: int, hits: int} $on
     * @param array{date: string, recallOff: float, recallOn: float, p95Off: float, p95On: float} $report
     */
    private function renderMarkdown(string $corpusId, int $k, array $off, array $on, array $report): string
    {
        $flip = $on['recall'] > $off['recall'] && $on['p95'] <= $this->plugConfig->rerankLatencyBudgetMs()
            ? 'numbers improved — still keep ENABLED=0 until a reviewer pastes this into STATUS.md'
            : 'default stays 0 (decision 8)';

        return <<<MD
# RAG rerank eval — {$report['date']}

Corpus: `{$corpusId}` · k={$k}

| | Off | On |
| --- | ---: | ---: |
| recall@{$k} | {$off['recall']} | {$on['recall']} |
| MRR | {$off['mrr']} | {$on['mrr']} |
| p50 ms | {$off['p50']} | {$on['p50']} |
| p95 ms | {$off['p95']} | {$on['p95']} |

Decision: {$flip}.
MD;
    }
}
