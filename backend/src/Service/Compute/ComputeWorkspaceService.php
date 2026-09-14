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

/**
 * Maps a Synaplan user to an opaque sidecar workspace id. PHP never stores a path.
 */
final readonly class ComputeWorkspaceService
{
    public function __construct(
        private ComputeConfig $config,
        private ComputeClient $client,
        private ComputeWorkspaceRepository $workspaces,
        private RateLimitService $rateLimits,
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

        return $this->workspaces->findActiveForUser($id);
    }

    public function ensure(User $user): ComputeWorkspace
    {
        $existing = $this->forUser($user);
        if ($existing instanceof ComputeWorkspace) {
            return $existing;
        }

        $userId = (int) $user->getId();
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
        $this->workspaces->save($row);

        return $row;
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

    public static function safeRelativePath(string $path, bool $fileRequired = false): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');
        if (str_contains($path, '..') || str_starts_with($path, '/')) {
            throw new ComputeRefusedException('bad_file_name', 'That folder path is not allowed.');
        }
        if ($fileRequired && '' === $path) {
            throw new ComputeRefusedException('bad_file_name', 'A file path is required.');
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    public function createPayloadNeverContainsPath(ComputeWorkspaceCreate $create): array
    {
        return ['owner' => $create->owner, 'quotaMb' => $create->quotaMb];
    }

    private function quotaMb(User $user): int
    {
        return $this->rateLimits->computeIntSetting($user, 'COMPUTE_WORKSPACE_MB', $this->defaultQuotaMb($user));
    }

    private function defaultQuotaMb(User $user): int
    {
        return match (strtoupper($user->getRateLimitLevel())) {
            'PRO' => 512,
            'TEAM' => 1024,
            'BUSINESS', 'ADMIN' => 2048,
            'ANONYMOUS' => 0,
            default => 256,
        };
    }
}
