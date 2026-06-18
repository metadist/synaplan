<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Parallel;

use App\Service\Multitask\Execution\NodeResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Collects the result of a media node running in a Symfony Process. Never throws:
 * timeout, crash, or unparseable output all degrade to {@see NodeResult::failed}
 * so the executor can isolate the node.
 *
 * The subprocess prints its result on a single line prefixed with
 * {@see RESULT_MARKER}; everything else (logs) is ignored.
 */
final class ProcessMediaNodeJob implements MediaNodeJob
{
    public const RESULT_MARKER = 'MEDIA_NODE_RESULT:';

    public function __construct(
        private readonly Process $process,
        private readonly string $nodeId,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function cancel(): void
    {
        try {
            if ($this->process->isRunning()) {
                $this->process->stop(0);
                $this->logger->info('ProcessMediaNodeJob: media node cancelled', ['node' => $this->nodeId]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('ProcessMediaNodeJob: cancel failed', [
                'node' => $this->nodeId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function wait(int $timeoutSeconds): NodeResult
    {
        try {
            $this->process->setTimeout($timeoutSeconds);
            $this->process->wait();
        } catch (ProcessTimedOutException) {
            $this->process->stop(0);
            $this->logger->warning('ProcessMediaNodeJob: media node timed out', ['node' => $this->nodeId]);

            return NodeResult::failed('media node timed out');
        } catch (\Throwable $e) {
            return NodeResult::failed('media node process error: '.$e->getMessage());
        }

        if (!$this->process->isSuccessful()) {
            $this->logger->warning('ProcessMediaNodeJob: media node exited non-zero', [
                'node' => $this->nodeId,
                'exit' => $this->process->getExitCode(),
                'stderr' => substr($this->process->getErrorOutput(), -500),
            ]);
        }

        return $this->parse($this->process->getOutput());
    }

    private function parse(string $output): NodeResult
    {
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (!str_starts_with($line, self::RESULT_MARKER)) {
                continue;
            }

            $decoded = json_decode(substr($line, \strlen(self::RESULT_MARKER)), true);
            if (!is_array($decoded)) {
                break;
            }

            if (true === ($decoded['ok'] ?? false)) {
                /** @var list<array<string, mixed>> $files */
                $files = is_array($decoded['files'] ?? null) ? $decoded['files'] : [];

                return NodeResult::ok(
                    is_string($decoded['text'] ?? null) ? $decoded['text'] : null,
                    $files,
                    is_array($decoded['metadata'] ?? null) ? $decoded['metadata'] : [],
                );
            }

            return NodeResult::failed(is_string($decoded['error'] ?? null) ? $decoded['error'] : 'media node failed');
        }

        return NodeResult::failed('media node produced no parseable result');
    }
}
