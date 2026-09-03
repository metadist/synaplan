<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\PromptRepository;
use App\Service\Message\MessageSorter;
use App\Service\Message\Routing\EmbeddingRouterConfig;
use App\Service\Message\Routing\EmbeddingRouterService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Live-model evaluation of the message sorter (`tools:sort`) against a golden corpus.
 *
 * Runs each corpus utterance through the REAL MessageSorter (real SORT model,
 * real prompt substitution) and checks the classification against the
 * expected BTOPIC / BLANG / BMULTI / BMEDIA / BWEBSEARCH. Unlike the routing
 * characterization test (which injects a canned sorter result to lock
 * downstream behavior), this command measures whether the sorter itself is
 * getting the CALL right.
 *
 * Deliberately NOT part of the CI gate — it needs a live model — but every
 * change to `tools:sort` or the classification schema should come with a run
 * of this command, before and after, in the PR description:
 *
 *   make -C backend sort-eval
 *   php bin/console app:sort-eval --filter=mediamaker --repeat=3
 *
 * The most important number this command reports is "invalid topic rate":
 * the share of responses whose BTOPIC is not one of the topics that were
 * actually offered to the model. That number can only go up when the sorter
 * hallucinates a category — see MessageSorter::parseResponse(), which today
 * accepts BTOPIC unvalidated. Structured-output enum constraints are meant
 * to drive this number to zero.
 */
#[AsCommand(
    name: 'app:sort-eval',
    description: 'Evaluate the message sorter (tools:sort) against the golden utterance corpus (live model)',
)]
final class SortEvalCommand extends Command
{
    private const DEFAULT_CORPUS = 'tests/Eval/sort_eval_corpus.json';

    public function __construct(
        private readonly MessageSorter $sorter,
        private readonly PromptRepository $promptRepository,
        private readonly EmbeddingRouterService $embeddingRouter,
        private readonly EmbeddingRouterConfig $embeddingRouterConfig,
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
            ->addOption('repeat', null, InputOption::VALUE_REQUIRED, 'Run every case N times (stability check)', '1')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Resolve the sorting model for this user id (default: global)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON summary instead of a table (for before/after diffing)')
            ->addOption('cascade', null, InputOption::VALUE_NONE, 'Phase 8: try the embedding-router cascade layer first (bypassing its BCONFIG flag — this IS the calibration tool for that flag); escalate to the AI sorter on a sub-threshold match. Reports accuracy and latency separately per layer that made the final decision.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $corpusPath = (string) $input->getOption('corpus');
        if (!str_starts_with($corpusPath, '/')) {
            $corpusPath = $this->projectDir.'/'.$corpusPath;
        }
        if (!is_file($corpusPath)) {
            $io->error("Corpus file not found: {$corpusPath}");

            return Command::FAILURE;
        }

        $corpus = json_decode((string) file_get_contents($corpusPath), true);
        if (!is_array($corpus)) {
            $io->error('Corpus file is not valid JSON.');

            return Command::FAILURE;
        }

        $filter = $input->getOption('filter');
        $repeat = max(1, (int) $input->getOption('repeat'));
        $userOption = $input->getOption('user');
        $userId = null !== $userOption ? (int) $userOption : null;
        $asJson = (bool) $input->getOption('json');
        $cascade = (bool) $input->getOption('cascade');
        $confidenceThreshold = $this->embeddingRouterConfig->getConfidenceThreshold();

        // The set of topics actually offered to the model for this user.
        // Anything the model returns outside this set is a hallucinated
        // category — the metric structured output is meant to eliminate.
        $validTopics = $this->promptRepository->getAllTopics(0, $userId, excludeTools: true);
        // Slash-command topics (tools:pic, tools:vid, ...) never come from
        // the sorter's own vocabulary but are a legitimate classify() output
        // for slash-command corpus cases, so they don't count as "invalid".
        $validTopics = array_merge($validTopics, ['tools:pic', 'tools:vid', 'tools:tts', 'tools:search', 'tools:lang', 'tools:web', 'tools:list', 'tools:filesort']);

        $rows = [];
        $topicHits = 0;
        $topicTotal = 0;
        $langHits = 0;
        $langTotal = 0;
        $invalidTopicCount = 0;
        $parseFailureCount = 0;
        $runsTotal = 0;
        /** @var array<string, array<string, int>> $confusion expected topic => [actual topic => count] */
        $confusion = [];
        /** @var array<string, array{hits: int, total: int}> $perTopic */
        $perTopic = [];
        /** @var array<string, array{total: int, topic_hits: int, topic_total: int, latency_ms_sum: float}> $layerStats keyed by cascade layer ('embedding_router' | 'ai_sorting') */
        $layerStats = [];

        foreach ($corpus as $case) {
            if (!is_array($case) || !is_string($case['id'] ?? null)) {
                continue;
            }
            if (is_string($filter) && '' !== $filter && !str_contains($case['id'], $filter)) {
                continue;
            }

            $expect = is_array($case['expect'] ?? null) ? $case['expect'] : [];
            $expectedTopic = is_string($expect['topic'] ?? null) ? $expect['topic'] : null;

            for ($run = 1; $run <= $repeat; ++$run) {
                ++$runsTotal;
                $messageData = $this->buildMessageData($case);
                $text = is_string($messageData['BTEXT'] ?? null) ? $messageData['BTEXT'] : '';

                $layer = 'ai_sorting';
                $startedAt = microtime(true);
                $result = null;

                if ($cascade) {
                    // This is the calibration tool for EmbeddingRouterConfig,
                    // so it deliberately bypasses isEnabled() and evaluates
                    // the confidence threshold directly against the raw
                    // match — the whole point is to answer "if this were
                    // turned on with threshold X, what would happen?".
                    $embeddingMatch = $this->embeddingRouter->findClosestAnchor($text, $userId);
                    if (null !== $embeddingMatch && $embeddingMatch->score >= $confidenceThreshold) {
                        $layer = 'embedding_router';
                        // The embedding router only votes on topic. Language,
                        // multi_step, media_type, and web_search are left
                        // unset here (not asserted downstream against the
                        // sorter's richer votes) — see EmbeddingRouterService's
                        // docblock for why those are intentionally out of
                        // scope for this layer.
                        $result = ['topic' => $embeddingMatch->topic, 'source' => 'embedding_router'];
                    }
                }

                if (null === $result) {
                    $result = $this->sorter->classify($messageData, [], $userId);
                }

                $latencyMs = (microtime(true) - $startedAt) * 1000.0;

                $actualTopic = (string) ($result['topic'] ?? 'general');
                $actualLang = (string) ($result['language'] ?? 'en');

                $rawResponse = (string) ($result['raw_response'] ?? '');
                $parseFailed = '' !== $rawResponse && !$this->looksLikeParsedJson($rawResponse);
                if ($parseFailed) {
                    ++$parseFailureCount;
                }

                if ($cascade) {
                    $layerStats[$layer]['total'] = ($layerStats[$layer]['total'] ?? 0) + 1;
                    $layerStats[$layer]['latency_ms_sum'] = ($layerStats[$layer]['latency_ms_sum'] ?? 0.0) + $latencyMs;
                    if (null !== $expectedTopic) {
                        $layerStats[$layer]['topic_total'] = ($layerStats[$layer]['topic_total'] ?? 0) + 1;
                        if ($actualTopic === $expectedTopic) {
                            $layerStats[$layer]['topic_hits'] = ($layerStats[$layer]['topic_hits'] ?? 0) + 1;
                        }
                    }
                }

                $isInvalidTopic = '' !== $actualTopic && !in_array($actualTopic, $validTopics, true);
                if ($isInvalidTopic) {
                    ++$invalidTopicCount;
                }

                $problems = [];

                if (null !== $expectedTopic) {
                    ++$topicTotal;
                    $perTopic[$expectedTopic]['total'] = ($perTopic[$expectedTopic]['total'] ?? 0) + 1;
                    $topicOk = $actualTopic === $expectedTopic;
                    if ($topicOk) {
                        ++$topicHits;
                        $perTopic[$expectedTopic]['hits'] = ($perTopic[$expectedTopic]['hits'] ?? 0) + 1;
                    } else {
                        $confusion[$expectedTopic][$actualTopic] = ($confusion[$expectedTopic][$actualTopic] ?? 0) + 1;
                        $problems[] = "topic: expected '{$expectedTopic}', got '{$actualTopic}'".($isInvalidTopic ? ' (INVALID — not offered to the model)' : '');
                    }
                }

                $expectedLang = is_string($expect['lang'] ?? null) ? $expect['lang'] : null;
                if (null !== $expectedLang) {
                    ++$langTotal;
                    if ($actualLang === $expectedLang) {
                        ++$langHits;
                    } else {
                        $problems[] = "lang: expected '{$expectedLang}', got '{$actualLang}'";
                    }
                }

                if (array_key_exists('multi_step', $expect)) {
                    $actualMulti = $result['multi_step'] ?? null;
                    if ($actualMulti !== $expect['multi_step']) {
                        $problems[] = 'multi_step: expected '.json_encode($expect['multi_step']).', got '.json_encode($actualMulti);
                    }
                }

                if (array_key_exists('media_type', $expect)) {
                    $actualMedia = $result['media_type'] ?? null;
                    if ($actualMedia !== $expect['media_type']) {
                        $problems[] = "media_type: expected '{$expect['media_type']}', got '{$actualMedia}'";
                    }
                }

                if (array_key_exists('web_search', $expect)) {
                    $actualWebSearch = (bool) ($result['web_search'] ?? false);
                    if ($actualWebSearch !== (bool) $expect['web_search']) {
                        $problems[] = 'web_search: expected '.($expect['web_search'] ? 'true' : 'false').', got '.($actualWebSearch ? 'true' : 'false');
                    }
                }

                $ok = [] === $problems;
                $row = [
                    $case['id'].($repeat > 1 ? " #{$run}" : ''),
                    $ok ? '<info>PASS</info>' : '<error>FAIL</error>',
                    $actualTopic.($isInvalidTopic ? ' <error>[INVALID]</error>' : ''),
                    $actualLang,
                    implode('; ', $problems),
                ];
                if ($cascade) {
                    $row[] = $layer;
                    $row[] = sprintf('%.1f', $latencyMs);
                }
                $rows[] = $row;
            }
        }

        if ([] === $rows) {
            $io->warning('No corpus cases matched.');

            return Command::FAILURE;
        }

        $topicAccuracy = $topicTotal > 0 ? round($topicHits / $topicTotal * 100, 1) : 0.0;
        $langAccuracy = $langTotal > 0 ? round($langHits / $langTotal * 100, 1) : 0.0;
        $invalidTopicRate = $runsTotal > 0 ? round($invalidTopicCount / $runsTotal * 100, 1) : 0.0;
        $parseFailureRate = $runsTotal > 0 ? round($parseFailureCount / $runsTotal * 100, 1) : 0.0;

        $summary = [
            'runs' => $runsTotal,
            'topic_accuracy_pct' => $topicAccuracy,
            'lang_accuracy_pct' => $langAccuracy,
            'invalid_topic_rate_pct' => $invalidTopicRate,
            'parse_failure_rate_pct' => $parseFailureRate,
            'per_topic' => array_map(
                static fn (array $v) => $v['total'] > 0 ? round(($v['hits'] ?? 0) / $v['total'] * 100, 1) : 0.0,
                $perTopic
            ),
            'confusion' => $confusion,
        ];

        if ($cascade) {
            // Phase 8 acceptance criterion: accuracy and latency reported
            // SEPARATELY per cascade layer, never blended into the headline
            // topic_accuracy_pct above — a high embedding-router escalation
            // rate must not be hidden behind a good blended number.
            $summary['cascade'] = array_map(
                static fn (array $v): array => [
                    'decisions' => $v['total'],
                    'topic_accuracy_pct' => ($v['topic_total'] ?? 0) > 0
                        ? round((($v['topic_hits'] ?? 0) / $v['topic_total']) * 100, 1)
                        : 0.0,
                    'avg_latency_ms' => $v['total'] > 0
                        ? round($v['latency_ms_sum'] / $v['total'], 1)
                        : 0.0,
                ],
                $layerStats
            );
        }

        if ($asJson) {
            $output->writeln(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        $io->table($cascade ? ['case', 'result', 'topic', 'lang', 'detail', 'layer', 'latency_ms'] : ['case', 'result', 'topic', 'lang', 'detail'], $rows);

        if ($cascade && [] !== $layerStats) {
            $io->section('Cascade layers (Phase 8)');
            $cascadeRows = [];
            foreach ($summary['cascade'] as $layerName => $stats) {
                $cascadeRows[] = [$layerName, $stats['decisions'], $stats['topic_accuracy_pct'].'%', $stats['avg_latency_ms'].' ms'];
            }
            $io->table(['layer', 'decisions', 'topic accuracy', 'avg latency'], $cascadeRows);
            $io->writeln(sprintf('<comment>Confidence threshold in effect: %.2f (BCONFIG EMBEDDING_ROUTER.CONFIDENCE_THRESHOLD)</comment>', $confidenceThreshold));
        }

        $io->section('Summary');
        $io->writeln(sprintf('Topic accuracy: %.1f%% (%d/%d)', $topicAccuracy, $topicHits, $topicTotal));
        $io->writeln(sprintf('Lang accuracy: %.1f%% (%d/%d)', $langAccuracy, $langHits, $langTotal));
        $io->writeln(sprintf('<comment>Invalid topic rate: %.1f%% (%d/%d)</comment> — hallucinated categories not offered to the model', $invalidTopicRate, $invalidTopicCount, $runsTotal));
        $io->writeln(sprintf('JSON parse failure rate: %.1f%% (%d/%d)', $parseFailureRate, $parseFailureCount, $runsTotal));

        if ([] !== $confusion) {
            $confusionRows = [];
            foreach ($confusion as $expected => $actuals) {
                foreach ($actuals as $actual => $count) {
                    $confusionRows[] = [$expected, $actual, $count];
                }
            }
            $io->section('Confusion pairs (expected -> actual)');
            $io->table(['expected', 'actual', 'count'], $confusionRows);
        }

        return $topicAccuracy >= 100.0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Best-effort independent check of whether the raw model response was
     * parseable JSON, mirroring MessageSorter::parseResponse()'s own
     * fence-stripping — but computed here, outside the sorter, so a parse
     * failure is visible even though classify() swallows it into a silent
     * "general" fallback.
     */
    private function looksLikeParsedJson(string $raw): bool
    {
        $text = trim($raw);
        if (str_starts_with($text, '```')) {
            $text = (string) preg_replace('/^```(?:json)?\s*/', '', $text);
            $text = (string) preg_replace('/\s*```$/', '', $text);
            $text = trim($text);
        }

        try {
            json_decode($text, true, 512, JSON_THROW_ON_ERROR);

            return true;
        } catch (\JsonException) {
            return false;
        }
    }

    /**
     * Build the same message-data shape MessageClassifier::buildMessageData()
     * sends to the sorter, from a corpus case.
     *
     * @param array<string, mixed> $case
     *
     * @return array<string, mixed>
     */
    private function buildMessageData(array $case): array
    {
        $data = [
            'BDATETIME' => date('YmdHis'),
            'BFILEPATH' => '',
            'BTOPIC' => '',
            'BLANG' => is_string($case['language'] ?? null) ? $case['language'] : 'en',
            'BTEXT' => is_string($case['text'] ?? null) ? $case['text'] : '',
            'BFILETEXT' => '',
            'BFILE' => 0,
            'BWEBSEARCH' => 0,
        ];

        $attach = $case['attach'] ?? null;
        if (is_array($attach)) {
            $data['BFILE'] = 1;
            $data['BFILETYPE'] = is_string($attach['type'] ?? null) ? $attach['type'] : '';
            if (is_string($attach['file_text'] ?? null)) {
                $data['BFILETEXT'] = $attach['file_text'];
            }
        }

        return $data;
    }
}
