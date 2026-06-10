<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Message;
use App\Repository\MessageRepository;
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
 * The node description arrives as a JSON payload on STDIN (`--stdin`, written
 * by the dispatcher via Process::setInput()) — never as argv, where prompts
 * would leak into `ps` and could exceed argv limits. `--payload` exists for
 * tests/manual debugging only.
 *
 * When the payload carries a `message_id`, the REAL inbound message is loaded
 * from the database so the node context matches the inline execution path —
 * including file attachments (pic2pic reference images), thread snapshot and
 * processing options. Without it (or when the row is gone) the command falls
 * back to a synthetic message carrying just the resolved prompt.
 *
 * Not for manual use; takes a pre-resolved prompt (the parent resolves inputs).
 */
#[AsCommand(
    name: 'app:multitask:run-media-node',
    description: 'Internal: run a single multi-task media node (used for parallel offload)',
)]
final class RunMediaNodeCommand extends Command
{
    public function __construct(
        private readonly RunnerRegistry $registry,
        private readonly MessageRepository $messages,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('stdin', null, InputOption::VALUE_NONE, 'Read the JSON node payload from STDIN (used by the dispatcher)')
            ->addOption('payload', null, InputOption::VALUE_REQUIRED, 'JSON node payload (tests/debugging alternative to --stdin)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $payload = $this->readPayload($input);
        if (null === $payload) {
            return $this->emit($output, ['ok' => false, 'error' => 'missing or invalid JSON payload (--stdin or --payload)']);
        }

        $userId = (int) ($payload['user_id'] ?? 0);
        $capabilityValue = (string) ($payload['capability'] ?? '');
        $prompt = (string) ($payload['prompt'] ?? '');
        $language = '' !== (string) ($payload['language'] ?? '') ? (string) $payload['language'] : 'en';
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];
        $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];
        $thread = $this->sanitizeThread($payload['thread'] ?? null);
        $messageId = is_numeric($payload['message_id'] ?? null) ? (int) $payload['message_id'] : null;

        $capability = Capability::tryFrom($capabilityValue);
        if (null === $capability) {
            return $this->emit($output, ['ok' => false, 'error' => "unknown capability '{$capabilityValue}'"]);
        }

        $runner = $this->registry->get($capability);
        if (null === $runner) {
            return $this->emit($output, ['ok' => false, 'error' => "no runner for '{$capabilityValue}'"]);
        }

        $message = $this->resolveMessage($messageId, $userId, $prompt, $language);

        $context = new NodeContext($message, $thread, $userId > 0 ? $userId : null, ['language' => $language], $options);
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
     * @return array<string, mixed>|null
     */
    private function readPayload(InputInterface $input): ?array
    {
        if (true === $input->getOption('stdin')) {
            // The dispatcher writes the payload via Process::setInput() and
            // closes the pipe, so this blocking read terminates at EOF.
            $raw = \defined('STDIN') && \is_resource(\STDIN) ? stream_get_contents(\STDIN) : false;
            $raw = false === $raw ? '' : $raw;
        } else {
            $option = $input->getOption('payload');
            $raw = is_string($option) ? $option : '';
        }

        if ('' === trim($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Load the real inbound message (carries the file attachments needed by
     * pic2pic) — falling back to a synthetic prompt-only message when the
     * payload has no id or the row no longer exists.
     */
    private function resolveMessage(?int $messageId, int $userId, string $prompt, string $language): Message
    {
        if (null !== $messageId && $messageId > 0) {
            $real = $this->messages->find($messageId);
            if ($real instanceof Message) {
                return $real;
            }
        }

        $message = new Message();
        $message->setUserId($userId);
        $message->setText($prompt);
        $message->setLanguage($language);
        $message->setDirection('IN');
        $message->setFile(0);

        return $message;
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function sanitizeThread(mixed $thread): array
    {
        if (!is_array($thread)) {
            return [];
        }

        $out = [];
        foreach ($thread as $entry) {
            if (is_array($entry) && is_string($entry['role'] ?? null) && is_string($entry['content'] ?? null)) {
                $out[] = ['role' => $entry['role'], 'content' => $entry['content']];
            }
        }

        return $out;
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
