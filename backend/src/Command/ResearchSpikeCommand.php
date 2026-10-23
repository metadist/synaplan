<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Research\Spike\SpikeBatch;
use App\Service\Research\Spike\SpikeLimits;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * In-process research spike. It scores with the eval scorer and writes nothing else.
 */
#[AsCommand(
    name: 'app:research:spike',
    description: 'Run the research spike on the eval corpus and score fact recall.',
)]
final class ResearchSpikeCommand extends Command
{
    private const DEFAULT_CORPUS = 'tests/Eval/research/corpus.json';

    public function __construct(
        private readonly SpikeBatch $batch,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('corpus', null, InputOption::VALUE_REQUIRED, 'smoke, full, or a path to a corpus file', 'smoke')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'rounds, tools, or both', 'both')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Evaluation account id')
            ->addOption('repeat', null, InputOption::VALUE_REQUIRED, 'Run each item this many times', '1')
            ->addOption('searches', null, InputOption::VALUE_REQUIRED, 'Search budget', '10')
            ->addOption('sources', null, InputOption::VALUE_REQUIRED, 'Source budget', '15')
            ->addOption('turns', null, InputOption::VALUE_REQUIRED, 'Lead turns', '4')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Run one corpus id')
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Where to write the Markdown report');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userId = (int) $input->getOption('user');
        if ($userId < 1) {
            $io->error('Pass --user with the evaluation account. Nothing was sent.');

            return Command::FAILURE;
        }
        $mode = (string) $input->getOption('mode');
        if (!\in_array($mode, ['rounds', 'tools', 'both'], true)) {
            $io->error('Pass --mode with rounds, tools or both.');

            return Command::FAILURE;
        }

        $corpusOption = (string) $input->getOption('corpus');
        $path = \in_array($corpusOption, ['smoke', 'full'], true) ? $this->projectDir.'/'.self::DEFAULT_CORPUS : $this->absolute($corpusOption);
        $hash = is_file($path) ? hash_file('sha256', $path) : '';
        $limits = new SpikeLimits(
            max(0, (int) $input->getOption('searches')),
            max(0, (int) $input->getOption('sources')),
            max(1, (int) $input->getOption('turns')),
        );
        $modes = 'both' === $mode ? ['rounds', 'tools'] : [$mode];
        try {
            foreach ($modes as $one) {
                $command = 'app:research:spike --corpus='.$corpusOption.' --mode='.$one.' --user='.$userId;
                $only = $input->getOption('only');
                $text = $this->batch->run(
                    $path,
                    'smoke' === $corpusOption,
                    $one,
                    $userId,
                    $limits,
                    max(1, (int) $input->getOption('repeat')),
                    $command,
                    \is_string($only) && '' !== trim($only) ? trim($only) : null,
                );
                $io->writeln('Corpus sha256: '.$hash);
                $io->writeln($text);
                $report = $input->getOption('report');
                if (\is_string($report) && '' !== trim($report)) {
                    $target = $this->absolute($report);
                    if (\count($modes) > 1) {
                        $target = preg_replace('/\.md$/', '-'.$one.'.md', $target) ?? $target;
                    }
                    $dir = \dirname($target);
                    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                        throw new \RuntimeException('Could not create the report directory '.$dir.'.');
                    }
                    if (false === file_put_contents($target, 'Corpus sha256: '.$hash."\n\n".$text)) {
                        throw new \RuntimeException('Could not write the report to '.$target.'.');
                    }
                }
            }
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->projectDir.'/'.$path;
    }
}
