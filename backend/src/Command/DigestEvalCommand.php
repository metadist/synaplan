<?php

declare(strict_types=1);

namespace App\Command;

use App\AI\Service\AiFacade;
use App\Service\Digest\DigestSearchService;
use App\Service\Digest\MessageDigestConfig;
use App\Service\Memory\MemoryEmbeddingModelResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Retrieval evaluation for the message-digest deep-memory index (Sprint 4).
 *
 * Embeds the golden corpus' digest titles and queries with the REAL memory
 * embedding model, ranks with cosine similarity + the REAL production recency
 * decay ({@see DigestSearchService::effectiveScore}), and reports hit@1,
 * hit@k, and MRR per case — the instrument for tuning `DIGEST.MIN_SCORE` and
 * `DIGEST.RECENCY_HALF_LIFE_DAYS`.
 *
 * Needs a live embedding model (bge-m3 by default), so it is NOT part of the
 * CI gate. A missing embedding provider is reported as SKIPPED, never failed:
 *
 *   make -C backend digest-eval
 *   php bin/console app:digest:eval --filter=office_rent --json
 *   php bin/console app:digest:eval --min-score=0.6 --half-life-days=90
 */
#[AsCommand(
    name: 'app:digest:eval',
    description: 'Evaluate digest retrieval quality against the golden corpus (live embedding model)',
)]
final class DigestEvalCommand extends Command
{
    private const DEFAULT_CORPUS = 'tests/Eval/digest_eval_corpus.json';

    /** @var array<string, list<float>> */
    private array $embeddingCache = [];

    public function __construct(
        private readonly AiFacade $aiFacade,
        private readonly MemoryEmbeddingModelResolver $embeddingResolver,
        private readonly MessageDigestConfig $digestConfig,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('corpus', null, InputOption::VALUE_REQUIRED, 'Path to the corpus JSON (relative to the backend dir)', self::DEFAULT_CORPUS)
            ->addOption('filter', null, InputOption::VALUE_REQUIRED, 'Only run cases whose id contains this substring')
            ->addOption('min-score', null, InputOption::VALUE_REQUIRED, 'Override DIGEST.MIN_SCORE for this run')
            ->addOption('half-life-days', null, InputOption::VALUE_REQUIRED, 'Override DIGEST.RECENCY_HALF_LIFE_DAYS for this run')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print machine-readable JSON instead of tables');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $cases = $this->loadCorpus((string) $input->getOption('corpus'), $io);
        if (null === $cases) {
            return Command::FAILURE;
        }

        $filter = $input->getOption('filter');
        if (null !== $filter && '' !== $filter) {
            $cases = array_values(array_filter(
                $cases,
                static fn (array $case): bool => str_contains((string) ($case['id'] ?? ''), (string) $filter)
            ));
        }
        if ([] === $cases) {
            $io->warning('No corpus cases matched.');

            return Command::FAILURE;
        }

        $embeddingConfig = $this->embeddingResolver->resolve();
        if (null === $embeddingConfig['model']) {
            $io->warning('SKIPPED: no memory embedding model configured (fresh install with embeddings disabled).');

            return Command::SUCCESS;
        }

        $minScoreOption = $input->getOption('min-score');
        $minScore = null !== $minScoreOption ? (float) $minScoreOption : $this->digestConfig->getMinScore();
        $halfLifeOption = $input->getOption('half-life-days');
        $halfLifeDays = null !== $halfLifeOption ? (int) $halfLifeOption : $this->digestConfig->getRecencyHalfLifeDays();
        $topK = $this->digestConfig->getTopK();

        try {
            $report = $this->evaluate($cases, $embeddingConfig, $minScore, $halfLifeDays, $topK);
        } catch (\Throwable $e) {
            if ($this->looksLikeMissingProvider($e->getMessage())) {
                $io->warning(sprintf('SKIPPED: embedding model unavailable — %s', $e->getMessage()));

                return Command::SUCCESS;
            }
            throw $e;
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderTables($io, $report, $minScore, $halfLifeDays, $topK, (string) $embeddingConfig['model']);
        }

        return 0 === $report['total']['misses'] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param list<array<string, mixed>>                                                 $cases
     * @param array{provider: ?string, model: ?string, model_id: ?int, vector_dim: ?int} $embeddingConfig
     *
     * @return array{cases: list<array<string, mixed>>, total: array{queries: int, hit1: int, hitk: int, misses: int, mrr: float}}
     */
    private function evaluate(array $cases, array $embeddingConfig, float $minScore, int $halfLifeDays, int $topK): array
    {
        $now = time();
        $halfLifeSeconds = $halfLifeDays * 86400;

        $caseReports = [];
        $totalQueries = 0;
        $totalHit1 = 0;
        $totalHitK = 0;
        $reciprocalRanks = [];

        foreach ($cases as $case) {
            $digests = [];
            foreach ((array) ($case['digests'] ?? []) as $digest) {
                $digests[] = [
                    'key' => (string) $digest['key'],
                    'title' => (string) $digest['title'],
                    'source_date' => $now - ((int) ($digest['days_ago'] ?? 0)) * 86400,
                    'vector' => $this->embed((string) $digest['title'], $embeddingConfig),
                ];
            }

            $queryReports = [];
            foreach ((array) ($case['queries'] ?? []) as $query) {
                $queryVector = $this->embed((string) $query['query'], $embeddingConfig);
                $expected = (string) $query['expect_top1'];

                $ranked = [];
                foreach ($digests as $digest) {
                    $score = $this->cosine($queryVector, $digest['vector']);
                    if ($score < $minScore) {
                        continue;
                    }
                    $ranked[] = [
                        'key' => $digest['key'],
                        'score' => round($score, 4),
                        'effective_score' => round(DigestSearchService::effectiveScore(
                            $score,
                            max(0, $now - $digest['source_date']),
                            $halfLifeSeconds,
                        ), 4),
                    ];
                }
                usort($ranked, static fn (array $a, array $b): int => $b['effective_score'] <=> $a['effective_score']);
                $ranked = array_slice($ranked, 0, $topK);

                $rank = null;
                foreach ($ranked as $i => $hit) {
                    if ($hit['key'] === $expected) {
                        $rank = $i + 1;
                        break;
                    }
                }

                ++$totalQueries;
                if (1 === $rank) {
                    ++$totalHit1;
                }
                if (null !== $rank) {
                    ++$totalHitK;
                }
                $reciprocalRanks[] = null !== $rank ? 1.0 / $rank : 0.0;

                $queryReports[] = [
                    'query' => (string) $query['query'],
                    'expected' => $expected,
                    'rank' => $rank,
                    'top' => $ranked,
                ];
            }

            $caseReports[] = [
                'id' => (string) ($case['id'] ?? ''),
                'queries' => $queryReports,
            ];
        }

        return [
            'cases' => $caseReports,
            'total' => [
                'queries' => $totalQueries,
                'hit1' => $totalHit1,
                'hitk' => $totalHitK,
                'misses' => $totalQueries - $totalHitK,
                'mrr' => [] !== $reciprocalRanks
                    ? round(array_sum($reciprocalRanks) / count($reciprocalRanks), 4)
                    : 0.0,
            ],
        ];
    }

    /**
     * @param array{provider: ?string, model: ?string, model_id: ?int, vector_dim: ?int} $embeddingConfig
     *
     * @return list<float>
     */
    private function embed(string $text, array $embeddingConfig): array
    {
        if (isset($this->embeddingCache[$text])) {
            return $this->embeddingCache[$text];
        }

        $result = $this->aiFacade->embed($text, 0, array_filter([
            'model' => $embeddingConfig['model'],
            'provider' => $embeddingConfig['provider'],
        ]));

        /** @var list<float> $embedding */
        $embedding = $result['embedding'];
        if ([] === $embedding) {
            throw new \RuntimeException(sprintf('Embedding model returned an empty vector for "%s"', mb_substr($text, 0, 60)));
        }

        return $this->embeddingCache[$text] = $embedding;
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        foreach ($a as $i => $valueA) {
            $valueB = $b[$i] ?? 0.0;
            $dot += $valueA * $valueB;
            $normA += $valueA * $valueA;
            $normB += $valueB * $valueB;
        }
        if (0.0 === $normA || 0.0 === $normB) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function loadCorpus(string $relativePath, SymfonyStyle $io): ?array
    {
        $path = str_starts_with($relativePath, '/') ? $relativePath : $this->projectDir.'/'.$relativePath;
        if (!is_file($path)) {
            $io->error(sprintf('Corpus file not found: %s', $path));

            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            $io->error(sprintf('Corpus file is not valid JSON: %s', $path));

            return null;
        }

        /* @var list<array<string, mixed>> $decoded */
        return array_values($decoded);
    }

    private function looksLikeMissingProvider(string $message): bool
    {
        return 1 === preg_match(
            '/api.?key|credential|unauthorized|401|403|not configured|no provider|provider.+(unavailable|not found)|model not found|ollama pull|connection refused|could not resolve/i',
            $message
        );
    }

    /**
     * @param array{cases: list<array<string, mixed>>, total: array{queries: int, hit1: int, hitk: int, misses: int, mrr: float}} $report
     */
    private function renderTables(SymfonyStyle $io, array $report, float $minScore, int $halfLifeDays, int $topK, string $model): void
    {
        $io->title(sprintf(
            'Digest retrieval eval — model %s, min_score %.2f, half-life %dd, top-k %d',
            $model,
            $minScore,
            $halfLifeDays,
            $topK,
        ));

        foreach ($report['cases'] as $case) {
            $rows = [];
            foreach ((array) $case['queries'] as $query) {
                $topSummary = implode(', ', array_map(
                    static fn (array $hit): string => sprintf('%s(%.2f)', $hit['key'], $hit['effective_score']),
                    array_slice((array) $query['top'], 0, 3),
                ));
                $rows[] = [
                    mb_substr((string) $query['query'], 0, 48),
                    (string) $query['expected'],
                    null === $query['rank'] ? '<error>MISS</error>' : ($query['rank'] > 1 ? sprintf('<comment>#%d</comment>', $query['rank']) : '<info>#1</info>'),
                    $topSummary,
                ];
            }
            $io->section((string) $case['id']);
            $io->table(['query', 'expected', 'rank', 'top hits (effective)'], $rows);
        }

        $total = $report['total'];
        $io->success(sprintf(
            '%d queries — hit@1 %d/%d, hit@%d %d/%d, MRR %.3f',
            $total['queries'],
            $total['hit1'],
            $total['queries'],
            $topK,
            $total['hitk'],
            $total['queries'],
            $total['mrr'],
        ));
    }
}
