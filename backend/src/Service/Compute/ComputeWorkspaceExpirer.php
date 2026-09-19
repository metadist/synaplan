<?php

declare(strict_types=1);

namespace App\Service\Compute;

use App\Repository\ComputeWorkspaceRepository;
use App\Repository\UserRepository;
use App\Service\InternalEmailService;
use Psr\Log\LoggerInterface;

/**
 * Expires idle file-work workspaces (CS29).
 *
 * Active workspaces idle past `WORKSPACE_TTL_DAYS` flip to `expiring` with a
 * 7-day grace and the owner is mailed once (the `expiring` state itself is
 * the once-guard: mail is best-effort and never retried into a loop). Past
 * the grace the sidecar folder is deleted and the row flips to `deleted`.
 */
final readonly class ComputeWorkspaceExpirer
{
    private const EXPIRY_GRACE_DAYS = 7;

    public function __construct(
        private ComputeConfig $config,
        private ComputeClient $client,
        private ComputeWorkspaceRepository $workspaces,
        private UserRepository $users,
        private InternalEmailService $mail,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{notified: int, deleted: int, skipped: int}
     */
    public function expire(): array
    {
        if (!$this->config->isEnabled()) {
            return ['notified' => 0, 'deleted' => 0, 'skipped' => 0];
        }
        $now = new \DateTimeImmutable();
        $notified = 0;
        $deleted = 0;
        $skipped = 0;

        $staleBefore = $now->modify(sprintf('-%d days', $this->config->workspaceTtlDays()));
        foreach ($this->workspaces->findStaleActiveWorkspaces($staleBefore) as $workspace) {
            try {
                $workspace->markExpiring($now->modify(sprintf('+%d days', self::EXPIRY_GRACE_DAYS)));
                $this->workspaces->save($workspace);
                $this->notifyOwner($workspace->getUserId());
                ++$notified;
            } catch (\Throwable $e) {
                ++$skipped;
                $this->logger->warning('ComputeWorkspaceExpirer: workspace kept for next cycle', [
                    'workspace_id' => $workspace->getWorkspaceId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($this->workspaces->findExpiredWorkspaces($now) as $workspace) {
            try {
                $this->deleteQuietly($workspace->getWorkspaceId());
                $workspace->markDeleted();
                $this->workspaces->save($workspace);
                ++$deleted;
            } catch (\Throwable $e) {
                ++$skipped;
                $this->logger->warning('ComputeWorkspaceExpirer: deletion deferred to next cycle', [
                    'workspace_id' => $workspace->getWorkspaceId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['notified' => $notified, 'deleted' => $deleted, 'skipped' => $skipped];
    }

    private function notifyOwner(int $userId): void
    {
        $user = $this->users->find($userId);
        if (null === $user) {
            return;
        }
        $address = trim($user->getMail());
        if ('' === $address || str_ends_with(strtolower($address), '@synaplan.local')) {
            return;
        }
        $frontendUrl = $_ENV['FRONTEND_URL'] ?? $_ENV['APP_URL'] ?? 'http://localhost:5173';
        try {
            $this->mail->sendWorkspaceExpiryEmail(
                $address,
                $user->getLocale(),
                self::EXPIRY_GRACE_DAYS,
                rtrim($frontendUrl, '/').'/files/workspace'
            );
        } catch (\Throwable $e) {
            $this->logger->warning('ComputeWorkspaceExpirer: expiry mail failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function deleteQuietly(string $workspaceId): void
    {
        try {
            $this->client->deleteWorkspace($workspaceId);
        } catch (ComputeRefusedException $e) {
            if (404 !== $e->getCode()) {
                throw $e;
            }
        }
    }
}
