<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Message;
use App\Service\Message\Handler\ChatHandler;
use App\Service\Message\MessageClassifier;
use App\Service\SelfAware\Eval\SelfAwareEvalAssertions;
use App\Service\SelfAware\Eval\SelfAwareEvalCorpus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Live-model evaluation of platform self-awareness (routing + answer text).
 *
 * Not part of `make test` — needs a chat model. Release spot-check:
 *
 *   php bin/console app:selfaware:eval --install=no_engine
 *   php bin/console app:selfaware:eval --install=full
 */
#[AsCommand(
    name: 'app:selfaware:eval',
    description: 'Evaluate self-aware routing and answers against the frozen question set (live model)',
)]
final class SelfAwareEvalCommand extends Command
{
    private const DEFAULT_CORPUS = 'tests/Eval/self_aware_eval_corpus.json';

    public function __construct(
        private readonly MessageClassifier $classifier,
        private readonly ChatHandler $chatHandler,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('corpus', null, InputOption::VALUE_REQUIRED, 'Path to the corpus JSON', self::DEFAULT_CORPUS)
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Comma-separated row ids (Q1,N2)')
            ->addOption('install', null, InputOption::VALUE_REQUIRED, 'Only rows for this install profile (no_keys|no_engine|full)')
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'json or table', 'table')
            ->addOption('routing-only', null, InputOption::VALUE_NONE, 'Assert classification only (no live chat call)')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'User id for inventory + chat', '2');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $corpusPath = (string) $input->getOption('corpus');
        if (!str_starts_with($corpusPath, '/')) {
            $corpusPath = $this->projectDir.'/'.$corpusPath;
        }

        try {
            $rows = SelfAwareEvalCorpus::select(
                SelfAwareEvalCorpus::load($corpusPath),
                $input->getOption('only'),
                $input->getOption('install'),
            );
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $routingOnly = (bool) $input->getOption('routing-only');
        $userId = (int) $input->getOption('user');
        $passed = 0;
        $failed = 0;
        $skipped = 0;
        $table = [];

        foreach ($rows as $row) {
            $id = $row['id'];
            $expect = $row['expect'];
            $message = new Message();
            $message->setUserId($userId);
            $message->setTrackingId(0);
            $message->setText($row['text']);
            $message->setLanguage($row['lang']);
            $message->setDirection('IN');
            $message->setUnixTimestamp(time());
            $message->setDateTime(date('YmdHis'));

            try {
                $classification = $this->classifier->classify($message);
            } catch (\Throwable $e) {
                ++$failed;
                $table[] = [$id, 'FAIL', 'classify: '.$e->getMessage()];
                continue;
            }

            $topic = (string) ($classification['topic'] ?? '');
            if (!SelfAwareEvalAssertions::topicMatches($topic, $expect)) {
                ++$failed;
                $table[] = [$id, 'FAIL', sprintf('topic=%s expected=%s', $topic, (string) ($expect['topic'] ?? ''))];
                continue;
            }

            if ($routingOnly || str_starts_with($id, 'W') || str_starts_with($id, 'N')) {
                ++$passed;
                $table[] = [$id, 'PASS', 'topic='.$topic];
                continue;
            }

            try {
                $response = $this->chatHandler->handle($message, [], $classification, null, []);
                $answer = is_string($response['content'] ?? null) ? $response['content'] : '';
            } catch (\Throwable $e) {
                ++$failed;
                $table[] = [$id, 'FAIL', 'chat: '.$e->getMessage()];
                continue;
            }

            $answerFails = SelfAwareEvalAssertions::answerFailures($answer, $expect);
            if ([] !== $answerFails) {
                ++$failed;
                $table[] = [$id, 'FAIL', implode('; ', $answerFails)];
                continue;
            }

            ++$passed;
            $table[] = [$id, 'PASS', 'topic='.$topic];
        }

        if ([] === $rows) {
            ++$skipped;
        }

        if ('json' === $input->getOption('report')) {
            $io->writeln(json_encode([
                'passed' => $passed,
                'failed' => $failed,
                'skipped' => $skipped,
                'rows' => $table,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $io->table(['Id', 'Result', 'Detail'], $table);
            $io->writeln(sprintf('passed=%d failed=%d skipped=%d', $passed, $failed, $skipped));
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
