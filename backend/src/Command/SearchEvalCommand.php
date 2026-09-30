<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\SmartSearch\Eval\SearchEvalMetrics;
use App\Service\SmartSearch\RankFusion;
use App\Service\SmartSearch\SearchHit;
use App\Service\SmartSearch\SmartSearchService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ranking quality of the Ctrl/Cmd+K search against the golden queries:
 * Recall@5 and nDCG@10 per language and overall, through the real
 * SmartSearchService (all providers, live embedding model) restricted to the
 * kinds of the expected ids. The tool for tuning the RRF constant and the
 * provider weights.
 *
 * Needs an admin (settings are admin-only) and, for the non-English rows, a
 * multilingual embedding model; without one the run says it is keyword-only.
 * Not part of the CI gate:
 *
 *   php bin/console app:search:eval --user=admin@synaplan.com
 *   php bin/console app:search:eval --user=1 --rrf-k=20 --lang=de --json
 */
#[AsCommand(
    name: 'app:search:eval',
    description: 'Measure Smart Search ranking (Recall@5, nDCG@10) against the golden queries',
)]
final class SearchEvalCommand extends Command
{
    private const DEFAULT_CORPUS = 'tests/Eval/search_golden_queries.json';
    private const RECALL_K = 5;
    private const NDCG_K = 10;

    public function __construct(
        private readonly SmartSearchService $search,
        private readonly UserRepository $users,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Admin to search as (id or email)')
            ->addOption('corpus', null, InputOption::VALUE_REQUIRED, 'Golden queries JSON, relative to the backend dir', self::DEFAULT_CORPUS)
            ->addOption('lang', null, InputOption::VALUE_REQUIRED, 'Only queries in this language')
            ->addOption('rrf-k', null, InputOption::VALUE_REQUIRED, 'RRF constant for this run', (string) RankFusion::DEFAULT_K)
            ->addOption('min-recall', null, InputOption::VALUE_REQUIRED, 'Fail when overall Recall@5 is below this (0–1)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print machine-readable JSON instead of tables');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $user = $this->resolveUser((string) $input->getOption('user'));
        if (null === $user || !$user->isAdmin()) {
            $io->error('Pass an admin with --user=<id|email>; settings are only searched for admins.');

            return Command::INVALID;
        }

        $queries = $this->loadQueries((string) $input->getOption('corpus'), $io);
        if (null === $queries) {
            return Command::FAILURE;
        }
        $lang = $input->getOption('lang');
        if (is_string($lang) && '' !== $lang) {
            $queries = array_values(array_filter($queries, static fn (array $query): bool => $query['lang'] === $lang));
        }
        if ([] === $queries) {
            $io->warning('No golden query matched.');

            return Command::FAILURE;
        }

        $report = $this->evaluate($user, $queries, max(1, (int) $input->getOption('rrf-k')));

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($io, $report);
        }

        $minRecall = $input->getOption('min-recall');

        return null !== $minRecall && $report['total']['recall5'] < (float) $minRecall ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param list<array{lang: string, q: string, expect: list<string>}> $queries
     *
     * @return array{rrfK: int, semanticAvailable: bool, queries: list<array{lang: string, q: string, rank: ?int, recall5: float, ndcg10: float, top: list<string>}>, languages: array<string, array{count: int, recall5: float, ndcg10: float}>, total: array{count: int, recall5: float, ndcg10: float}}
     */
    private function evaluate(User $user, array $queries, int $rrfK): array
    {
        $rows = [];
        $semantic = true;
        foreach ($queries as $query) {
            $response = $this->search->search($user, $query['q'], self::expectedKinds($query['expect']), self::NDCG_K, $rrfK);
            $semantic = $semantic && $response->semanticAvailable;
            $ranked = array_map(static fn (SearchHit $hit): string => $hit->id(), $response->hits);
            $rows[] = [
                'lang' => $query['lang'],
                'q' => $query['q'],
                'rank' => SearchEvalMetrics::firstRank($ranked, $query['expect'], self::NDCG_K),
                'recall5' => SearchEvalMetrics::recallAt($ranked, $query['expect'], self::RECALL_K),
                'ndcg10' => SearchEvalMetrics::ndcgAt($ranked, $query['expect'], self::NDCG_K),
                'top' => array_slice($ranked, 0, 3),
            ];
        }

        $languages = [];
        foreach (array_unique(array_column($rows, 'lang')) as $lang) {
            $languages[$lang] = self::average(array_values(array_filter($rows, static fn (array $row): bool => $row['lang'] === $lang)));
        }

        return [
            'rrfK' => $rrfK,
            'semanticAvailable' => $semantic,
            'queries' => $rows,
            'languages' => $languages,
            'total' => self::average($rows),
        ];
    }

    /**
     * The palette groups results by kind, so a target competes only with
     * hits of its own kind; measure the ranking the user actually sees.
     *
     * @param list<string> $expected ids like `setting:KEY`
     *
     * @return list<string>
     */
    private static function expectedKinds(array $expected): array
    {
        return array_values(array_unique(array_map(static fn (string $id): string => explode(':', $id, 2)[0], $expected)));
    }

    /**
     * @param list<array{recall5: float, ndcg10: float}> $rows
     *
     * @return array{count: int, recall5: float, ndcg10: float}
     */
    private static function average(array $rows): array
    {
        $count = count($rows);

        return [
            'count' => $count,
            'recall5' => 0 === $count ? 0.0 : round(array_sum(array_column($rows, 'recall5')) / $count, 3),
            'ndcg10' => 0 === $count ? 0.0 : round(array_sum(array_column($rows, 'ndcg10')) / $count, 3),
        ];
    }

    /**
     * @param array{rrfK: int, semanticAvailable: bool, queries: list<array{lang: string, q: string, rank: ?int, recall5: float, ndcg10: float, top: list<string>}>, languages: array<string, array{count: int, recall5: float, ndcg10: float}>, total: array{count: int, recall5: float, ndcg10: float}} $report
     */
    private function render(SymfonyStyle $io, array $report): void
    {
        if (!$report['semanticAvailable']) {
            $io->warning('Keyword search only: no embedding model answered, so wording in other languages is expected to miss.');
        }

        $io->table(
            ['Lang', 'Query', 'Rank', 'R@5', 'nDCG@10', 'Top 3'],
            array_map(static fn (array $row): array => [
                $row['lang'],
                $row['q'],
                $row['rank'] ?? '–',
                number_format($row['recall5'], 2),
                number_format($row['ndcg10'], 2),
                implode(', ', $row['top']),
            ], $report['queries']),
        );

        $summary = [];
        foreach ($report['languages'] as $lang => $metrics) {
            $summary[] = [$lang, $metrics['count'], number_format($metrics['recall5'], 3), number_format($metrics['ndcg10'], 3)];
        }
        $summary[] = ['all', $report['total']['count'], number_format($report['total']['recall5'], 3), number_format($report['total']['ndcg10'], 3)];
        $io->table(['Lang', 'Queries', 'Recall@5', 'nDCG@10'], $summary);
        $io->writeln(sprintf('RRF k = %d', $report['rrfK']));
    }

    private function resolveUser(string $identifier): ?User
    {
        if ('' === $identifier) {
            return null;
        }

        return ctype_digit($identifier) ? $this->users->find((int) $identifier) : $this->users->findByEmail($identifier);
    }

    /**
     * @return list<array{lang: string, q: string, expect: list<string>}>|null
     */
    private function loadQueries(string $path, SymfonyStyle $io): ?array
    {
        $file = str_starts_with($path, '/') ? $path : $this->projectDir.'/'.$path;
        $raw = is_file($file) ? file_get_contents($file) : false;
        $data = false === $raw ? null : json_decode($raw, true);
        if (!is_array($data) || !is_array($data['queries'] ?? null)) {
            $io->error(sprintf('Cannot read golden queries from %s.', $file));

            return null;
        }

        $queries = [];
        foreach ($data['queries'] as $query) {
            if (!is_array($query) || !is_string($query['q'] ?? null) || !is_array($query['expect'] ?? null)) {
                continue;
            }
            $queries[] = [
                'lang' => is_string($query['lang'] ?? null) ? $query['lang'] : 'en',
                'q' => $query['q'],
                'expect' => array_values(array_filter($query['expect'], 'is_string')),
            ];
        }

        return $queries;
    }
}
