<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Message;
use App\Service\Message\Effort\EffortVerdict;
use App\Service\Message\MessageSorter;
use App\Service\Research\Eval\RouterEval;
use App\Service\Research\Eval\RouterEvalReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Runs the production sorter (`tools:sort`, DEFAULTMODEL.SORT) over the
 * labelled router corpus and prints the effort-rating metrics with their
 * gate. One sorter call per item and repeat; billed to --user.
 */
#[AsCommand(
    name: 'app:research:router-eval',
    description: 'Score the sorter\'s effort rating against the router corpus.',
)]
final class ResearchRouterEvalCommand extends Command
{
    private const DEFAULT_CORPUS = 'tests/Eval/research/router.json';

    public function __construct(
        private readonly MessageSorter $sorter,
        private readonly RouterEval $eval,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('corpus', null, InputOption::VALUE_REQUIRED, '"router" or a path to a corpus file', 'router')
            ->addOption('repeat', null, InputOption::VALUE_REQUIRED, 'Rate each item this many times and take the majority', '1')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Account the sorter calls are billed to')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Comma-separated item ids');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userId = (int) $input->getOption('user');
        if ($userId < 1) {
            $io->error('Pass --user with the account the sorter calls are billed to. Nothing was sent.');

            return Command::FAILURE;
        }
        $corpusOption = (string) $input->getOption('corpus');
        $path = 'router' === $corpusOption ? self::DEFAULT_CORPUS : $corpusOption;
        $path = str_starts_with($path, '/') ? $path : $this->projectDir.'/'.$path;
        $items = $this->load($path, (string) $input->getOption('only'));
        if ([] === $items) {
            $io->error('No corpus items in '.$path.'. Nothing was sent.');

            return Command::FAILURE;
        }
        $repeat = max(1, (int) $input->getOption('repeat'));
        $io->writeln(sprintf('Rating %d items × %d with the production sorter.', count($items), $repeat));

        $started = microtime(true);
        $latencies = [];
        $report = $this->eval->run($items, function (array $item) use ($userId, $io, &$latencies): EffortVerdict {
            $t = microtime(true);
            $result = $this->sorter->classify($this->messageData($item), $this->history($item), $userId);
            $latencies[] = (microtime(true) - $t) * 1000;
            $verdict = EffortVerdict::fromRawResponse(is_string($result['raw_response'] ?? null) ? $result['raw_response'] : null);
            $io->writeln(sprintf('  %-12s %-9s %s', $item['id'], $verdict->voted ? $verdict->effort->value : 'none', $verdict->reason->value), OutputInterface::VERBOSITY_VERBOSE);

            return $verdict;
        }, $repeat);

        $this->print($io, $report, $latencies, microtime(true) - $started);

        return $report->meetsGate() ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return list<array{id: string, lang?: string, text: string, history?: list<array{role: string, text: string}>, label: string, why?: string}>
     */
    private function load(string $path, string $only): array
    {
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        $items = is_array($decoded) && is_array($decoded['items'] ?? null) ? $decoded['items'] : [];
        $wanted = array_filter(array_map('trim', explode(',', $only)));
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null) || !is_string($item['text'] ?? null) || !is_string($item['label'] ?? null)) {
                continue;
            }
            if ([] !== $wanted && !in_array($item['id'], $wanted, true)) {
                continue;
            }
            /* @var array{id: string, lang?: string, text: string, history?: list<array{role: string, text: string}>, label: string, why?: string} $item */
            $out[] = $item;
        }

        return $out;
    }

    /**
     * The same fields {@see \App\Service\Message\Classification\SorterInput} sends.
     *
     * @param array{id: string, lang?: string, text: string, history?: list<array{role: string, text: string}>, label: string, why?: string} $item
     *
     * @return array<string, mixed>
     */
    private function messageData(array $item): array
    {
        return [
            'BDATETIME' => date('YmdHis'),
            'BFILEPATH' => '',
            'BTOPIC' => '',
            'BLANG' => $item['lang'] ?? 'en',
            'BTEXT' => $item['text'],
            'BFILETEXT' => '',
            'BFILE' => 0,
            'BWEBSEARCH' => 0,
        ];
    }

    /**
     * @param array{id: string, lang?: string, text: string, history?: list<array{role: string, text: string}>, label: string, why?: string} $item
     *
     * @return list<Message>
     */
    private function history(array $item): array
    {
        $messages = [];
        foreach ($item['history'] ?? [] as $turn) {
            $message = new Message();
            $message->setDirection('assistant' === $turn['role'] ? 'OUT' : 'IN');
            $message->setText($turn['text']);
            $messages[] = $message;
        }

        return $messages;
    }

    /**
     * @param list<float> $latencies
     */
    private function print(SymfonyStyle $io, RouterEvalReport $report, array $latencies, float $seconds): void
    {
        sort($latencies);
        $p = static fn (float $q): int => [] === $latencies ? 0 : (int) round($latencies[(int) floor(($q / 100) * (count($latencies) - 1))]);
        $io->table(['Metric', 'Value', 'Gate'], [
            ['Agreement', self::pct($report->agreement), '≥ '.self::pct(RouterEval::GATE_AGREEMENT)],
            ['Over-escalation', self::pct($report->overEscalation), '≤ '.self::pct(RouterEval::GATE_OVER_ESCALATION)],
            ['Under-escalation', self::pct($report->underEscalation), '≤ '.self::pct(RouterEval::GATE_UNDER_ESCALATION)],
            ['Reason agreement', self::pct($report->reasonAgreement), 'reported'],
            ['No vote', self::pct($report->noVote), '≤ '.self::pct(RouterEval::GATE_NO_VOTE)],
            ['Sorter p50 / p95', $p(50).' / '.$p(95).' ms', 'reported'],
            ['Wall time', round($seconds).' s', ''],
        ]);
        $rows = [];
        foreach ($report->confusion as $label => $rated) {
            $rows[] = [$label, $rated['answer'], $rated['lookup'], $rated['research'], $rated['none']];
        }
        $io->table(['label \ rated', 'answer', 'lookup', 'research', 'none'], $rows);
        if ([] !== $report->disagreements) {
            $io->section('Disagreements');
            foreach ($report->disagreements as $row) {
                $io->writeln(sprintf('  %-12s label %-9s rated %-9s (%s)', $row->id, $row->label->value, $row->verdict->voted ? $row->verdict->effort->value : 'none', $row->verdict->reason->value));
            }
        }
        $report->meetsGate() ? $io->success('Gate met.') : $io->warning('Gate missed.');
    }

    private static function pct(float $share): string
    {
        return number_format($share * 100, 1).' %';
    }
}
