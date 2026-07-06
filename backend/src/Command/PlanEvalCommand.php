<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Message;
use App\Service\Multitask\Plan\TaskPlan;
use App\Service\Multitask\Skill\SkillCatalog;
use App\Service\Multitask\TaskPlanner;
use App\Service\PromptService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Live-model evaluation of the multitask planner against a golden corpus.
 *
 * Runs each corpus prompt through the REAL TaskPlanner (real planner model,
 * real prompt substitution) and asserts the SHAPE of the resulting DAG:
 * required capabilities, forbidden capabilities, and dependency edges between
 * capabilities ("image_generation->file_analysis" = some file_analysis node
 * depends on some image_generation node).
 *
 * This is the measurable instrument for planner-prompt changes: instead of
 * eyeballing one chat, run the corpus before and after and compare pass rates.
 * Deliberately NOT part of the CI gate — it needs a live model — but every
 * change to `tools:plan` routing rules or canonical examples should come with
 * a run of this command in the PR description:
 *
 *   make -C backend plan-eval                    # full corpus
 *   php bin/console app:multitask:plan-eval --filter=describe_attached --repeat=3
 */
#[AsCommand(
    name: 'app:multitask:plan-eval',
    description: 'Evaluate the multitask planner against the golden prompt corpus (live model)',
)]
final class PlanEvalCommand extends Command
{
    private const DEFAULT_CORPUS = 'tests/Eval/plan_eval_corpus.json';

    public function __construct(
        private readonly TaskPlanner $planner,
        private readonly SkillCatalog $skillCatalog,
        private readonly PromptService $promptService,
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
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Resolve planner model for this user id (default: global)');
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

        $rows = [];
        $passed = 0;
        $failed = 0;

        foreach ($corpus as $case) {
            if (!is_array($case) || !is_string($case['id'] ?? null)) {
                continue;
            }
            if (is_string($filter) && '' !== $filter && !str_contains($case['id'], $filter)) {
                continue;
            }

            // Cases that need per-user state (e.g. a connected MCP server
            // with an entitled topic) declare it via `requires`; when the
            // environment can't satisfy it the case is SKIPPED, not failed,
            // so the corpus stays green on machines without that setup.
            $unmet = $this->unmetRequirement($case, $userId);
            if (null !== $unmet) {
                $rows[] = [
                    $case['id'],
                    '<comment>SKIP</comment>',
                    '',
                    $unmet,
                ];
                continue;
            }

            for ($run = 1; $run <= $repeat; ++$run) {
                [$ok, $detail, $shape] = $this->runCase($case, $userId);
                $ok ? ++$passed : ++$failed;
                $rows[] = [
                    $case['id'].($repeat > 1 ? " #{$run}" : ''),
                    $ok ? '<info>PASS</info>' : '<error>FAIL</error>',
                    $shape,
                    $detail,
                ];
            }
        }

        if ([] === $rows) {
            $io->warning('No corpus cases matched.');

            return Command::FAILURE;
        }

        $io->table(['case', 'result', 'plan shape', 'detail'], $rows);
        $io->writeln(sprintf('%d passed, %d failed (%d runs)', $passed, $failed, $passed + $failed));

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param array<string, mixed> $case
     *
     * @return array{0: bool, 1: string, 2: string}
     */
    private function runCase(array $case, ?int $userId): array
    {
        $message = $this->buildMessage($case);

        // Per-case request options (e.g. client_country so the time-context
        // block resolves a concrete timezone like a real Cloudflare request —
        // without it the planner rightly plans a "which timezone?" chat
        // question instead of a calendar_event).
        $options = is_array($case['options'] ?? null) ? $case['options'] : [];

        $result = $this->planner->plan($message, [], $userId, $options);
        $plan = $result->plan;

        $shape = $this->describeShape($plan);

        if ($result->fallback) {
            $raw = trim($result->rawResponse);

            return [false, 'planner fell back: '.implode('; ', $result->errors).('' !== $raw ? ' | raw: '.mb_substr($raw, 0, 220) : ''), $shape];
        }

        $problems = [];
        $capabilities = array_map(static fn ($n) => $n->capability->value, $plan->nodes);

        $expect = is_array($case['expect'] ?? null) ? $case['expect'] : [];

        foreach ((array) ($expect['capabilities'] ?? []) as $required) {
            if (!in_array($required, $capabilities, true)) {
                $problems[] = "missing capability '{$required}'";
            }
        }

        foreach ((array) ($expect['forbid'] ?? []) as $forbidden) {
            if (in_array($forbidden, $capabilities, true)) {
                $problems[] = "forbidden capability '{$forbidden}' present";
            }
        }

        foreach ((array) ($expect['edges'] ?? []) as $edge) {
            if (!is_string($edge) || !str_contains($edge, '->')) {
                continue;
            }
            [$fromCap, $toCap] = array_map('trim', explode('->', $edge, 2));
            if (!$this->hasEdge($plan, $fromCap, $toCap)) {
                $problems[] = "missing dependency edge {$fromCap}->{$toCap}";
            }
        }

        if ([] !== $problems) {
            $raw = trim($result->rawResponse);

            return [false, implode('; ', $problems).('' !== $raw ? ' | raw: '.mb_substr($raw, 0, 220) : ''), $shape];
        }

        return [true, '', $shape];
    }

    /**
     * Resolve a case's `requires` declaration. Currently supported:
     *
     *   - "mcp_connections": the rendered capability catalog for the resolved
     *     user + the case's classification topic must offer `mcp_fetch` with
     *     at least one connection (i.e. the user has a reachable MCP server
     *     and the topic opted in via `tool_mcp`). Mirrors exactly what
     *     TaskPlanner::catalogContext() feeds the SkillCatalog.
     *
     * @param array<string, mixed> $case
     *
     * @return string|null a human-readable skip reason, or null when all requirements are met
     */
    private function unmetRequirement(array $case, ?int $userId): ?string
    {
        $requires = $case['requires'] ?? null;
        if (!is_string($requires) || 'mcp_connections' !== $requires) {
            return null;
        }

        $options = is_array($case['options'] ?? null) ? $case['options'] : [];
        $classification = is_array($options['classification'] ?? null) ? $options['classification'] : [];
        $topic = is_string($classification['topic'] ?? null) ? $classification['topic'] : '';
        if ('' === $topic) {
            return 'requires=mcp_connections needs options.classification.topic';
        }

        $topicMetadata = [];
        try {
            $promptData = $this->promptService->getPromptWithMetadata($topic, $userId ?? 0);
            if (null !== $promptData && is_array($promptData['metadata'] ?? null)) {
                $topicMetadata = $promptData['metadata'];
            }
        } catch (\Throwable) {
            // Fall through — the catalog check below decides.
        }

        $catalog = $this->skillCatalog->renderCapabilityList($userId, [
            'topic' => $topic,
            'topic_metadata' => $topicMetadata,
        ]);

        if (!str_contains($catalog, 'mcp_fetch')) {
            return 'no MCP connections for this user/topic — connect a server and enable "MCP Data Sources" for the topic (try --user=<id>)';
        }

        return null;
    }

    /** Whether any node of capability $toCap depends on any node of capability $fromCap. */
    private function hasEdge(TaskPlan $plan, string $fromCap, string $toCap): bool
    {
        foreach ($plan->nodes as $to) {
            if ($to->capability->value !== $toCap) {
                continue;
            }
            foreach ($to->dependsOn as $depId) {
                $from = $plan->nodeById($depId);
                if (null !== $from && $from->capability->value === $fromCap) {
                    return true;
                }
            }
        }

        return false;
    }

    private function describeShape(TaskPlan $plan): string
    {
        $parts = [];
        foreach ($plan->nodes as $node) {
            $deps = [] === $node->dependsOn ? '' : '('.implode(',', $node->dependsOn).')';
            $parts[] = $node->id.':'.$node->capability->value.$deps;
        }

        return implode(' ', $parts);
    }

    /**
     * Build an in-memory message from a corpus case (never persisted). The
     * `attach` block simulates an attached file via the legacy single-file
     * columns — exactly the signal TaskPlanner::buildCurrentMessageJson reads
     * as a fallback when no File entities exist.
     *
     * @param array<string, mixed> $case
     */
    private function buildMessage(array $case): Message
    {
        $message = new Message();
        $message->setUserId(0);
        $message->setTrackingId(0);
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('YmdHis'));
        $message->setMessageType('WEB');
        $message->setDirection('IN');
        $message->setText(is_string($case['text'] ?? null) ? $case['text'] : '');
        $message->setLanguage(is_string($case['language'] ?? null) ? $case['language'] : 'en');
        $message->setFile(0);
        $message->setFilePath('');
        $message->setFileType('');
        $message->setFileText('');

        $attach = $case['attach'] ?? null;
        if (is_array($attach)) {
            $message->setFile(1);
            $message->setFileType(is_string($attach['type'] ?? null) ? $attach['type'] : '');
            $message->setFilePath(is_string($attach['path'] ?? null) ? $attach['path'] : '');
            if (is_string($attach['file_text'] ?? null)) {
                $message->setFileText($attach['file_text']);
            }
        }

        return $message;
    }
}
