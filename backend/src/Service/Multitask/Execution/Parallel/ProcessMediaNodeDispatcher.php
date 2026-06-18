<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Parallel;

use App\Service\Multitask\Execution\NodeResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Real dispatcher: runs each media node as an isolated `bin/console
 * app:multitask:run-media-node` subprocess (Symfony Process). Clean process per
 * node (own DB/HTTP connections) — safe under FrankenPHP, unlike pcntl forking.
 *
 * The request is serialised as a JSON payload on the child's STDIN (not argv):
 * prompts and thread snapshots can exceed argv limits and would otherwise be
 * visible to every local user via `ps`.
 *
 * dispatch() starts the process and returns immediately, so the caller can run
 * inline work (streaming text) while media generates in the background.
 */
final readonly class ProcessMediaNodeDispatcher implements MediaNodeDispatcher
{
    public function __construct(
        private string $projectDir,
        private LoggerInterface $logger,
        private string $consoleBinary = 'bin/console',
    ) {
    }

    public function dispatch(MediaNodeRequest $request): MediaNodeJob
    {
        $payload = json_encode([
            'node_id' => $request->nodeId,
            'capability' => $request->capability,
            'prompt' => $request->prompt,
            'user_id' => $request->userId ?? 0,
            'language' => $request->language,
            'params' => $request->params,
            'message_id' => $request->messageId,
            'thread' => $request->thread,
            'options' => $request->options,
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        if (false === $payload) {
            return new SettledMediaNodeJob(NodeResult::failed('could not encode media node payload'));
        }

        $process = new Process(
            ['php', $this->consoleBinary, 'app:multitask:run-media-node', '--stdin'],
            $this->projectDir,
        );
        $process->setInput($payload);

        try {
            $process->start();
        } catch (\Throwable $e) {
            $this->logger->warning('ProcessMediaNodeDispatcher: failed to start media subprocess', [
                'node' => $request->nodeId,
                'error' => $e->getMessage(),
            ]);

            return new SettledMediaNodeJob(NodeResult::failed('could not start media node: '.$e->getMessage()));
        }

        return new ProcessMediaNodeJob($process, $request->nodeId, $this->logger);
    }
}
