<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Message;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\Parallel\ProcessMediaNodeJob;
use App\Service\Multitask\Execution\RunnerRegistry;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Internal: run ONE media-generation node in an isolated process.
 *
 * Used by {@see \App\Service\Multitask\Execution\Parallel\ProcessMediaNodeDispatcher}
 * to offload heavy media nodes (image/video/text2sound) so they run concurrently
 * with inline text streaming. Reuses the exact same capability runners as the
 * in-process executor (no duplicated generation logic), and prints the result on
 * a single marker-prefixed line for the parent to parse.
 *
 * Not for manual use; takes a pre-resolved prompt (the parent resolves inputs).
 */
#[AsCommand(
    name: 'app:multitask:run-media-node',
    description: 'Internal: run a single multi-task media node (used for parallel offload)',
)]
final class RunMediaNodeCommand extends Command
{
    public function __construct(private readonly RunnerRegistry $registry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user-id', null, InputOption::VALUE_REQUIRED, 'Owner user id', '0')
            ->addOption('capability', null, InputOption::VALUE_REQUIRED, 'image_generation|video_generation|text2sound')
            ->addOption('prompt', null, InputOption::VALUE_REQUIRED, 'Resolved prompt/text')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Language code', 'en')
            ->addOption('params', null, InputOption::VALUE_REQUIRED, 'JSON params', '{}');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $userId = (int) $input->getOption('user-id');
        $capabilityValue = (string) $input->getOption('capability');
        $prompt = (string) $input->getOption('prompt');
        $language = (string) $input->getOption('language') ?: 'en';
        $params = json_decode((string) $input->getOption('params'), true);
        if (!is_array($params)) {
            $params = [];
        }

        $capability = Capability::tryFrom($capabilityValue);
        if (null === $capability) {
            return $this->emit($output, ['ok' => false, 'error' => "unknown capability '{$capabilityValue}'"]);
        }

        $runner = $this->registry->get($capability);
        if (null === $runner) {
            return $this->emit($output, ['ok' => false, 'error' => "no runner for '{$capabilityValue}'"]);
        }

        $message = new Message();
        $message->setUserId($userId);
        $message->setText($prompt);
        $message->setLanguage($language);
        $message->setDirection('IN');
        $message->setFile(0);

        $context = new NodeContext($message, [], $userId > 0 ? $userId : null, ['language' => $language], []);
        // Provide the prompt under both keys so image (prompt) and tts (text) runners pick it up.
        $node = new TaskNode('n1', $capability, [], ['prompt' => $prompt, 'text' => $prompt], $params);

        try {
            $result = $runner->run($node, $context);
        } catch (\Throwable $e) {
            return $this->emit($output, ['ok' => false, 'error' => $e->getMessage()]);
        }

        if (!$result->isSuccessful()) {
            return $this->emit($output, ['ok' => false, 'error' => $result->error ?? 'media node failed']);
        }

        return $this->emit($output, [
            'ok' => true,
            'text' => $result->text,
            'files' => $result->files,
            'metadata' => $result->metadata,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function emit(OutputInterface $output, array $payload): int
    {
        $output->writeln(ProcessMediaNodeJob::RESULT_MARKER.(json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '{"ok":false,"error":"encode failed"}'));

        return Command::SUCCESS;
    }
}
