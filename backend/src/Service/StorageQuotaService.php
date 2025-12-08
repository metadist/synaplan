<?php

namespace App\Service;

use App\Entity\Config;
use App\Entity\File;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\FileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Storage Quota Service.
 *
 * Manages user storage limits based on subscription level
 */
class StorageQuotaService
{
    public function __construct(
        private FileRepository $fileRepository,
        private ConfigRepository $configRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Get storage limit in bytes for a user.
     */
    public function getStorageLimit(User $user): int
    {
        $level = $user->getRateLimitLevel();

        // Get limit from config (in MB or GB depending on plan)
        $limitConfig = $this->configRepository->findOneBy([
            'ownerId' => 0,
            'group' => "RATELIMITS_{$level}",
            'setting' => 'STORAGE_GB',
        ]);

        if ($limitConfig) {
            // GB limit for paid plans
            return (int) $limitConfig->getValue() * 1024 * 1024 * 1024; // Convert GB to bytes
        }

        $limitConfig = $this->configRepository->findOneBy([
            'ownerId' => 0,
            'group' => "RATELIMITS_{$level}",
            'setting' => 'STORAGE_MB',
        ]);

        if ($limitConfig) {
            // MB limit for free plans
            return (int) $limitConfig->getValue() * 1024 * 1024; // Convert MB to bytes
        }

        // Default fallback: 100 MB
        return 100 * 1024 * 1024;
    }

    /**
     * Get current storage usage in bytes for a user.
     */
    public function getStorageUsage(User $user): int
    {
        $qb = $this->fileRepository->createQueryBuilder('f');
        $qb->select('SUM(f.fileSize) as total')
           ->where('f.userId = :userId')
           ->setParameter('userId', $user->getId());

        $result = $qb->getQuery()->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    /**
     * Get remaining storage in bytes.
     */
    public function getRemainingStorage(User $user): int
    {
        $limit = $this->getStorageLimit($user);
        $usage = $this->getStorageUsage($user);

        return max(0, $limit - $usage);
    }

    /**
     * Check if user has enough storage for a file.
     */
    public function hasStorageFor(User $user, int $fileSize): bool
    {
        $remaining = $this->getRemainingStorage($user);

        return $remaining >= $fileSize;
    }

    /**
     * Check if user can upload a file, throw exception if not.
     *
     * @throws \RuntimeException if storage limit exceeded
     */
    public function checkStorageLimit(User $user, int $fileSize): void
    {
        $limit = $this->getStorageLimit($user);
        $usage = $this->getStorageUsage($user);
        $remaining = $limit - $usage;

        if ($fileSize > $remaining) {
            $this->logger->warning('Storage limit exceeded', [
                'user_id' => $user->getId(),
                'user_level' => $user->getRateLimitLevel(),
                'limit' => $limit,
                'usage' => $usage,
                'remaining' => $remaining,
                'file_size' => $fileSize,
            ]);

            throw new \RuntimeException(sprintf('Storage limit exceeded. You have %s remaining, but the file is %s. Upgrade your plan for more storage.', $this->formatBytes($remaining), $this->formatBytes($fileSize)));
        }

        $this->logger->debug('Storage check passed', [
            'user_id' => $user->getId(),
            'user_level' => $user->getRateLimitLevel(),
            'usage' => $usage,
            'limit' => $limit,
            'remaining' => $remaining,
            'file_size' => $fileSize,
        ]);
    }

    /**
     * Get storage statistics for a user.
     *
     * @return array{
     *   limit: int,
     *   usage: int,
     *   remaining: int,
     *   percentage: float,
     *   limit_formatted: string,
     *   usage_formatted: string,
     *   remaining_formatted: string
     * }
     */
    public function getStorageStats(User $user): array
    {
        $limit = $this->getStorageLimit($user);
        $usage = $this->getStorageUsage($user);
        $remaining = max(0, $limit - $usage);
        $percentage = $limit > 0 ? ($usage / $limit) * 100 : 0;

        return [
            'limit' => $limit,
            'usage' => $usage,
            'remaining' => $remaining,
            'percentage' => round($percentage, 2),
            'limit_formatted' => $this->formatBytes($limit),
            'usage_formatted' => $this->formatBytes($usage),
            'remaining_formatted' => $this->formatBytes($remaining),
        ];
    }

    /**
     * Format bytes to human-readable format.
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 2).' KB';
        }

        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2).' MB';
        }

        return round($bytes / (1024 * 1024 * 1024), 2).' GB';
    }
}
