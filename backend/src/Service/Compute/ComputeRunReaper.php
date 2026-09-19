<?php

declare(strict_types=1);

namespace App\Service\Compute;

use App\Entity\ComputeRun;
use App\Repository\ComputeRunRepository;
use Psr\Log\LoggerInterface;

/**
 * Closes run rows the worker will never finish (CS28) and purges finished
 * sidecar runs whose scratch can go.
 *
 * A stale open row is only closed when the sidecar answers: unknown (404) or
 * terminal means the result is lost to the user (`failed`, reason `lost`).
 * Transport errors leave the row alone for the next cycle. Finished rows
 * older than 10 minutes get their sidecar run deleted (artefacts were pulled
 * before the row went final). Never opens artefacts (C5).
 */
final readonly class ComputeRunReaper
{
    private const STALE_GRACE_SECONDS = 120;
    private const FINAL_PURGE_AFTER_SECONDS = 600;

    public function __construct(
        private ComputeConfig $config,
        private ComputeClient $client,
        private ComputeRunRepository $runs,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{closed: int, purged: int, skipped: int}
     */
    public function reap(): array
    {
        if (!$this->config->isEnabled()) {
            return ['closed' => 0, 'purged' => 0, 'skipped' => 0];
        }
        $now = new \DateTimeImmutable();
        $closed = 0;
        $purged = 0;
        $skipped = 0;

        $staleBefore = $now->modify(sprintf('-%d seconds', $this->config->maxTimeoutSec() + self::STALE_GRACE_SECONDS));
        foreach ($this->runs->findStaleOpenRuns($staleBefore) as $run) {
            try {
                if ($this->isLost($run)) {
                    $run->setReason('lost');
                    $run->markFinished(ComputeRun::STATUS_FAILED);
                    $this->runs->save($run);
                    $this->cancelQuietly($run->getRunId());
                    ++$closed;
                }
            } catch (\Throwable $e) {
                ++$skipped;
                $this->logger->warning('ComputeRunReaper: stale run kept for next cycle', [
                    'run_id' => $run->getRunId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $purgeBefore = $now->modify(sprintf('-%d seconds', self::FINAL_PURGE_AFTER_SECONDS));
        foreach ($this->runs->findFinalRunsBefore($purgeBefore) as $run) {
            try {
                $this->cancelQuietly($run->getRunId());
                ++$purged;
            } catch (\Throwable $e) {
                ++$skipped;
                $this->logger->warning('ComputeRunReaper: purge deferred to next cycle', [
                    'run_id' => $run->getRunId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['closed' => $closed, 'purged' => $purged, 'skipped' => $skipped];
    }

    private function isLost(ComputeRun $run): bool
    {
        try {
            $status = $this->client->status($run->getRunId());
        } catch (ComputeRefusedException $e) {
            if (404 === $e->getCode()) {
                return true;
            }

            throw $e;
        }

        return \in_array($status->status, [
            ComputeRun::STATUS_SUCCEEDED,
            ComputeRun::STATUS_FAILED,
            ComputeRun::STATUS_CANCELLED,
        ], true);
    }

    private function cancelQuietly(string $runId): void
    {
        try {
            $this->client->cancel($runId);
        } catch (ComputeRefusedException $e) {
            if (404 !== $e->getCode()) {
                throw $e;
            }
        }
    }
}
