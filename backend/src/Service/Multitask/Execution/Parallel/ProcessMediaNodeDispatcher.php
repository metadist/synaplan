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
        $process = new Process(
            [
                'php',
                $this->consoleBinary,
                'app:multitask:run-media-node',
                '--user-id='.(string) ($request->userId ?? 0),
                '--capability='.$request->capability,
                '--prompt='.$request->prompt,
                '--language='.$request->language,
                '--params='.(json_encode($request->params) ?: '{}'),
            ],
            $this->projectDir,
        );

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
