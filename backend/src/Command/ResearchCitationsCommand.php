<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Research\Eval\CitationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Judges citation precision of saved answers. Judges spend provider budget on
 * the evaluation account; cited pages that are URLs are read again.
 */
#[AsCommand(
    name: 'app:research:citations',
    description: 'Judge citation precision of saved research answers.',
)]
final class ResearchCitationsCommand extends Command
{
    private const DEFAULT_SAMPLE = 40;

    public function __construct(
        private readonly CitationRunner $runner,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('answers', null, InputOption::VALUE_REQUIRED, 'Comma-separated answer files from app:research:spike or app:research:eval --answers')
            ->addOption('judges', null, InputOption::VALUE_REQUIRED, 'Two judge keys, provider:model, plus an optional third as tie-breaker')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Evaluation account id')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Directory for report.md, pairs.json and sample.json', 'var/research-eval/citations')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Shuffle seed', '1')
            ->addOption('sample', null, InputOption::VALUE_REQUIRED, 'Pairs in the blind sample for people to label', (string) self::DEFAULT_SAMPLE)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Judge only the first N shuffled pairs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userId = (int) $input->getOption('user');
        $answers = $this->list($input->getOption('answers'));
        $judges = $this->list($input->getOption('judges'));
        if ($userId < 1 || [] === $answers) {
            $io->error('Pass --user with the evaluation account and --answers with at least one file. Nothing was sent.');

            return Command::FAILURE;
        }
        $limit = $input->getOption('limit');
        try {
            $io->writeln($this->runner->run(
                array_map(fn (string $path): string => $this->absolute($path), $answers),
                $judges,
                $userId,
                (int) $input->getOption('seed'),
                max(0, (int) $input->getOption('sample')),
                \is_string($limit) && ctype_digit($limit) ? (int) $limit : null,
                $this->absolute((string) $input->getOption('out')),
                static function (string $line) use ($io): void {
                    $io->getErrorStyle()->writeln($line);
                },
            ));
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function list(mixed $value): array
    {
        if (!\is_string($value)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $part): bool => '' !== $part));
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->projectDir.'/'.$path;
    }
}
