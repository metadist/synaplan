<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\File;
use App\Repository\FileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * One lock per file so a delete and an in-flight index cannot pass each other.
 * A write that arrives after the deletion marker is dropped.
 */
final readonly class CloudFileIndexGuard
{
    public function __construct(
        private LockFactory $locks,
        private FileRepository $files,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function exclusive(int $fileId, callable $work): mixed
    {
        return $this->exclusiveAll([$fileId], $work);
    }

    /**
     * @param list<int> $fileIds
     */
    public function exclusiveAll(array $fileIds, callable $work): mixed
    {
        $ids = array_unique($fileIds);
        sort($ids);
        $locks = [];
        foreach ($ids as $id) {
            $lock = $this->locks->createLock('cloud-file-index:'.$id, 30);
            $lock->acquire(true);
            $locks[] = $lock;
        }
        try {
            return $work();
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }
    }

    public function allowsWrite(int $fileId): bool
    {
        $file = $this->withoutFilter(fn (): ?File => $this->files->find($fileId));

        return !$file instanceof File || null === $file->getSourceDeletedAt();
    }

    /**
     * @template T
     *
     * @param callable(): T $read
     *
     * @return T
     */
    private function withoutFilter(callable $read): mixed
    {
        $filters = $this->entityManager->getFilters();
        $wasOn = $filters->isEnabled('source_deleted');
        if ($wasOn) {
            $filters->disable('source_deleted');
        }
        try {
            return $read();
        } finally {
            if ($wasOn) {
                $filters->enable('source_deleted');
            }
        }
    }
}
