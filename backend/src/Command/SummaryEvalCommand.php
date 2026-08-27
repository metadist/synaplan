<?php

declare(strict_types=1);

namespace App\Command;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Service\Eval\SummaryEvalScorer;
use App\Service\Message\ConversationSummaryConfigService;
use App\Service\Message\ConversationSummaryPrompts;
use App\Service\ModelConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Live-model evaluation of the rolling conversation summary against a golden
 * corpus — the measurable instrument for "does the summary quality and size
 * hold with our main chat models?" (Anthropic, OpenAI, Google, HuggingFace,
 * Groq, Ollama, ...).
 *
 * Runs the REAL production prompts ({@see ConversationSummaryPrompts}) in both
 * modes (bootstrap + incremental fold) and scores the raw model output
 * deterministically ({@see SummaryEvalScorer}): size compliance, fact
 * retention, hallucination probes, language, structure.
 *
 * Deliberately NOT part of the CI gate — it needs live models. Providers
 * without a configured key are reported as SKIPPED, never failed, so the
 * command is runnable on any install:
 *
 *   make -C backend summary-eval
 *   php bin/console app:summary:eval --models=anthropic:claude-sonnet-4-5,openai:gpt-5
 *   php bin/console app:summary:eval --filter=rent_letter --repeat=3 --json
 */
#[AsCommand(
    name: 'app:summary:eval',
    description: 'Evaluate the rolling conversation summary against the golden corpus (live models)',
)]
final class SummaryEvalCommand extends Command
{
    private const DEFAULT_CORPUS = 'tests/Eval/summary_eval_corpus.json';

    /** Consecutive hard errors after which the remaining cases of a model are abandoned. */
    private const MAX_CONSECUTIVE_ERRORS = 3;

    public function __construct(
        private readonly AiFacade $aiFacade,
        private readonly ModelConfigService $modelConfigService,
        private readonly ConversationSummaryConfigService $summaryConfig,
        private readonly SummaryEvalScorer $scorer,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('corpus', null, InputOption::VALUE_REQUIRED, 'Path to the corpus JSON (relative to the backend dir)', self::DEFAULT_CORPUS)
            ->addOption('models', null, InputOption::VALUE_REQUIRED, 'Comma-separated provider:model pairs (default: the configured SUMMARIZE model)')
            ->addOption('filter', null, InputOption::VALUE_REQUIRED, 'Only run cases whose id contains this substring')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Restrict to one prompt mode: bootstrap | incremental')
            ->addOption('repeat', null, InputOption::VALUE_REQUIRED, 'Run every case N times (stability check)', '1')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Resolve the default summary model for this user id (default: global)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print machine-readable JSON instead of tables');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $corpus = $this->loadCorpus((string) $input->getOption('corpus'), $io);
        if (null === $corpus) {
            return Command::FAILURE;
        }

        $userOption = $input->getOption('user');
        $userId = null !== $userOption ? (int) $userOption : null;

        $targets = $this->resolveTargets($input->getOption('models'), $userId, $io);
        if ([] === $targets) {
            return Command::FAILURE;
        }

        $cases = $this->selectCases($corpus, $input->getOption('filter'), $input->getOption('mode'));
        if ([] === $cases) {
            $io->warning('No corpus cases matched.');

            return Command::FAILURE;
        }

        $repeat = max(1, (int) $input->getOption('repeat'));
        $summaryMax = $this->summaryConfig->getSummaryMaxChars();

        $report = [];
        $totalFailed = 0;
        foreach ($targets as $target) {
            $result = $this->evaluateModel($target, $cases, $repeat, $summaryMax, $userId);
            $report[] = $result;
            $totalFailed += $result['failed'] + $result['errors'];
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderTables($io, $report);
        }

        return 0 === $totalFailed ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function loadCorpus(string $corpusPath, SymfonyStyle $io): ?array
    {
        if (!str_starts_with($corpusPath, '/')) {
            $corpusPath = $this->projectDir.'/'.$corpusPath;
        }
        if (!is_file($corpusPath)) {
            $io->error("Corpus file not found: {$corpusPath}");

            return null;
        }

        $corpus = json_decode((string) file_get_contents($corpusPath), true);
        if (!is_array($corpus)) {
            $io->error('Corpus file is not valid JSON.');

            return null;
        }

        return array_values(array_filter($corpus, 'is_array'));
    }

    /**
     * @return list<array{label: string, provider: ?string, model: ?string}>
     */
    private function resolveTargets(mixed $modelsOption, ?int $userId, SymfonyStyle $io): array
    {
        if (is_string($modelsOption) && '' !== trim($modelsOption)) {
            $targets = [];
            foreach (explode(',', $modelsOption) as $pair) {
                $pair = trim($pair);
                if ('' === $pair) {
                    continue;
                }
                // Split on the FIRST colon only — model names may contain colons
                // (e.g. ollama "llama3.1:70b").
                $parts = explode(':', $pair, 2);
                if (2 !== count($parts) || '' === $parts[0] || '' === $parts[1]) {
                    $io->error("Invalid --models entry '{$pair}' — expected provider:model.");

                    return [];
                }
                $targets[] = ['label' => $pair, 'provider' => $parts[0], 'model' => $parts[1]];
            }

            return $targets;
        }

        $config = $this->modelConfigService->getSummaryModelConfig($userId);
        if (null === ($config['model'] ?? null)) {
            $io->error('No summary model configured (DEFAULTMODEL.SUMMARIZE/SORT/CHAT all unresolved) and no --models given.');

            return [];
        }

        return [[
            'label' => sprintf('default (%s:%s)', $config['provider'] ?? '?', $config['model']),
            'provider' => $config['provider'] ?? null,
            'model' => $config['model'],
        ]];
    }

    /**
     * @param list<array<string, mixed>> $corpus
     *
     * @return list<array<string, mixed>>
     */
    private function selectCases(array $corpus, mixed $filter, mixed $mode): array
    {
        $cases = [];
        foreach ($corpus as $case) {
            $id = $case['id'] ?? null;
            if (!is_string($id) || '' === $id) {
                continue;
            }
            if (is_string($filter) && '' !== $filter && !str_contains($id, $filter)) {
                continue;
            }
            if (is_string($mode) && '' !== $mode && ($case['mode'] ?? '') !== $mode) {
                continue;
            }
            $cases[] = $case;
        }

        return $cases;
    }

    /**
     * @param array{label: string, provider: ?string, model: ?string} $target
     * @param list<array<string, mixed>>                              $cases
     *
     * @return array{model: string, skipped: bool, skip_reason: string, passed: int, failed: int, errors: int, rows: list<array{case: string, result: string, chars: int, latency_ms: int, detail: string, summary: string}>}
     */
    private function evaluateModel(array $target, array $cases, int $repeat, int $summaryMax, ?int $userId): array
    {
        $rows = [];
        $passed = 0;
        $failed = 0;
        $errors = 0;
        $skipped = false;
        $skipReason = '';
        $consecutiveErrors = 0;
        $firstCall = true;

        foreach ($cases as $case) {
            for ($run = 1; $run <= $repeat; ++$run) {
                $label = (string) $case['id'].($repeat > 1 ? " #{$run}" : '');

                try {
                    $started = microtime(true);
                    $summary = $this->generateSummary($case, $summaryMax, $userId, $target);
                    $latencyMs = (int) round((microtime(true) - $started) * 1000);
                } catch (\Throwable $e) {
                    if ($firstCall && $this->looksLikeMissingProvider($e)) {
                        // Provider not configured on this install — the whole
                        // model is SKIPPED, never failed.
                        $skipped = true;
                        $skipReason = $e->getMessage();
                        break 2;
                    }

                    ++$errors;
                    ++$consecutiveErrors;
                    $rows[] = ['case' => $label, 'result' => 'ERROR', 'chars' => 0, 'latency_ms' => 0, 'detail' => mb_substr($e->getMessage(), 0, 200), 'summary' => ''];
                    if ($consecutiveErrors >= self::MAX_CONSECUTIVE_ERRORS) {
                        $rows[] = ['case' => '—', 'result' => 'ABORT', 'chars' => 0, 'latency_ms' => 0, 'detail' => 'too many consecutive errors, remaining cases abandoned', 'summary' => ''];
                        break 2;
                    }
                    continue;
                } finally {
                    $firstCall = false;
                }

                $consecutiveErrors = 0;
                $probes = is_array($case['probes'] ?? null) ? $case['probes'] : [];
                $score = $this->scorer->score(
                    $summary,
                    $summaryMax,
                    array_values(array_filter((array) ($probes['required'] ?? []), 'is_string')),
                    array_values(array_filter((array) ($probes['forbidden'] ?? []), 'is_string')),
                    is_string($case['expect_language'] ?? null) ? $case['expect_language'] : null,
                );

                $score->passed() ? ++$passed : ++$failed;
                $rows[] = [
                    'case' => $label,
                    'result' => $score->passed() ? 'PASS' : 'FAIL',
                    'chars' => $score->chars,
                    'latency_ms' => $latencyMs,
                    'detail' => $score->problems(),
                    'summary' => $summary,
                ];
            }
        }

        return [
            'model' => $target['label'],
            'skipped' => $skipped,
            'skip_reason' => $skipReason,
            'passed' => $passed,
            'failed' => $failed,
            'errors' => $errors,
            'rows' => $rows,
        ];
    }

    /**
     * Build the production prompt pair for a corpus case and run it once.
     *
     * @param array<string, mixed>                                    $case
     * @param array{label: string, provider: ?string, model: ?string} $target
     */
    private function generateSummary(array $case, int $summaryMax, ?int $userId, array $target): string
    {
        $messages = $this->buildMessages((array) ($case['messages'] ?? []));

        if ('incremental' === ($case['mode'] ?? '')) {
            $systemPrompt = ConversationSummaryPrompts::incrementalSystemPrompt($summaryMax);
            $userContent = ConversationSummaryPrompts::incrementalUserContent(
                is_string($case['previous_summary'] ?? null) ? $case['previous_summary'] : '',
                $messages,
            );
        } else {
            $tiers = is_int($case['tiers'] ?? null) ? $case['tiers'] : $this->summaryConfig->getTiers();
            $systemPrompt = ConversationSummaryPrompts::bootstrapSystemPrompt($summaryMax);
            $userContent = ConversationSummaryPrompts::bootstrapUserContent($messages, $tiers);
        }

        $response = $this->aiFacade->chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userContent],
        ], $userId, [
            'provider' => $target['provider'],
            'model' => $target['model'],
            'temperature' => 0.2,
            'max_tokens' => ConversationSummaryPrompts::tokenBudget($summaryMax),
        ]);

        // Deliberately NOT clipped: the eval measures whether the model itself
        // respects the cap — production clipping would hide the violation.
        return trim((string) ($response['content'] ?? ''));
    }

    /**
     * In-memory messages (never persisted), rendered exactly like production.
     *
     * @param list<mixed> $raw
     *
     * @return list<Message>
     */
    private function buildMessages(array $raw): array
    {
        // Reflection here is statically safe: Message::$id is a mapped Doctrine
        // property, and PHPStan (level max) verifies the property exists — a
        // try/catch around it would be provably dead code.
        $idProperty = new \ReflectionProperty(Message::class, 'id');

        $messages = [];
        $id = 0;
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $message = new Message();
            $idProperty->setValue($message, ++$id);
            $message->setUserId(0);
            $message->setTrackingId(0);
            $message->setUnixTimestamp(time());
            $message->setDateTime(date('YmdHis'));
            $message->setMessageType('WEB');
            $message->setDirection('user' === ($entry['role'] ?? '') ? 'IN' : 'OUT');
            $message->setText(is_string($entry['text'] ?? null) ? $entry['text'] : '');
            $message->setFile(0);
            $message->setFilePath('');
            $message->setFileType(is_string($entry['file_type'] ?? null) ? $entry['file_type'] : '');
            $message->setFileText(is_string($entry['file_text'] ?? null) ? $entry['file_text'] : '');

            $messages[] = $message;
        }

        return $messages;
    }

    /**
     * Environment limitations (missing key, unknown provider, local model not
     * pulled) mean the model cannot be evaluated HERE — that is a SKIP, not a
     * quality failure.
     */
    private function looksLikeMissingProvider(\Throwable $e): bool
    {
        return 1 === preg_match(
            '/api.?key|unauthori[sz]|credential|401|403|not configured|no such provider|unknown provider|provider .* not found|model .* not found|ollama pull/i',
            $e->getMessage(),
        );
    }

    /**
     * @param list<array{model: string, skipped: bool, skip_reason: string, passed: int, failed: int, errors: int, rows: list<array{case: string, result: string, chars: int, latency_ms: int, detail: string, summary: string}>}> $report
     */
    private function renderTables(SymfonyStyle $io, array $report): void
    {
        foreach ($report as $modelReport) {
            $io->section($modelReport['model']);

            if ($modelReport['skipped']) {
                $io->writeln(sprintf('<comment>SKIPPED</comment> — %s', $modelReport['skip_reason']));
                continue;
            }

            $tableRows = [];
            foreach ($modelReport['rows'] as $row) {
                $decorated = match ($row['result']) {
                    'PASS' => '<info>PASS</info>',
                    'FAIL' => '<error>FAIL</error>',
                    default => '<comment>'.$row['result'].'</comment>',
                };
                $tableRows[] = [$row['case'], $decorated, $row['chars'], $row['latency_ms'], $row['detail']];
            }

            $io->table(['case', 'result', 'chars', 'latency ms', 'detail'], $tableRows);
            $io->writeln(sprintf(
                '%d passed, %d failed, %d errors',
                $modelReport['passed'],
                $modelReport['failed'],
                $modelReport['errors'],
            ));
        }
    }
}
