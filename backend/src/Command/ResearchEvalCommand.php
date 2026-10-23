<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Research\Eval\EvalFiles;
use App\Service\Research\Eval\EvalLiveTarget;
use App\Service\Research\Eval\EvalRequest;
use App\Service\Research\Eval\EvalRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Scores the research corpus. Live arms spend provider budget on the
 * evaluation account and write nothing else.
 */
#[AsCommand(
    name: 'app:research:eval',
    description: 'Score the research corpus. Live arms use the evaluation account.',
)]
final class ResearchEvalCommand extends Command
{
    private const DEFAULT_CORPUS = 'tests/Eval/research/corpus.json';
    private const API_KEY_ENV = 'RESEARCH_EVAL_API_KEY';

    public function __construct(
        private readonly EvalRunner $runner,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('corpus', null, InputOption::VALUE_REQUIRED, 'smoke, full, or a path to a corpus file', 'smoke')
            ->addOption('arm', null, InputOption::VALUE_REQUIRED, 'b0, b1, b1plus or b4')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Evaluation account id. Required for a live b0 run and for --judges.')
            ->addOption('repeat', null, InputOption::VALUE_REQUIRED, 'Run each live item this many times and average the recall', '1')
            ->addOption('import', null, InputOption::VALUE_REQUIRED, 'JSON file of reports (id, text). Required for b1. Skips the live arm.')
            ->addOption('frozen', null, InputOption::VALUE_REQUIRED, 'Directory of recorded searches. The first run writes it; later runs replay it.')
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Where to write the Markdown report')
            ->addOption('answers', null, InputOption::VALUE_REQUIRED, 'Where to save each answer, for app:research:citations')
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Knowledge folder key for items that read documents')
            ->addOption('gateway-url', null, InputOption::VALUE_REQUIRED, 'Base URL for b1plus, without /v1/messages')
            ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'Evaluation API key for b1plus. Prefer the '.self::API_KEY_ENV.' env var; an option stays in the shell history')
            ->addOption('gateway-model', null, InputOption::VALUE_REQUIRED, 'Model for b1plus, provider id or service:providerId:tag. Only a paired desktop key may omit it')
            ->addOption('b4-endpoint', null, InputOption::VALUE_REQUIRED, 'External agent URL that returns {"text":"..."}')
            ->addOption('web-search', null, InputOption::VALUE_NONE, 'b1plus declares the web_search tool, as a desktop client does')
            ->addOption('judges', null, InputOption::VALUE_REQUIRED, 'Comma-separated judge keys, provider:model')
            ->addOption('write-documents', null, InputOption::VALUE_REQUIRED, 'Write the synthetic knowledge files and stop');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $io->writeln($this->runner->run($this->request($input)));
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function request(InputInterface $input): EvalRequest
    {
        $corpusOption = (string) $input->getOption('corpus');
        $smokeOnly = 'smoke' === $corpusOption;
        $corpus = \in_array($corpusOption, ['smoke', 'full'], true) ? self::DEFAULT_CORPUS : $corpusOption;
        $judges = trim((string) $input->getOption('judges'));

        return new EvalRequest(
            arm: (string) ($input->getOption('arm') ?? ''),
            corpusPath: $this->absolute($corpus),
            smokeOnly: $smokeOnly,
            userId: max(0, (int) $input->getOption('user')),
            repeat: max(1, (int) $input->getOption('repeat')),
            live: new EvalLiveTarget(
                $this->optionalString($input->getOption('folder')),
                $this->optionalString($input->getOption('gateway-url')),
                $this->optionalString($input->getOption('api-key')) ?? $this->optionalString($_SERVER[self::API_KEY_ENV] ?? getenv(self::API_KEY_ENV)),
                $this->optionalString($input->getOption('gateway-model')),
                $this->optionalString($input->getOption('b4-endpoint')),
                '' === $judges ? [] : array_values(array_filter(array_map('trim', explode(',', $judges)))),
                (bool) $input->getOption('web-search'),
            ),
            files: new EvalFiles(
                $this->optionalPath($input->getOption('import')),
                $this->optionalPath($input->getOption('frozen')),
                $this->optionalPath($input->getOption('report')),
                $this->optionalPath($input->getOption('write-documents')),
                $this->optionalPath($input->getOption('answers')),
            ),
            commandLine: 'app:research:eval --corpus='.$corpusOption.' --arm='.(string) $input->getOption('arm').($input->getOption('web-search') ? ' --web-search' : ''),
        );
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->projectDir.'/'.$path;
    }

    private function optionalPath(mixed $value): ?string
    {
        if (!\is_string($value) || '' === trim($value)) {
            return null;
        }

        return $this->absolute($value);
    }

    private function optionalString(mixed $value): ?string
    {
        if (!\is_string($value) || '' === trim($value)) {
            return null;
        }

        return $value;
    }
}
