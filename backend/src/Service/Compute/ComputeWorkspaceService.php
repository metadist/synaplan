<?php

declare(strict_types=1);

namespace App\Service\Compute;

use App\Entity\ComputeWorkspace;
use App\Entity\User;
use App\Repository\ComputeWorkspaceRepository;
use App\Service\Compute\Contract\ComputeWorkspaceCreate;
use App\Service\Compute\Contract\ComputeWorkspaceFile;
use App\Service\Compute\Contract\ComputeWorkspaceUsage;
use App\Service\RateLimitService;
use Symfony\Component\Lock\LockFactory;

/**
 * Maps a Synaplan user to an opaque sidecar workspace id. PHP never stores a path.
 */
final readonly class ComputeWorkspaceService
{
    private const MAX_PATH_LENGTH = 1024;
    /** Longer than one sidecar create round-trip; auto-expires if a worker dies mid-create. */
    private const CREATE_LOCK_TTL_SECONDS = 30.0;

    public function __construct(
        private ComputeConfig $config,
        private ComputeClient $client,
        private ComputeWorkspaceRepository $workspaces,
        private RateLimitService $rateLimits,
        private LockFactory $lockFactory,
    ) {
    }

    public static function ownerString(int $userId): string
    {
        return 'user:'.$userId;
    }

    public function forUser(User $user): ?ComputeWorkspace
    {
        $id = (int) $user->getId();
        if ($id <= 0) {
            return null;
        }

        return $this->activeRow($id);
    }

    public function ensure(User $user): ComputeWorkspace
    {
        $existing = $this->forUser($user);
        if ($existing instanceof ComputeWorkspace) {
            return $existing;
        }

        // Two first runs racing here would both create a sidecar folder while
        // only one row per user fits the unique key — the loser's folder would
        // be orphaned on disk. Serialise creation per user (cluster-wide via
        // LOCK_DSN); the second caller finds the row the first one saved.
        $userId = (int) $user->getId();
        $lock = $this->lockFactory->createLock('compute-workspace-create.'.$userId, self::CREATE_LOCK_TTL_SECONDS);
        $lock->acquire(true);
        try {
            $existing = $this->activeRow($userId);

            return $existing instanceof ComputeWorkspace ? $existing : $this->create($user, $userId);
        } finally {
            $lock->release();
        }
    }

    private function create(User $user, int $userId): ComputeWorkspace
    {
        $quotaMb = $this->quotaMb($user);
        if ($quotaMb <= 0) {
            throw new ComputeRefusedException('workspace_quota_exceeded', 'This account cannot keep a file-work folder.');
        }

        $created = $this->client->createWorkspace(new ComputeWorkspaceCreate(
            self::ownerString($userId),
            $quotaMb,
        ));
        if ('' === $created->workspaceId) {
            throw new ComputeRefusedException('invalid_json', 'Workspace id missing from sidecar response');
        }

        $ttl = $this->config->workspaceTtlDays();
        $row = new ComputeWorkspace(
            $userId,
            $created->workspaceId,
            $created->quotaMb > 0 ? $created->quotaMb : $quotaMb,
            (new \DateTimeImmutable())->modify('+'.$ttl.' days'),
        );
        try {
            $this->workspaces->save($row);
        } catch (\Throwable $e) {
            try {
                $this->client->deleteWorkspace($created->workspaceId);
            } catch (\Throwable) {
            }

            throw $e;
        }

        return $row;
    }

    /**
     * Active, unexpired row — or null after a successful expire/delete.
     * An expired folder that a run is still using stays visible until idle.
     */
    private function activeRow(int $userId): ?ComputeWorkspace
    {
        $row = $this->workspaces->findActiveForUser($userId);
        if (!$row instanceof ComputeWorkspace) {
            return null;
        }
        if (!$row->isExpired()) {
            return $row;
        }

        return $this->forgetIfIdle($row) ? null : $row;
    }

    /**
     * Drops an expired mapping and its sidecar folder. Returns false when a
     * run still holds the folder (409 workspace_busy) so the caller can keep
     * serving it until that run finishes.
     */
    private function forgetIfIdle(ComputeWorkspace $row): bool
    {
        try {
            $this->client->deleteWorkspace($row->getWorkspaceId());
        } catch (ComputeRefusedException $e) {
            if ('workspace_not_found' === $e->errorCode()) {
                $this->workspaces->remove($row);

                return true;
            }

            // Busy, or any other sidecar refusal: keep serving until we can
            // drop the folder without a 500 on the Files tab.
            return false;
        } catch (\Throwable) {
            return false;
        }
        $this->workspaces->remove($row);

        return true;
    }

    public function refreshUsage(ComputeWorkspace $workspace): ComputeWorkspaceUsage
    {
        $usage = $this->client->workspaceUsage($workspace->getWorkspaceId());
        $lastUsed = '' !== $usage->lastUsedAt
            ? new \DateTimeImmutable($usage->lastUsedAt)
            : new \DateTimeImmutable();
        $workspace->applyUsage($usage->usedMb, $lastUsed);
        $this->workspaces->save($workspace);

        return $usage;
    }

    public function delete(User $user): void
    {
        $workspace = $this->forUser($user);
        if (!$workspace instanceof ComputeWorkspace) {
            return;
        }
        try {
            $this->client->deleteWorkspace($workspace->getWorkspaceId());
        } catch (ComputeRefusedException $e) {
            if ('workspace_busy' === $e->errorCode()) {
                throw new ComputeRefusedException('workspace_busy', 'A file-work run is still using this folder. Wait for it to finish, then delete it.', httpStatus: 409);
            }
            if ('workspace_not_found' !== $e->errorCode()) {
                throw $e;
            }
        }
        $this->workspaces->remove($workspace);
    }

    /**
     * @return list<ComputeWorkspaceFile>
     */
    public function listFiles(User $user, string $path = ''): array
    {
        $workspace = $this->forUser($user);
        if (!$workspace instanceof ComputeWorkspace) {
            return [];
        }

        return $this->client->listWorkspaceFiles($workspace->getWorkspaceId(), self::safeRelativePath($path));
    }

    /**
     * @return array{contents: string, mime: string, name: string}
     */
    public function downloadFile(User $user, string $path): array
    {
        $workspace = $this->forUser($user);
        if (!$workspace instanceof ComputeWorkspace) {
            throw new ComputeRefusedException('workspace_not_found', 'There is no file-work folder yet.');
        }

        return $this->client->downloadWorkspaceFile($workspace->getWorkspaceId(), self::safeRelativePath($path, true));
    }

    /**
     * Normalises a user-supplied path inside the workspace before it reaches the
     * sidecar. The sidecar confines every path itself; this is the PHP-side
     * check that refuses anything that could only be an attack or a bug.
     */
    public static function safeRelativePath(string $path, bool $fileRequired = false): string
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');
        if (\strlen($path) > self::MAX_PATH_LENGTH
            || 1 === preg_match('/[\x00-\x1F\x7F]/', $path)
        ) {
            throw new ComputeRefusedException('bad_file_name', 'That folder path is not allowed.');
        }
        foreach (explode('/', $path) as $segment) {
            if ('.' === $segment || '..' === $segment) {
                throw new ComputeRefusedException('bad_file_name', 'That folder path is not allowed.');
            }
        }
        if ($fileRequired && '' === $path) {
            throw new ComputeRefusedException('bad_file_name', 'A file path is required.');
        }

        return $path;
    }

    private function quotaMb(User $user): int
    {
        return $this->rateLimits->computeIntSetting($user, 'COMPUTE_WORKSPACE_MB', $this->defaultQuotaMb($user));
    }

    private function defaultQuotaMb(User $user): int
    {
        return match (strtoupper($this->rateLimits->resolveRateLimitLevel($user))) {
            'PRO' => 512,
            'TEAM' => 1024,
            'BUSINESS', 'ADMIN' => 2048,
            'ANONYMOUS' => 0,
            default => 256,
        };
    }
}
